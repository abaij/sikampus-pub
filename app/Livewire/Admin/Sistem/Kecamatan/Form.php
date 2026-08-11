<?php

namespace App\Livewire\Admin\Sistem\Kecamatan;

use App\Models\Kecamatan;
use App\Models\Kota;
use App\Models\Negara;
use App\Models\Provinsi;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $kecamatanId = null;

    public string $nama = '';

    public string $kode = '';

    public ?int $id_kota = null;

    // Filter Negara & Provinsi murni alat bantu UI untuk mempersempit pilihan Kota di bawah —
    // TIDAK divalidasi/disimpan, mirror pola filterProdi di App\Livewire\Admin\JadwalUjian\Form.
    public string $filterNegara = '';

    public string $filterProvinsi = '';

    public function mount(?int $id = null): void
    {
        $this->kecamatanId = $id;

        if ($id === null) {
            return;
        }

        $kecamatan = Kecamatan::findOrFail($id);

        $this->nama = $kecamatan->nama;
        $this->kode = (string) $kecamatan->kode;
        $this->id_kota = $kecamatan->id_kota;

        $kota = $kecamatan->id_kota ? Kota::find($kecamatan->id_kota) : null;
        $this->filterProvinsi = (string) ($kota?->id_provinsi ?? '');
        $this->filterNegara = (string) ($kota?->provinsi?->id_negara ?? '');
    }

    public function updatedFilterNegara(): void
    {
        $this->filterProvinsi = '';
        $this->id_kota = null;
    }

    public function updatedFilterProvinsi(): void
    {
        $this->id_kota = null;
    }

    /**
     * Rule sama persis dengan KecamatanController::store/update.
     */
    protected function rules(): array
    {
        $uniqueKode = Rule::unique('kecamatan', 'kode');

        if ($this->kecamatanId) {
            $uniqueKode = $uniqueKode->ignore($this->kecamatanId);
        }

        return [
            'nama' => ['required', 'string', 'max:255'],
            'kode' => ['required', 'string', 'max:50', $uniqueKode],
            'id_kota' => ['nullable', 'exists:kota,id'],
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        if ($this->kecamatanId) {
            Kecamatan::findOrFail($this->kecamatanId)->update($validated);
        } else {
            Kecamatan::create($validated);
        }

        session()->flash('status', 'Kecamatan berhasil disimpan.');

        return redirect()->route('admin.sistem.kecamatan');
    }

    public function render()
    {
        $provinsiQuery = Provinsi::query()->orderBy('nama');
        if ($this->filterNegara !== '') {
            $provinsiQuery->where('id_negara', (int) $this->filterNegara);
        }

        $kotaQuery = Kota::query()->orderBy('nama');
        if ($this->filterProvinsi !== '') {
            $kotaQuery->where('id_provinsi', (int) $this->filterProvinsi);
        } elseif ($this->filterNegara !== '') {
            $kotaQuery->whereIn('id_provinsi', Provinsi::where('id_negara', (int) $this->filterNegara)->pluck('id'));
        }

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.sistem.kecamatan.form', [
            'negaraOptions' => Negara::orderBy('nama')->get(['id', 'nama']),
            'provinsiOptions' => $provinsiQuery->get(['id', 'nama']),
            'kotaOptions' => $kotaQuery->get(['id', 'nama']),
        ])->extends('layouts.web');
    }
}
