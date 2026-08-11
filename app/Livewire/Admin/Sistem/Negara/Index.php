<?php

namespace App\Livewire\Admin\Sistem\Negara;

use App\Models\Negara;
use Livewire\Component;
use Livewire\WithPagination;

// Rute modul ini dikunci 'role.admin.superadmin' di routes/web.php (lihat catatan menu Sistem
// di sana) — jadi tidak ada pengecekan PanelAccess::can() tambahan di sini, beda dari modul lain
// yang bisa diakses admin_akademik/admin_keuangan lewat permission granular.
class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public int $perPage = 10;

    public ?int $confirmingDeleteId = null;

    public function updatingSearch(): void
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

        Negara::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->resetPage();
    }

    /**
     * Sama persis dengan NegaraController::index.
     */
    public function render()
    {
        $query = Negara::query();

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('nama', 'like', "%{$this->search}%")
                    ->orWhere('kode', 'like', "%{$this->search}%");
            });
        }

        $negaraList = $query->orderBy('nama')->paginate($this->perPage);

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.sistem.negara.index', [
            'negaraList' => $negaraList,
        ])->extends('layouts.web');
    }
}
