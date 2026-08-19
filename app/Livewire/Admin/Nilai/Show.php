<?php

namespace App\Livewire\Admin\Nilai;

use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\NilaiRevisi;
use App\Models\Semester;
use App\Services\SemesterService;
use App\Services\UrutanMatkulService;
use App\Support\PanelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Show extends Component
{
    public int $mahasiswaId;

    public string $search = '';

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
                abort(403, 'Anda tidak memiliki akses ke data nilai mahasiswa ini.');
            }
        }
    }

    public function updatingSearch(): void
    {
        unset($this->krsList, $this->statistik, $this->semesterDitempuh);
    }

    public function updatingFilterSemester(): void
    {
        unset($this->krsList, $this->statistik, $this->semesterDitempuh);
    }

    #[Computed]
    public function mahasiswa()
    {
        return Mahasiswa::with(['prodi', 'semester_masuk'])->findOrFail($this->mahasiswaId);
    }

    /**
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
     * Sama persis dengan NilaiController::show.
     */
    #[Computed]
    public function krsList()
    {
        $query = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
        ])
            ->where('id_mahasiswa', $this->mahasiswaId)
            ->whereNull('deleted_at');

        if ($this->filterSemester !== '') {
            $semesterId = (int) $this->filterSemester;
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        if ($this->search !== '') {
            $s = $this->search;
            $query->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($s) {
                $q->where('nama', 'like', "%{$s}%")
                    ->orWhere('kode', 'like', "%{$s}%");
            });
        }

        // Diurutkan berdasarkan nama mata kuliah; created_at tetap jadi tie-breaker karena
        // sortBy di PHP 8 stabil.
        $krsList = UrutanMatkulService::urutkanKrs($query->orderByDesc('created_at')->get());

        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = empty($krsIds)
            ? collect()
            : Nilai::whereIn('id_krs', $krsIds)->whereNull('deleted_at')->get()->keyBy('id_krs');

        foreach ($krsList as $krs) {
            $krs->setRelation('nilai', $nilaiMap->get($krs->id));
        }

        return $krsList;
    }

    /**
     * Sama persis dengan perhitungan statistik di halaman detail nilai frontend: hanya nilai
     * final yang dihitung ke IPK.
     */
    #[Computed]
    public function statistik(): array
    {
        $totalSks = 0;
        $totalAngkaMutu = 0.0;
        $totalSksDenganNilai = 0;

        foreach ($this->krsList as $krs) {
            $sks = $krs->kelas->kurikulumMatkul->matkul->sks ?? 0;
            $totalSks += $sks;

            $nilai = $krs->nilai;
            if ($nilai && $nilai->is_final && $nilai->angka_mutu !== null) {
                $totalAngkaMutu += (float) $nilai->angka_mutu * $sks;
                $totalSksDenganNilai += $sks;
            }
        }

        return [
            'total_sks' => $totalSks,
            'total_angka_mutu' => $totalAngkaMutu,
            'total_sks_dengan_nilai' => $totalSksDenganNilai,
            'ipk' => $totalSksDenganNilai > 0 ? round($totalAngkaMutu / $totalSksDenganNilai, 2) : null,
        ];
    }

    #[Computed]
    public function semesterDitempuh(): ?int
    {
        $mahasiswa = $this->mahasiswa;
        if (! $mahasiswa->semester_masuk) {
            return null;
        }

        $target = $this->filterSemester !== ''
            ? Semester::find((int) $this->filterSemester)
            : Semester::where('is_active', true)->first();

        if (! $target) {
            return null;
        }

        return SemesterService::hitungSemesterDitempuhFromModel($mahasiswa->semester_masuk, $target);
    }

    public function confirmDelete(int $nilaiId): void
    {
        // Tombol pemicu ini disembunyikan di Blade untuk user tanpa hak hapus, tapi method
        // Livewire tetap bisa dipanggil langsung lewat request yang dipalsukan — pengecekan di
        // sini dan di delete() adalah otoritas sebenarnya, bukan sekadar UI.
        abort_unless(PanelAccess::can(Auth::user(), 'nilai', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus nilai.');

        $this->confirmDeleteId = $nilaiId;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    /**
     * Sama persis dengan NilaiController::destroy — soft delete nilai + komponen + revisi terkait
     * id_krs yang sama. Scope sudah dijamin lewat mount() (mahasiswa halaman ini sudah dicek).
     */
    public function delete(): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'nilai', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus nilai.');

        if (! $this->confirmDeleteId) {
            return;
        }

        $user = Auth::user();

        $nilai = Nilai::with('krs')->findOrFail($this->confirmDeleteId);

        if (! $nilai->krs || (int) $nilai->krs->id_mahasiswa !== $this->mahasiswaId) {
            abort(404);
        }

        $idKrs = (int) $nilai->id_krs;
        $deletedBy = $user ? ($user->name ?? (string) ($user->email ?? $user->id)) : 'system';

        DB::transaction(function () use ($nilai, $idKrs, $deletedBy): void {
            DB::table('nilai_komponen')
                ->where('id_krs', $idKrs)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'deleted_by' => $deletedBy,
                    'updated_at' => now(),
                ]);

            NilaiRevisi::where('id_krs', $idKrs)->whereNull('deleted_at')->delete();

            $nilai->deleted_by = $deletedBy;
            $nilai->save();
            $nilai->delete();
        });

        $this->confirmDeleteId = null;
        unset($this->krsList, $this->statistik);

        session()->flash('status', 'Nilai berhasil dihapus.');
    }

    public function render()
    {
        return view('livewire.admin.nilai.show')->extends('layouts.web');
    }
}
