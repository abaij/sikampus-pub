<?php

namespace App\Livewire\Admin\Kelas;

use App\Livewire\Admin\Kelas\Concerns\ForwardsIndexState;
use App\Models\Dosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;
use App\Models\Prodi;
use App\Models\Semester;
use App\Services\KelasAngkatanService;
use App\Services\KelasKodeGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Form extends Component
{
    use ForwardsIndexState;

    public ?int $kelasId = null;

    // FK boleh ?int karena diikat lewat <x-searchable-select> (entangle), bukan <select> polos.
    // :live="true" di view supaya daftar kurikulum mata kuliah ikut dimuat ulang saat prodi berganti.
    public ?int $id_prodi = null;

    public ?int $id_kurikulum_matkul = null;

    public ?int $id_semester = null;

    public ?int $id_angkatan = null;

    public ?int $id_kelompok_kelas = null;

    public ?int $id_dosen_pic = null;

    public string $kode = '';

    // Terikat <input type="number">, bukan <select> — tetap string karena input kosong mengirim
    // "" yang tidak bisa dikonversi PHP ke int typed property (lihat SKILL.md).
    public string $jml_pertemuan = '16';

    public string $kuota = '0';

    public bool $is_mingguan = true;

    public bool $is_active = true;

    /** Pencarian dosen untuk ditambahkan sebagai tim pengampu (di luar PIC). */
    public string $dosenSearch = '';

    /** @var array<int> id dosen tim pengampu (tidak termasuk PIC), dikirim sebagai dosen_tim_ids. */
    public array $dosenTimIds = [];

    /** @var array<int, string> label tampilan untuk id dosen terpilih (PIC + tim). */
    public array $dosenLabelById = [];

    public function mount(?int $id = null): void
    {
        $this->kelasId = $id;
        $this->resolveBackUrl();

        if ($id === null) {
            return;
        }

        $kelas = Kelas::with(['kelasDosen' => function ($q) {
            $q->whereNull('deleted_at');
        }, 'kelasDosen.dosen'])->findOrFail($id);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $this->id_prodi = $kelas->id_prodi;
        $this->id_kurikulum_matkul = $kelas->id_kurikulum_matkul;
        $this->id_semester = $kelas->id_semester;
        $this->id_angkatan = $kelas->id_angkatan;
        $this->id_kelompok_kelas = $kelas->id_kelompok_kelas;
        $this->id_dosen_pic = $kelas->id_dosen_pic;
        $this->kode = (string) $kelas->kode;
        $this->jml_pertemuan = (string) ($kelas->jml_pertemuan ?? 16);
        $this->kuota = (string) ($kelas->kuota ?? 0);
        $this->is_mingguan = $kelas->is_mingguan !== false;
        $this->is_active = (bool) $kelas->is_active;

        foreach ($kelas->kelasDosen as $kd) {
            if (! $kd->dosen) {
                continue;
            }
            if ($kd->is_pic) {
                $this->dosenLabelById[$kd->id_dosen] = $this->formatDosenLabel($kd->dosen);

                continue;
            }
            $this->dosenTimIds[] = (int) $kd->id_dosen;
            $this->dosenLabelById[$kd->id_dosen] = $this->formatDosenLabel($kd->dosen);
        }
    }

    /**
     * Reset kurikulum mata kuliah saat prodi berganti — pilihan lama sudah tidak relevan.
     */
    public function updatedIdProdi(): void
    {
        $this->id_kurikulum_matkul = null;
    }

    /**
     * Isikan angkatan dari kelompok kelas yang dipilih. id_angkatan = semester masuk mahasiswa,
     * bukan semester berjalan — salah isi bikin kelas tidak pernah muncul di pengajuan KRS.
     */
    public function updatedIdKelompokKelas(): void
    {
        $saran = KelasAngkatanService::angkatanSaranForKelompokKelas($this->id_kelompok_kelas);
        if ($saran !== null) {
            $this->id_angkatan = $saran;
            $this->resetErrorBag('id_angkatan');
        }
    }

    private function formatDosenLabel(Dosen $dosen): string
    {
        $label = trim(($dosen->gelar_depan ? $dosen->gelar_depan.' ' : '').$dosen->nama.($dosen->gelar_belakang ? ', '.$dosen->gelar_belakang : ''));

        return $dosen->kode_dosen ? "{$label} ({$dosen->kode_dosen})" : $label;
    }

    #[Computed]
    public function kurikulumMatkulOptions()
    {
        if (! $this->id_prodi) {
            return collect();
        }

        return KurikulumMatkul::with(['matkul', 'kurikulum'])
            ->whereHas('kurikulum', function ($q) {
                $q->where('id_prodi', $this->id_prodi);
            })
            ->orderBy('id')
            ->get()
            ->map(fn (KurikulumMatkul $km) => (object) [
                'id' => $km->id,
                'label' => trim(($km->matkul?->kode ? "{$km->matkul->kode} - " : '').($km->matkul?->nama ?? 'Mata Kuliah').($km->kurikulum?->nama ? " ({$km->kurikulum->nama})" : '')),
            ]);
    }

    #[Computed]
    public function dosenSearchResults()
    {
        if ($this->dosenSearch === '') {
            return collect();
        }

        $excludedIds = $this->dosenTimIds;
        if ($this->id_dosen_pic) {
            $excludedIds[] = $this->id_dosen_pic;
        }

        return Dosen::query()
            ->where(function ($q) {
                $q->where('nama', 'like', "%{$this->dosenSearch}%")
                    ->orWhere('kode_dosen', 'like', "%{$this->dosenSearch}%");
            })
            ->whereNotIn('id', $excludedIds)
            ->orderBy('nama')
            ->limit(20)
            ->get();
    }

    public function addDosenTim(int $id): void
    {
        if (in_array($id, $this->dosenTimIds, true)) {
            return;
        }

        $dosen = Dosen::find($id);
        if (! $dosen) {
            return;
        }

        $this->dosenTimIds[] = $id;
        $this->dosenLabelById[$id] = $this->formatDosenLabel($dosen);
        $this->dosenSearch = '';
    }

    public function removeDosenTim(int $id): void
    {
        $this->dosenTimIds = array_values(array_diff($this->dosenTimIds, [$id]));
    }

    /**
     * Cek duplikat berdasarkan unique (id_kelompok_kelas, id_kurikulum_matkul, id_semester,
     * id_angkatan) — sama persis dengan KelasController::kelasDuplicateExists.
     */
    private function kelasDuplicateExists(): bool
    {
        $q = Kelas::query()
            ->where('id_kurikulum_matkul', $this->id_kurikulum_matkul)
            ->where('id_semester', $this->id_semester)
            ->where('id_angkatan', $this->id_angkatan);

        if ($this->id_kelompok_kelas) {
            $q->where('id_kelompok_kelas', $this->id_kelompok_kelas);
        } else {
            $q->whereNull('id_kelompok_kelas');
        }

        if ($this->kelasId) {
            $q->where('id', '!=', $this->kelasId);
        }

        return $q->exists();
    }

    /**
     * Sinkronisasi kelas_dosen: tim pengampu + dosen PIC (is_pic = true) — sama persis dengan
     * KelasController::syncKelasDosen.
     */
    private function syncKelasDosen(Kelas $kelas): void
    {
        $picId = $this->id_dosen_pic;
        $timIds = $this->dosenTimIds;

        $allIds = $timIds;
        if ($picId !== null && ! in_array($picId, $allIds, true)) {
            $allIds[] = $picId;
        }

        $rows = KelasDosen::withTrashed()->where('id_kelas', $kelas->id)->get();
        $byDosen = $rows->keyBy('id_dosen');

        foreach ($allIds as $dosenId) {
            $isPic = $picId !== null && $dosenId === $picId;
            $existing = $byDosen->get($dosenId);
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                if ((bool) $existing->is_pic !== $isPic) {
                    $existing->update(['is_pic' => $isPic]);
                }
            } else {
                KelasDosen::create([
                    'id_kelas' => $kelas->id,
                    'id_dosen' => $dosenId,
                    'is_pic' => $isPic,
                ]);
            }
        }

        foreach ($rows as $row) {
            if ($row->trashed()) {
                continue;
            }
            if (! in_array((int) $row->id_dosen, $allIds, true)) {
                $row->delete();
            }
        }
    }

    /**
     * Rule sama persis dengan KelasController::store/update.
     */
    protected function rules(): array
    {
        return [
            'id_kurikulum_matkul' => ['required', 'integer', 'exists:kurikulum_matkul,id'],
            'id_prodi' => ['required', 'integer', 'exists:prodi,id'],
            'id_semester' => ['required', 'integer', 'exists:semester,id'],
            'id_angkatan' => ['required', 'integer', 'exists:semester,id'],
            'id_dosen_pic' => ['nullable', 'integer', 'exists:dosen,id'],
            'id_kelompok_kelas' => ['nullable', 'integer', 'exists:kelompok_kelas,id'],
            'kode' => ['nullable', 'string', 'max:255'],
            'jml_pertemuan' => ['nullable', 'integer', 'min:1', 'max:99'],
            'kuota' => ['nullable', 'integer', 'min:0', 'max:32767'],
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        // Kode kosong dibuatkan sistem dari nama kelompok kelas (cadangan: kode mata kuliah).
        if ($validated['kode'] === '') {
            $validated['kode'] = KelasKodeGenerator::untukKelas(
                $validated['id_kelompok_kelas'] ?? null,
                $validated['id_kurikulum_matkul'] ?? null,
            );
        }
        $validated['jml_pertemuan'] = (int) $validated['jml_pertemuan'];
        $validated['kuota'] = (int) $validated['kuota'];
        $validated['is_mingguan'] = $this->is_mingguan;
        $validated['is_active'] = $this->is_active;

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $validated['id_prodi'], $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke program studi ini.');
            }
        }

        $pesanAngkatan = KelasAngkatanService::pesanKetidakcocokan(
            $validated['id_kelompok_kelas'] ?? null,
            $validated['id_angkatan'] ?? null,
        );
        if ($pesanAngkatan !== null) {
            $this->addError('id_angkatan', $pesanAngkatan);

            return;
        }

        if ($this->kelasDuplicateExists()) {
            $this->addError('id_kurikulum_matkul', 'Kelas dengan kombinasi kelompok, kurikulum mata kuliah, semester berjalan, dan angkatan yang sama sudah ada.');

            return;
        }

        DB::transaction(function () use ($validated): void {
            if ($this->kelasId) {
                $kelas = Kelas::findOrFail($this->kelasId);
                $kelas->update($validated);
                $kelas->refresh();
            } else {
                $kelas = Kelas::create($validated);
            }

            $this->syncKelasDosen($kelas);
        });

        session()->flash('status', 'Kelas berhasil disimpan.');

        return redirect($this->backUrl);
    }

    public function render()
    {
        $user = Auth::user();
        $prodiQuery = Prodi::with('jenjang')->whereNull('deleted_at');
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $prodiQuery->whereIn('id', $allowedProdiIds);
            }
        }

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.kelas.form', [
            'prodiOptions' => $prodiQuery->orderBy('nama')->get()->map(fn (Prodi $p) => (object) [
                'id' => $p->id,
                'label' => $p->jenjang?->kode ? "{$p->nama} ({$p->jenjang->kode})" : $p->nama,
            ]),
            'semesterOptions' => Semester::whereNull('deleted_at')->orderByDesc('kode')->get(['id', 'kode', 'nama'])
                ->map(fn (Semester $s) => (object) ['id' => $s->id, 'label' => "{$s->nama} ({$s->kode})"]),
            'kelompokKelasOptions' => KelompokKelas::whereNull('deleted_at')->orderBy('nama')->get(['id', 'nama']),
            'dosenOptions' => Dosen::whereNull('deleted_at')->orderBy('nama')->get()->map(fn (Dosen $d) => (object) [
                'id' => $d->id,
                'label' => $this->formatDosenLabel($d),
            ]),
        ])->extends('layouts.web');
    }
}
