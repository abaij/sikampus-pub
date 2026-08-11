<?php

namespace App\Livewire\Admin\Sistem\Provinsi;

use App\Models\Negara;
use App\Models\Provinsi;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $provinsiId = null;

    public string $nama = '';

    public string $kode = '';

    public ?int $id_negara = null;

    public function mount(?int $id = null): void
    {
        $this->provinsiId = $id;

        if ($id === null) {
            return;
        }

        $provinsi = Provinsi::findOrFail($id);

        $this->nama = $provinsi->nama;
        $this->kode = (string) $provinsi->kode;
        $this->id_negara = $provinsi->id_negara;
    }

    /**
     * Rule sama persis dengan ProvinsiController::store/update.
     */
    protected function rules(): array
    {
        $uniqueKode = Rule::unique('provinsi', 'kode');

        if ($this->provinsiId) {
            $uniqueKode = $uniqueKode->ignore($this->provinsiId);
        }

        return [
            'nama' => ['required', 'string', 'max:255'],
            'kode' => ['required', 'string', 'max:50', $uniqueKode],
            'id_negara' => ['required', 'exists:negara,id'],
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        if ($this->provinsiId) {
            Provinsi::findOrFail($this->provinsiId)->update($validated);
        } else {
            Provinsi::create($validated);
        }

        session()->flash('status', 'Provinsi berhasil disimpan.');

        return redirect()->route('admin.sistem.provinsi');
    }

    public function render()
    {
        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.sistem.provinsi.form', [
            'negaraOptions' => Negara::orderBy('nama')->get(['id', 'nama']),
        ])->extends('layouts.web');
    }
}
