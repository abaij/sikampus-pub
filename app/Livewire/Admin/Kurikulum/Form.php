<?php

namespace App\Livewire\Admin\Kurikulum;

use App\Livewire\Admin\Kurikulum\Concerns\ForwardsIndexState;
use App\Models\Kurikulum;
use App\Models\Matkul;
use App\Models\Prodi;
use App\Models\Semester;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    use ForwardsIndexState;

    public ?int $kurikulumId = null;

    // FK boleh ?int karena diikat lewat <x-searchable-select> (entangle), bukan <select> polos.
    // :live="true" di view supaya daftar mata kuliah ikut dimuat ulang saat prodi berganti.
    public ?int $id_prodi = null;

    public string $kode = '';

    public string $nama = '';

    public string $deskripsi = '';

    // Terikat <input type="number">, bukan <select> — tetap string (bukan ?int) karena input
    // kosong mengirim "" yang tidak bisa dikonversi PHP ke int typed property. Lihat SKILL.md.
    public string $sks_wajib_minimal = '';

    public ?int $id_tahun_berlaku = null;

    public string $status = 'active';

    /** @var array<int> id mata kuliah yang dicentang, diikat wire:model.live ke checkbox group. */
    public array $selectedMatkulIds = [];

    /** @var array<int, array{semester_rekomendasi: string, is_wajib: bool}> */
    public array $matkulDetail = [];

    public function mount(?int $id = null): void
    {
        $this->kurikulumId = $id;
        $this->resolveBackUrl();

        if ($id === null) {
            return;
        }

        $kurikulum = Kurikulum::with('matkuls')->findOrFail($id);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kurikulum->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kurikulum ini.');
            }
        }

        $this->id_prodi = $kurikulum->id_prodi;
        $this->kode = $kurikulum->kode;
        $this->nama = $kurikulum->nama;
        $this->deskripsi = (string) $kurikulum->deskripsi;
        $this->sks_wajib_minimal = $kurikulum->sks_wajib_minimal !== null ? (string) $kurikulum->sks_wajib_minimal : '';
        $this->id_tahun_berlaku = $kurikulum->id_tahun_berlaku;
        $this->status = $kurikulum->status ?? 'active';

        foreach ($kurikulum->matkuls as $matkul) {
            $this->selectedMatkulIds[] = $matkul->id;
            $this->matkulDetail[$matkul->id] = [
                'semester_rekomendasi' => $matkul->pivot->semester_rekomendasi !== null ? (string) $matkul->pivot->semester_rekomendasi : '',
                'is_wajib' => (bool) $matkul->pivot->is_wajib,
            ];
        }
    }

    /**
     * Beri nilai default (semester bawaan mata kuliah, wajib) begitu mata kuliah baru dicentang —
     * meniru handleMatkulToggle di app/admin/kurikulum/new/page.tsx.
     */
    public function updatedSelectedMatkulIds(): void
    {
        foreach ($this->selectedMatkulIds as $matkulId) {
            if (! isset($this->matkulDetail[$matkulId])) {
                $matkul = Matkul::find($matkulId);
                $this->matkulDetail[$matkulId] = [
                    'semester_rekomendasi' => $matkul?->semester !== null ? (string) $matkul->semester : '',
                    'is_wajib' => true,
                ];
            }
        }
    }

    /**
     * Ganti prodi = daftar mata kuliah yang tersedia berubah total, jadi seleksi lama dibuang —
     * sama seperti reset selectedMatkuls saat form.id_prodi berubah di frontend.
     */
    public function updatedIdProdi(): void
    {
        $this->selectedMatkulIds = [];
        $this->matkulDetail = [];
    }

    /**
     * Centang/hapus centang semua mata kuliah yang tersedia untuk prodi terpilih sekaligus.
     * Query sama persis dengan yang dipakai render() untuk $matkulOptions supaya "semua" di
     * sini konsisten dengan daftar yang tampil di tabel.
     */
    public function toggleSelectAllMatkul(): void
    {
        if (! $this->id_prodi) {
            return;
        }

        $allIds = Matkul::where('id_prodi', $this->id_prodi)->whereNull('deleted_at')->pluck('id')->all();

        if (empty(array_diff($allIds, $this->selectedMatkulIds))) {
            $this->selectedMatkulIds = array_values(array_diff($this->selectedMatkulIds, $allIds));
        } else {
            $this->selectedMatkulIds = array_values(array_unique(array_merge($this->selectedMatkulIds, $allIds)));
            $this->updatedSelectedMatkulIds();
        }
    }

    /**
     * Rule sama persis dengan KurikulumController::store/update.
     */
    protected function rules(): array
    {
        $uniqueKode = Rule::unique('kurikulum', 'kode');

        if ($this->kurikulumId) {
            $uniqueKode = $uniqueKode->ignore($this->kurikulumId);
        }

        return [
            'id_prodi' => ['required', 'integer', 'exists:prodi,id'],
            'kode' => ['required', 'string', 'max:50', $uniqueKode],
            'nama' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'sks_wajib_minimal' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'id_tahun_berlaku' => ['required', 'integer', 'exists:semester,id'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        foreach (['deskripsi', 'sks_wajib_minimal'] as $field) {
            if ($validated[$field] === '') {
                $validated[$field] = null;
            }
        }
        if ($validated['sks_wajib_minimal'] !== null) {
            $validated['sks_wajib_minimal'] = (int) $validated['sks_wajib_minimal'];
        }
        $validated['status'] = $validated['status'] ?? 'active';

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $validated['id_prodi'], $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke program studi ini.');
            }
        }

        if ($this->kurikulumId) {
            $kurikulum = Kurikulum::findOrFail($this->kurikulumId);

            if ($user && $user->hasScopeRestriction()) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $kurikulum->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke kurikulum ini.');
                }
            }

            $kurikulum->update($validated);
        } else {
            $kurikulum = Kurikulum::create($validated);
        }

        // Sama persis dengan KurikulumController::store/update — snapshot kode/nama/SKS mata
        // kuliah disalin ke pivot supaya tampilan kurikulum tidak basi kalau master matkul berubah.
        $syncData = [];
        foreach ($this->selectedMatkulIds as $matkulId) {
            $matkul = Matkul::find($matkulId);
            if (! $matkul) {
                continue;
            }

            $detail = $this->matkulDetail[$matkulId] ?? ['semester_rekomendasi' => '', 'is_wajib' => true];

            $syncData[$matkulId] = [
                'kode_matkul' => $matkul->kode,
                'nama_matkul' => $matkul->nama,
                'nama_matkul_en' => $matkul->nama_en,
                'sks' => $matkul->sks,
                'semester_rekomendasi' => $detail['semester_rekomendasi'] !== '' ? (int) $detail['semester_rekomendasi'] : null,
                'is_wajib' => (bool) ($detail['is_wajib'] ?? true),
            ];
        }
        $kurikulum->matkuls()->sync($syncData);

        session()->flash('status', 'Kurikulum berhasil disimpan.');

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
        $prodiOptions = $prodiQuery->orderBy('nama')->get()->map(fn ($p) => (object) [
            'id' => $p->id,
            'label' => $p->jenjang?->kode ? "{$p->nama} ({$p->jenjang->kode})" : $p->nama,
        ]);

        $semesterOptions = Semester::whereNull('deleted_at')->orderByDesc('kode')->get(['id', 'kode', 'nama'])
            ->map(fn ($s) => (object) [
                'id' => $s->id,
                'label' => $s->kode ? "{$s->nama} ({$s->kode})" : $s->nama,
            ]);

        $matkulOptions = $this->id_prodi
            ? Matkul::where('id_prodi', $this->id_prodi)->whereNull('deleted_at')->orderBy('kode')->get()
            : collect();

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.kurikulum.form', [
            'prodiOptions' => $prodiOptions,
            'semesterOptions' => $semesterOptions,
            'matkulOptions' => $matkulOptions,
        ])->extends('layouts.web');
    }
}
