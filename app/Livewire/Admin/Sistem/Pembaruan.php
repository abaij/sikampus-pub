<?php

namespace App\Livewire\Admin\Sistem;

use App\Services\Update\InstallationInspector;
use App\Services\Update\Release;
use App\Services\Update\ReleaseChecker;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Halaman "Cek Pembaruan" — TAHAP PERTAMA, sengaja read-only.
 *
 * Belum ada satu pun aksi yang mengubah berkas: yang ditampilkan adalah versi terpasang vs
 * versi terbaru, changelog, dan hasil preflight yang menentukan jalur update mana yang nanti
 * bisa dipakai instalasi ini. Tombol update sungguhan menyusul setelah tampilan ini terbukti
 * melaporkan keadaan server dengan benar di lapangan — mendahulukannya berarti membangun
 * mekanisme yang mengganti berkas di atas deteksi yang belum pernah diuji di luar laptop.
 */
class Pembaruan extends Component
{
    public bool $checked = false;

    public function mount(): void
    {
        // Cek otomatis saat halaman dibuka. Aman karena hasilnya di-cache (termasuk hasil
        // gagal), jadi membuka halaman berulang kali tidak menembak sumber rilis tiap kali.
        $this->checked = true;
    }

    /**
     * @return array{release: ?Release, source: ?string, error: ?string}
     */
    #[Computed]
    public function check(): array
    {
        return app(ReleaseChecker::class)->latest();
    }

    #[Computed]
    public function inspector(): InstallationInspector
    {
        return app(InstallationInspector::class);
    }

    public function installedVersion(): string
    {
        return (string) config('sikampus.version');
    }

    /**
     * true = ada versi lebih baru, false = sudah terbaru, null = tidak diketahui.
     * Ketiganya ditampilkan berbeda; lihat Release::isNewerThan().
     */
    #[Computed]
    public function updateAvailable(): ?bool
    {
        return $this->check()['release']?->isNewerThan($this->installedVersion());
    }

    public function refreshCheck(): void
    {
        app(ReleaseChecker::class)->latest(force: true);

        unset($this->check, $this->updateAvailable);

        session()->flash('status', 'Pengecekan pembaruan diperbarui.');
    }

    public function render()
    {
        return view('livewire.admin.sistem.pembaruan')->extends('layouts.web');
    }
}
