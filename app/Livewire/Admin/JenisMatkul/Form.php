<?php

namespace App\Livewire\Admin\JenisMatkul;

use App\Models\JenisMatkul;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $jenisMatkulId = null;

    public string $kode = '';

    public string $nama = '';

    public string $deskripsi = '';

    public function mount(?int $id = null): void
    {
        $this->jenisMatkulId = $id;

        if ($id === null) {
            return;
        }

        $jenisMatkul = JenisMatkul::findOrFail($id);

        $this->kode = (string) $jenisMatkul->kode;
        $this->nama = $jenisMatkul->nama;
        $this->deskripsi = (string) $jenisMatkul->deskripsi;
    }

    /**
     * Rule sama persis dengan JenisMatkulController::store/update.
     */
    protected function rules(): array
    {
        $uniqueKode = Rule::unique('jenis_matkul', 'kode');
        $uniqueNama = Rule::unique('jenis_matkul', 'nama');

        if ($this->jenisMatkulId) {
            $uniqueKode = $uniqueKode->ignore($this->jenisMatkulId);
            $uniqueNama = $uniqueNama->ignore($this->jenisMatkulId);
        }

        return [
            'nama' => ['required', 'string', 'max:255', $uniqueNama],
            'kode' => ['required', 'string', 'max:50', $uniqueKode],
            'deskripsi' => ['nullable', 'string'],
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        if ($validated['deskripsi'] === '') {
            $validated['deskripsi'] = null;
        }

        if ($this->jenisMatkulId) {
            JenisMatkul::findOrFail($this->jenisMatkulId)->update($validated);
        } else {
            JenisMatkul::create($validated);
        }

        session()->flash('status', 'Jenis mata kuliah berhasil disimpan.');

        return redirect()->route('admin.jenis-matkul.index');
    }

    public function render()
    {
        return view('livewire.admin.jenis-matkul.form')->extends('layouts.web');
    }
}
