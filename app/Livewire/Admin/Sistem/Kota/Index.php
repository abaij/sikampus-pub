<?php

namespace App\Livewire\Admin\Sistem\Kota;

use App\Models\Kota;
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

    // Filter Negara murni alat bantu UI untuk mempersempit pilihan Provinsi di bawah — tidak
    // dikirim ke query (Kota tidak punya kolom id_negara langsung), mirror pola filterProdi di
    // App\Livewire\Admin\JadwalUjian\Form.
    #[Url(as: 'id_negara')]
    public string $filterNegara = '';

    #[Url(as: 'id_provinsi')]
    public string $filterProvinsi = '';

    public int $perPage = 10;

    public ?int $confirmingDeleteId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterNegara(): void
    {
        $this->filterProvinsi = '';
        $this->resetPage();
    }

    public function updatingFilterProvinsi(): void
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

        Kota::findOrFail($this->confirmingDeleteId)->delete();

        $this->confirmingDeleteId = null;
        $this->resetPage();
    }

    /**
     * Sama persis dengan KotaController::index (dengan with=parent, karena tabel selalu
     * menampilkan nama provinsi/negara induknya).
     */
    public function render()
    {
        $query = Kota::query()->with('provinsi.negara');

        if ($this->search !== '') {
            $query->where('nama', 'like', "%{$this->search}%");
        }

        if ($this->filterProvinsi !== '') {
            $query->where('id_provinsi', (int) $this->filterProvinsi);
        }

        $kotaList = $query->orderBy('nama')->paginate($this->perPage);

        $provinsiQuery = Provinsi::query()->orderBy('nama');
        if ($this->filterNegara !== '') {
            $provinsiQuery->where('id_negara', (int) $this->filterNegara);
        }

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.sistem.kota.index', [
            'kotaList' => $kotaList,
            'negaraOptions' => Negara::orderBy('nama')->get(['id', 'nama']),
            'provinsiOptions' => $provinsiQuery->get(['id', 'nama']),
        ])->extends('layouts.web');
    }
}
