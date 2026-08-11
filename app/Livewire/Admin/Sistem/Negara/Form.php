<?php

namespace App\Livewire\Admin\Sistem\Negara;

use App\Models\Negara;
use Livewire\Component;

class Form extends Component
{
    public ?int $negaraId = null;

    public string $nama = '';

    public string $kode = '';

    public function mount(?int $id = null): void
    {
        $this->negaraId = $id;

        if ($id === null) {
            return;
        }

        $negara = Negara::findOrFail($id);

        $this->nama = $negara->nama;
        $this->kode = (string) $negara->kode;
    }

    /**
     * Rule sama persis dengan NegaraController::store/update — tidak ada Rule::unique karena
     * DB tidak punya unique constraint di kolom kode/nama negara.
     */
    protected function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'kode' => ['required', 'string', 'max:50'],
        ];
    }

    public function save()
    {
        $validated = $this->validate();

        if ($this->negaraId) {
            Negara::findOrFail($this->negaraId)->update($validated);
        } else {
            Negara::create($validated);
        }

        session()->flash('status', 'Negara berhasil disimpan.');

        return redirect()->route('admin.sistem.negara');
    }

    public function render()
    {
        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.sistem.negara.form')->extends('layouts.web');
    }
}
