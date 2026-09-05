<?php

namespace App\Services\Update;

use App\Models\UpdateRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Menjalankan SATU langkah pembaruan per pemanggilan.
 *
 * Dipecah per langkah karena unduh dan ekstrak masing-masing bisa memakan menit — satu request
 * yang mengerjakan semuanya akan menabrak max_execution_time di hosting mana pun yang ketat,
 * yaitu lingkungan yang justru paling membutuhkan pembaruan satu klik.
 *
 * Maintenance mode dinyalakan tepat sebelum langkah pertama yang mengubah berkas hidup, dan
 * dimatikan setelah finalize. Rute wizard ini dikecualikan dari maintenance di bootstrap/app.php
 * — tanpa itu, menyalakan maintenance akan mengunci halaman yang sedang menjalankan pembaruan.
 */
class UpdateRunner
{
    public function __construct(
        private readonly ArchiveUpdater $archive,
        private readonly GitUpdater $git,
        private readonly ReleaseChecker $checker,
    ) {}

    /**
     * @return string Langkah yang baru saja diselesaikan.
     */
    public function performNext(UpdateRun $run): string
    {
        if (! $run->isRunning()) {
            throw new RuntimeException('Pembaruan ini sudah selesai.');
        }

        $step = $run->step ?? $run->steps()[0] ?? null;

        if ($step === null) {
            throw new RuntimeException('Jalur pembaruan tidak dikenali.');
        }

        if ($step === (UpdateRun::MUTATING_STEP[$run->path] ?? null)) {
            $this->enterMaintenance($run);
        }

        try {
            $this->dispatch($run, $step);
        } catch (Throwable $e) {
            // Kegagalan SETELAH berkas mulai diubah meninggalkan aplikasi dalam keadaan yang
            // tidak boleh dianggap sehat — maintenance sengaja DIBIARKAN menyala supaya
            // pengunjung tidak melihat aplikasi setengah tertukar. Halaman hasil menjelaskan
            // cara mengangkatnya kembali.
            if (! $run->hasStartedMutating()) {
                $this->leaveMaintenance($run);
            }

            $run->markFailed($e->getMessage());

            throw $e;
        }

        $next = $run->nextStep();

        if ($next === null) {
            $run->update([
                'status' => UpdateRun::STATUS_SUCCESS,
                'finished_at' => now(),
            ]);
            $run->appendLog('Pembaruan selesai.');
        } else {
            $run->update(['step' => $next]);
        }

        return $step;
    }

    private function dispatch(UpdateRun $run, string $step): void
    {
        match ($step) {
            'download' => $this->archive->download($run, $this->release($run)),
            'verify' => $this->archive->verify($run, $this->release($run)),
            'extract' => $this->archive->extract($run),
            'swap' => $this->swap($run),
            'fetch' => $this->git->fetch($run),
            'checkout' => $this->git->checkout($run, $run->version_to),
            'dependencies' => $this->git->installDependencies($run),
            'finalize' => $this->finalize($run),
            default => throw new RuntimeException("Langkah pembaruan tidak dikenali: {$step}"),
        };
    }

    private function swap(UpdateRun $run): void
    {
        $this->archive->swap($run);
        $this->archive->relinkPublicStorage($run);
    }

    /**
     * Migrasi dijalankan DI SINI, bukan bersama swap: request ini boot dengan kode yang sudah
     * baru, sedangkan request yang melakukan swap masih memegang autoloader dan kelas lama di
     * memori. Lihat docblock ArchiveUpdater::swap().
     */
    private function finalize(UpdateRun $run): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $run->appendLog('Migrasi database dijalankan.');

        foreach (['config:clear', 'route:clear', 'view:clear'] as $command) {
            Artisan::call($command);
        }

        // route:cache SENGAJA tidak dijalankan — route milik plugin berhenti terdaftar kalau
        // route sedang di-cache (lihat App\Livewire\Admin\Sistem\Plugin::enable()).
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        $run->appendLog('Cache dibersihkan.');

        $this->leaveMaintenance($run);

        if ($run->path === UpdateRun::PATH_ARCHIVE) {
            // Backup sengaja TIDAK dihapus di sini. Kalau versi baru ternyata bermasalah, itulah
            // satu-satunya salinan kode lama yang tersisa; membersihkannya adalah tindakan
            // terpisah yang dilakukan sadar oleh superadmin.
            $run->appendLog('Backup versi lama disimpan di: '.$run->backup_path);
        }
    }

    private function enterMaintenance(UpdateRun $run): void
    {
        try {
            Artisan::call('down', ['--render' => 'errors::503']);
            $run->appendLog('Mode pemeliharaan dinyalakan.');
        } catch (Throwable $e) {
            // Bukan alasan membatalkan pembaruan: tanpa maintenance, risikonya hanya pengunjung
            // melihat error selama beberapa detik penukaran, bukan kerusakan data.
            $run->appendLog('PERINGATAN: gagal menyalakan mode pemeliharaan: '.$e->getMessage());
        }
    }

    private function leaveMaintenance(UpdateRun $run): void
    {
        try {
            if (File::exists(storage_path('framework/down'))) {
                Artisan::call('up');
                $run->appendLog('Mode pemeliharaan dimatikan.');
            }
        } catch (Throwable $e) {
            $run->appendLog('PERINGATAN: gagal mematikan mode pemeliharaan: '.$e->getMessage()
                .' Hapus berkas storage/framework/down secara manual.');
        }
    }

    private function release(UpdateRun $run): Release
    {
        $release = $this->checker->latest()['release'];

        if (! $release || $release->version !== $run->version_to) {
            throw new RuntimeException('Informasi rilis tidak lagi cocok dengan pembaruan ini. Mulai ulang pengecekan.');
        }

        return $release;
    }
}
