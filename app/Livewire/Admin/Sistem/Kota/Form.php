<?php

namespace App\Livewire\Admin\Sistem\Kota;

use App\Models\Kota;
use App\Models\Negara;
use App\Models\Provinsi;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $kotaId = null;

    public string $nama = '';

    public string $kode = '';

    public ?int $id_provinsi = null;

    // Filter Negara murni alat bantu UI untuk mempersempit pilihan Provinsi di bawah — TIDAK
    // divalidasi/disimpan, mirror pola filterProdi di App\Livewire\Admin\JadwalUjian\Form.
    public string $filterNegara = '';

    public function mount(?int $id = null): void
    {
        $this->kotaId = $id;

        if ($id === null) {
            return;
        }

        $kota = Kota::findOrFail($id);

        $this->nama = $kota->nama;
        $this->kode = (string) $kota->kode;
        $this->id_provinsi = $kota->id_provinsi;
        $this->filterNegara = (string) ($kota->id_provinsi ? Provinsi::find($kota->id_provinsi)?->id_negara : '');
    }

    public function updatedFilterNegara(): void
    {
        $this->id_provinsi = null;
    }

    /**
     * Rule sama persis dengan KotaController::store/update.
     */
    protected function rules(): array
    {
        $uniqueKode = Rule::unique('kota', 'kode');

        if ($this->kotaId) {
            $uniqueKode = $uniqueKode->ignore($this->kotaId);
        }

        return [
            'nama' => ['required', 'string', 'max:255'],
            'kode' => ['required', 'string', 'max:50', $uniqueKode],
            'id_provinsi' => ['nullable', 'exists:provinsi,id'],
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        if ($this->kotaId) {
            Kota::findOrFail($this->kotaId)->update($validated);
        } else {
            Kota::create($validated);
        }

        session()->flash('status', 'Kota berhasil disimpan.');

        return redirect()->route('admin.sistem.kota');
    }

    public function render()
    {
        $provinsiQuery = Provinsi::query()->orderBy('nama');
        if ($this->filterNegara !== '') {
            $provinsiQuery->where('id_negara', (int) $this->filterNegara);
        }

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.sistem.kota.form', [
            'negaraOptions' => Negara::orderBy('nama')->get(['id', 'nama']),
            'provinsiOptions' => $provinsiQuery->get(['id', 'nama']),
        ])->extends('layouts.web');
    }
}
