<?php

namespace App\Console\Commands;

use App\Models\UpdateRun;
use App\Services\Update\InstallationInspector;
use App\Services\Update\ReleaseChecker;
use App\Services\Update\UpdateRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Jalan darurat untuk wizard pembaruan di /pembaruan.
 *
 * KENAPA ADA meskipun wizard web sudah bekerja: request browser bisa mati di tengah langkah
 * yang panjang (unduh, composer install) di server dengan batas ketat, dan yang tersisa adalah
 * pembaruan berstatus "running" yang tidak ada yang melanjutkan. Perintah ini melanjutkan
 * pembaruan yang sama dari langkah terakhir — bukan memulai yang baru — sehingga tidak ada
 * pekerjaan yang diulang dan tidak ada direktori kerja yang bertumpuk.
 *
 * BERHENTI SEBELUM 'finalize' dan meminta dijalankan ulang. Alasannya sama seperti di wizard
 * web: proses yang melakukan penukaran berkas masih memegang autoloader dan kelas kode LAMA di
 * memori, jadi migrasi harus dijalankan oleh proses baru yang boot dengan kode baru.
 */
class SikampusUpdate extends Command
{
    protected $signature = 'sikampus:update
        {--yes : Lewati konfirmasi (untuk skrip otomatis)}';

    protected $description = 'Perbarui Sikampus ke rilis terbaru, atau lanjutkan pembaruan yang tertunda';

    public function handle(UpdateRunner $runner, ReleaseChecker $checker, InstallationInspector $inspector): int
    {
        $run = UpdateRun::where('status', UpdateRun::STATUS_RUNNING)->latest('id')->first();

        if ($run) {
            $this->info("Melanjutkan pembaruan {$run->version_from} → {$run->version_to} dari langkah \"{$run->step}\".");
        } else {
            $run = $this->startNewRun($checker, $inspector);

            if (! $run instanceof UpdateRun) {
                return $run;
            }
        }

        return $this->runSteps($runner, $run);
    }

    private function startNewRun(ReleaseChecker $checker, InstallationInspector $inspector): UpdateRun|int
    {
        if ($inspector->type() === InstallationInspector::TYPE_MANAGED) {
            $this->error('Instalasi ini dikelola Sikampus Cloud; pembaruan dijalankan dari portal.');

            return self::FAILURE;
        }

        if (! $inspector->isFullyWritable()) {
            $blockers = array_keys(array_filter($inspector->writablePaths(), fn ($ok) => ! $ok));

            $this->error('Direktori aplikasi tidak bisa ditulis: '.implode(', ', $blockers));

            return self::FAILURE;
        }

        $check = $checker->latest();
        $release = $check['release'];

        if (! $release) {
            $this->error($check['error'] ?? 'Informasi rilis tidak tersedia.');

            return self::FAILURE;
        }

        $installed = (string) config('sikampus.version');

        if ($release->isNewerThan($installed) !== true) {
            $this->info("Tidak ada pembaruan. Versi terpasang: {$installed}, versi terbaru: {$release->version}.");

            return self::SUCCESS;
        }

        $path = $inspector->canUseGitPath() ? UpdateRun::PATH_GIT : UpdateRun::PATH_ARCHIVE;

        $this->warn('Backup database Anda sebelum melanjutkan. Migrasi mengubah struktur tabel dan tidak dibatalkan otomatis.');
        $this->line("  Versi terpasang : {$installed}");
        $this->line("  Versi tujuan    : {$release->version}");
        $this->line("  Jalur           : {$path}");

        if (! $this->option('yes') && ! $this->confirm('Lanjutkan pembaruan?')) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $run = UpdateRun::create([
            'version_from' => $installed,
            'version_to' => $release->version,
            'path' => $path,
            'status' => UpdateRun::STATUS_RUNNING,
            'step' => UpdateRun::STEPS[$path][0],
            'started_at' => now(),
        ]);

        $run->appendLog("Pembaruan dimulai dari CLI: {$run->version_from} → {$run->version_to} (jalur {$path}).");

        return $run;
    }

    private function runSteps(UpdateRunner $runner, UpdateRun $run): int
    {
        while ($run->isRunning()) {
            $step = $run->step;

            $this->line("→ {$step}…");

            try {
                $runner->performNext($run);
            } catch (Throwable $e) {
                $this->error("Langkah \"{$step}\" gagal: ".$e->getMessage());

                if (File::exists(storage_path('framework/down'))) {
                    $this->warn('Aplikasi masih dalam mode pemeliharaan. Setelah masalahnya dibereskan, '
                        .'jalankan ulang perintah ini, atau angkat manual dengan: php artisan up');
                }

                return self::FAILURE;
            }

            $run->refresh();

            // Berhenti sebelum finalize: migrasi harus dijalankan proses yang boot dengan kode
            // baru, bukan proses ini yang masih memegang autoloader lama di memori.
            if ($run->isRunning() && $run->step === 'finalize') {
                $this->newLine();
                $this->info('Berkas aplikasi sudah diperbarui.');
                $this->warn('Jalankan perintah ini SEKALI LAGI untuk menjalankan migrasi dan menyelesaikan pembaruan:');
                $this->line('  php artisan sikampus:update --yes');
                $this->newLine();
                $this->comment('Aplikasi sengaja dibiarkan dalam mode pemeliharaan sampai langkah itu selesai.');

                return self::SUCCESS;
            }
        }

        if ($run->status === UpdateRun::STATUS_SUCCESS) {
            $this->newLine();
            $this->info("Pembaruan selesai. Versi terpasang sekarang: {$run->version_to}.");

            if ($run->backup_path) {
                $this->comment('Berkas versi lama disimpan di: '.$run->backup_path);
            }

            return self::SUCCESS;
        }

        $this->error('Pembaruan berakhir dengan status: '.$run->status);

        return self::FAILURE;
    }
}
