<?php

namespace App\Services\Update;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Periksa keadaan nyata instalasi ini untuk menjawab satu pertanyaan: kalau tombol update
 * ditekan, jalur mana yang akan dipakai — dan apakah jalur itu benar-benar bisa berjalan.
 *
 * Semua pemeriksaan di sini bersifat MEMBACA saja. Tidak ada satu pun yang menjalankan
 * proses, menyentuh jaringan, atau mengubah berkas: kelas ini dipanggil setiap kali halaman
 * pembaruan dibuka, jadi harus murah dan tidak punya efek samping.
 *
 * Binary dicari lewat ExecutableFinder (menelusuri PATH) dan BUKAN dengan menjalankannya.
 * Menjalankan `git --version` untuk sekadar tahu git ada akan gagal justru di server yang
 * paling perlu jawabannya — yaitu server yang proc_open-nya dimatikan.
 */
class InstallationInspector
{
    public const TYPE_GIT = 'git';

    public const TYPE_ARCHIVE = 'archive';

    public const TYPE_MANAGED = 'managed';

    /**
     * Instalasi yang dikelola Sikampus Cloud diperlakukan sebagai tipe tersendiri, bukan
     * sebagai instalasi Git biasa — walaupun deploy engine memang meng-klon lewat Git.
     * Tanpa pembedaan ini, customer Cloud bisa menjalankan update mandiri yang bertabrakan
     * dengan deploy engine di sisi portal.
     */
    public function type(): string
    {
        if (filter_var(env('SIKAMPUS_MANAGED', false), FILTER_VALIDATE_BOOLEAN)) {
            return self::TYPE_MANAGED;
        }

        return is_dir(base_path('.git')) ? self::TYPE_GIT : self::TYPE_ARCHIVE;
    }

    public function typeLabel(): string
    {
        return match ($this->type()) {
            self::TYPE_MANAGED => 'Sikampus Cloud (dikelola)',
            self::TYPE_GIT => 'Klon Git',
            default => 'Source siap pakai',
        };
    }

    public function canRunProcesses(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * Direktori yang isinya diganti saat update. Root project sendiri ikut diperiksa karena
     * berkas di akar (artisan, composer.json) juga diganti, dan karena membuat direktori
     * staging butuh izin tulis di sana.
     *
     * @return array<string, bool>
     */
    public function writablePaths(): array
    {
        $paths = ['' => base_path()];

        foreach (['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor'] as $dir) {
            $paths[$dir] = base_path($dir);
        }

        $result = [];

        foreach ($paths as $label => $path) {
            $result[$label === '' ? '(root project)' : $label] = is_dir($path) && is_writable($path);
        }

        return $result;
    }

    public function isFullyWritable(): bool
    {
        return ! in_array(false, $this->writablePaths(), true);
    }

    /**
     * @return array<string, ?string>
     */
    public function binaries(): array
    {
        $finder = new ExecutableFinder;

        $found = [];

        foreach (['git', 'composer', 'npm'] as $binary) {
            $found[$binary] = $this->canRunProcesses() ? $finder->find($binary) : null;
        }

        return $found;
    }

    /**
     * Jalur Git menuntut ketiga binary DAN proc_open. npm ikut wajib walau terasa berlebihan:
     * public/build/ di-gitignore repo produk, jadi `git pull` saja tidak pernah memperbarui
     * aset — dan halaman yang memakai @vite() akan error begitu ada aset baru.
     */
    public function canUseGitPath(): bool
    {
        if ($this->type() !== self::TYPE_GIT || ! $this->canRunProcesses()) {
            return false;
        }

        return ! in_array(null, $this->binaries(), true);
    }

    /**
     * Manifest milik versi yang SEDANG terpasang, kalau ada. Instalasi hasil klon Git tidak
     * pernah punya berkas ini (dibuat saat build, tidak ikut di-commit) — untuk mereka
     * updater nanti mengunduh manifest versi terpasangnya dari saluran rilis.
     */
    public function localManifestPath(): ?string
    {
        $path = base_path('sikampus-manifest.json');

        return is_file($path) ? $path : null;
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }
}
