<?php

namespace App\Livewire\Admin\JenisMatkul;

use App\Models\JenisMatkul;
use App\Support\PanelAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

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
        // Tombol pemicu ini disembunyikan di Blade untuk user tanpa hak hapus, tapi method
        // Livewire tetap bisa dipanggil langsung lewat request yang dipalsukan — pengecekan di
        // sini dan di delete() adalah otoritas sebenarnya, bukan sekadar UI.
        abort_unless(PanelAccess::can(Auth::user(), 'jenis mata kuliah', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus jenis mata kuliah.');

        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'jenis mata kuliah', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus jenis mata kuliah.');

        if (! $this->confirmingDeleteId) {
            return;
        }

        JenisMatkul::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->resetPage();
    }

    /**
     * Sama persis dengan JenisMatkulController::index.
     */
    public function render()
    {
        $query = JenisMatkul::query();

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('nama', 'like', "%{$this->search}%")
                    ->orWhere('kode', 'like', "%{$this->search}%")
                    ->orWhere('deskripsi', 'like', "%{$this->search}%");
            });
        }

        $jenisMatkulList = $query->orderBy('nama')->paginate($this->perPage);

        return view('livewire.admin.jenis-matkul.index', [
            'jenisMatkulList' => $jenisMatkulList,
        ])->extends('layouts.web');
    }
}
