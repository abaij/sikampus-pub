<?php

namespace App\Livewire\Admin\KeringananBiaya;

use App\Models\KeringananBiaya;
use App\Services\KeringananBiayaPersentaseService;
use App\Support\PanelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    // Terikat <x-searchable-select>, string karena opsi kosong = "semua status".
    public string $filterStatus = '';

    public int $perPage = 10;

    public ?int $confirmingDeleteId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterStatus(): void
    {
        $this->resetPage();
    }

    public function statusOptions(): array
    {
        return [
            'pending' => 'Menunggu',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
        ];
    }

    public function confirmDelete(int $id): void
    {
        // Tombol pemicu ini disembunyikan di Blade untuk user tanpa hak hapus, tapi method
        // Livewire tetap bisa dipanggil langsung lewat request yang dipalsukan — pengecekan di
        // sini dan di delete() adalah otoritas sebenarnya, bukan sekadar UI.
        abort_unless(PanelAccess::can(Auth::user(), 'keringanan biaya', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus keringanan biaya.');

        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /**
     * Sama persis dengan KeringananBiayaController::destroy — tidak ada pengecekan scope prodi,
     * mengikuti controller aslinya (lihat catatan scope di render()).
     */
    public function delete(): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'keringanan biaya', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus keringanan biaya.');

        if (! $this->confirmingDeleteId) {
            return;
        }

        $keringananBiaya = KeringananBiaya::findOrFail($this->confirmingDeleteId);

        if ($keringananBiaya->file_lampiran && Storage::disk('public')->exists($keringananBiaya->file_lampiran)) {
            Storage::disk('public')->delete($keringananBiaya->file_lampiran);
        }

        $keringananBiaya->delete();

        $this->confirmingDeleteId = null;
        $this->resetPage();
    }

    /**
     * Hitung ulang nominal keringanan persentase yang sudah disetujui.
     *
     * Nominal adalah snapshot saat approve, jadi bisa basi kalau tagihan semester itu bertambah
     * atau berubah setelahnya. Sengaja manual, bukan otomatis: menghitung ulang diam-diam akan
     * mengubah angka yang sudah dilihat mahasiswa tanpa jejak.
     */
    public function hitungUlang(int $id): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'keringanan biaya', 'update'), 403, 'Anda tidak memiliki hak untuk mengubah keringanan biaya.');

        $row = KeringananBiaya::findOrFail($id);

        if ($row->persentase === null || $row->status !== 'approved') {
            return;
        }

        $gagal = KeringananBiayaPersentaseService::terapkanSaatApprove($row);
        if ($gagal !== null) {
            session()->flash('error', $gagal);

            return;
        }

        $row->updated_by = Auth::user()?->name ?? (string) Auth::id();
        $row->save();

        session()->flash('status', 'Nominal keringanan dihitung ulang: Rp'.number_format((float) $row->nominal, 0, ',', '.').'.');
    }

    /**
     * Sama persis dengan KeringananBiayaController::index. Scope prodi hanya diterapkan di
     * daftar ini — controller aslinya juga tidak mengecek scope di show/update/destroy.
     */
    public function render()
    {
        $query = KeringananBiaya::with([
            'jenisKeringananBiaya:id,nama,is_persentase,nominal',
            'mahasiswa:id,nama,nim,id_prodi',
            'mahasiswa.prodi:id,nama',
            'semester:id,kode,nama',
            'aturanAksesKeuangan:id,kode_akses,nama',
        ]);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('mahasiswa', fn ($q) => $q->whereIn('id_prodi', $allowedProdiIds));
            }
        }

        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('keterangan', 'like', "%{$search}%")
                    ->orWhereHas('mahasiswa', function ($mq) use ($search) {
                        $mq->where('nama', 'like', "%{$search}%")->orWhere('nim', 'like', "%{$search}%");
                    });
            });
        }

        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }

        $keringananBiayaList = $query->orderByDesc('tanggal_pengajuan')
            ->orderByDesc('id')
            ->paginate($this->perPage);

        return view('livewire.admin.keringanan-biaya.index', [
            'keringananBiayaList' => $keringananBiayaList,
        ])->extends('layouts.web');
    }
}
