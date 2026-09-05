<?php

namespace App\Services\Update;

use App\Models\UpdateRun;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Pembaruan untuk instalasi hasil klon Git: ambil tag rilis, fast-forward ke sana, lalu pasang
 * ulang dependensi dan bangun ulang aset.
 *
 * KENAPA composer DAN npm ikut wajib, bukan cuma "git pull": vendor/ dan public/build/ keduanya
 * di-gitignore repo ini, jadi menarik kode saja tidak pernah memperbarui dependensi maupun aset.
 * Halaman yang memakai @vite() akan error begitu rilis baru menambah aset. Ketersediaan ketiga
 * binary diperiksa InstallationInspector::canUseGitPath() sebelum jalur ini ditawarkan.
 *
 * Migrasi database TIDAK dijalankan di sini — alasannya sama seperti ArchiveUpdater::swap():
 * proses yang sedang berjalan sudah memuat autoloader lama.
 */
class GitUpdater
{
    public function __construct(private readonly string $root = '') {}

    private function root(): string
    {
        return $this->root !== '' ? $this->root : base_path();
    }

    /**
     * Menolak melanjutkan kalau ada perubahan yang belum di-commit.
     *
     * Fast-forward akan menimpa berkas yang terlacak, dan perubahan lokal yang belum di-commit
     * tidak punya salinan di mana pun — sekali hilang, tidak ada backup yang bisa
     * mengembalikannya, tidak seperti jalur arsip yang menyimpan versi lama.
     */
    public function assertCleanWorkingTree(): void
    {
        $status = $this->git(['status', '--porcelain'], 30);

        if (trim($status) !== '') {
            $count = count(array_filter(explode("\n", trim($status))));

            throw new RuntimeException(
                "Terdapat {$count} berkas dengan perubahan lokal yang belum di-commit. "
                .'Commit atau kembalikan perubahan itu lebih dulu, atau gunakan pembaruan lewat berkas rilis.'
            );
        }
    }

    public function fetch(UpdateRun $run): void
    {
        $this->git(['fetch', '--tags', '--force', 'origin'], 300);

        $run->appendLog('Tag dan commit terbaru berhasil diambil dari origin.');
    }

    /**
     * Fast-forward SAJA (--ff-only), tidak pernah membuat commit merge.
     *
     * Kalau riwayat lokal sudah menyimpang dari rilis, perintah ini gagal alih-alih menghasilkan
     * penggabungan yang mungkin salah. Kegagalan itu diinginkan: instalasi seperti itu harus
     * ditangani manusia, atau lewat jalur arsip yang menyimpan backup.
     */
    public function checkout(UpdateRun $run, string $version): void
    {
        $tag = 'v'.ltrim($version, 'vV');

        try {
            $this->git(['merge', '--ff-only', $tag], 120);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                "Tidak bisa fast-forward ke {$tag}: riwayat Git instalasi ini sudah menyimpang dari rilis resmi. "
                .'Gunakan pembaruan lewat berkas rilis, atau selesaikan penyimpangan itu secara manual. Detail: '
                .$e->getMessage()
            );
        }

        $run->appendLog("Kode diperbarui ke {$tag}.");
    }

    public function installDependencies(UpdateRun $run): void
    {
        $composer = DeployCommand::composer();
        $this->run([...$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'], 900);
        $run->appendLog('Dependensi PHP terpasang.');

        $npm = DeployCommand::npm();
        $this->run([...$npm, 'ci', '--no-audit', '--no-fund'], 900);
        $run->appendLog('Dependensi Node terpasang.');

        $this->run([...$npm, 'run', 'build'], 900);
        $run->appendLog('Aset frontend terbangun ulang.');
    }

    private function git(array $arguments, int $timeout): string
    {
        return $this->run([...DeployCommand::git(), ...$arguments], $timeout);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, int $timeout): string
    {
        $result = Process::path($this->root())->timeout($timeout)->run($command);

        if ($result->failed()) {
            $detail = trim($result->errorOutput()) ?: trim($result->output());

            throw new RuntimeException(
                'Perintah "'.implode(' ', array_slice($command, 0, 3)).'" gagal: '.mb_substr($detail, 0, 1000)
            );
        }

        return $result->output();
    }
}
