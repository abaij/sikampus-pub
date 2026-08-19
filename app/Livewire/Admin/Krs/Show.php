<?php

namespace App\Livewire\Admin\Krs;

use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Semester;
use App\Services\UrutanMatkulService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    public int $mahasiswaId;

    public string $filterSemester = '';

    public ?int $confirmDeleteId = null;

    public function mount(int $id): void
    {
        $this->mahasiswaId = $id;

        $mahasiswa = Mahasiswa::findOrFail($id);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data KRS mahasiswa ini.');
            }
        }
    }

    #[Computed]
    public function mahasiswa()
    {
        return Mahasiswa::with(['prodi', 'semester_masuk'])->findOrFail($this->mahasiswaId);
    }

    /**
     * Sama persis dengan KrsController::show — dosen wali diambil lewat raw query, bukan relasi.
     */
    #[Computed]
    public function dosenWali(): string
    {
        $row = DB::table('dosen_wali')
            ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
            ->where('dosen_wali.id_mahasiswa', $this->mahasiswaId)
            ->where('dosen_wali.status', 'active')
            ->whereNull('dosen_wali.deleted_at')
            ->select('dosen.nama as dosen_nama')
            ->first();

        return $row ? $row->dosen_nama : '—';
    }

    /**
     * Array asosiatif id => label (bukan Collection model) supaya cocok dipakai langsung oleh
     * <x-searchable-select> — sama seperti App\Livewire\Admin\Krs\Index::semesterOptions.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function semesterOptions(): array
    {
        return Semester::orderByDesc('kode')
            ->get(['id', 'kode', 'nama'])
            ->mapWithKeys(fn ($semester) => [$semester->id => "{$semester->nama} ({$semester->kode})"])
            ->all();
    }

    /**
     * Sama persis dengan KrsController::show.
     */
    #[Computed]
    public function krsList()
    {
        $query = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $this->mahasiswaId)
            ->whereNull('deleted_at');

        if ($this->filterSemester !== '') {
            $semesterId = (int) $this->filterSemester;
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        // Diurutkan berdasarkan nama mata kuliah; created_at tetap jadi tie-breaker karena
        // sortBy di PHP 8 stabil.
        return UrutanMatkulService::urutkanKrs($query->orderByDesc('created_at')->get());
    }

    #[Computed]
    public function summary(): array
    {
        $totalSksDiajukan = 0;
        $totalSksDiacc = 0;

        foreach ($this->krsList as $krs) {
            $sks = $krs->kelas->kurikulumMatkul->matkul->sks ?? 0;
            $totalSksDiajukan += $sks;
            if ($krs->approved_at) {
                $totalSksDiacc += $sks;
            }
        }

        return [
            'total_krs' => $this->krsList->count(),
            'sks_diajukan' => $totalSksDiajukan,
            'sks_diacc' => $totalSksDiacc,
        ];
    }

    public function updatingFilterSemester(): void
    {
        unset($this->krsList, $this->summary);
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    /**
     * Sama persis dengan KrsController::destroy. Scope sudah dijamin lewat mount() (mahasiswa di
     * halaman ini sudah dicek), dan where id_mahasiswa di bawah memastikan id yang dikirim dari
     * client benar-benar milik mahasiswa tsb — bukan sekadar disembunyikan dari tampilan.
     */
    public function delete(): void
    {
        if (! $this->confirmDeleteId) {
            return;
        }

        Krs::where('id', $this->confirmDeleteId)
            ->where('id_mahasiswa', $this->mahasiswaId)
            ->firstOrFail()
            ->delete();

        $this->confirmDeleteId = null;
        unset($this->krsList, $this->summary);
    }

    public function render()
    {
        return view('livewire.admin.krs.show')->extends('layouts.web');
    }
}
