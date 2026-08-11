<?php

namespace App\Livewire\Admin\Sistem\Provinsi;

use App\Models\Negara;
use App\Models\Provinsi;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// Rute modul ini dikunci 'role.admin.superadmin' di routes/web.php — lihat catatan yang sama
// di App\Livewire\Admin\Sistem\Negara\Index.
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url(as: 'id_negara')]
    public string $filterNegara = '';

    public int $perPage = 10;

    public ?int $confirmingDeleteId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterNegara(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        Provinsi::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->resetPage();
    }

    /**
     * Sama persis dengan ProvinsiController::index (dengan with=parent, karena tabel selalu
     * menampilkan nama negara induknya).
     */
    public function render()
    {
        $query = Provinsi::query()->with('negara');

        if ($this->search !== '') {
            $query->where('nama', 'like', "%{$this->search}%");
        }

        if ($this->filterNegara !== '') {
            $query->where('id_negara', (int) $this->filterNegara);
        }

        $provinsiList = $query->orderBy('nama')->paginate($this->perPage);

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.sistem.provinsi.index', [
            'provinsiList' => $provinsiList,
            'negaraOptions' => Negara::orderBy('nama')->get(['id', 'nama']),
        ])->extends('layouts.web');
    }
}
