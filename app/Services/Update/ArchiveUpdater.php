<?php

namespace App\Services\Update;

use App\Models\UpdateRun;
use App\Services\Plugins\PluginZipExtractor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Pembaruan dengan mengganti berkas dari zip rilis — jalur yang berlaku untuk instalasi tanpa
 * akses shell, dan jalur cadangan saat instalasi Git tidak punya git/composer/npm.
 *
 * BENTUK PENGAMANANNYA: seluruh pekerjaan lambat (unduh, verifikasi, ekstrak) dilakukan ke
 * direktori kerja TANPA menyentuh satu pun berkas hidup. Hanya langkah swap() yang berbahaya,
 * dan langkah itu sesingkat mungkin karena memakai rename direktori (seketika, bukan menyalin
 * ribuan berkas). Kalau swap gagal di tengah, direktori lama masih utuh di backup dan
 * dikembalikan oleh rollback().
 *
 * $root bisa diarahkan ke direktori lain khusus untuk pengujian. Operasi ini menghapus dan
 * memindahkan direktori aplikasi — menguji "apakah rollback benar-benar mengembalikan keadaan"
 * tidak mungkin dilakukan kalau targetnya selalu instalasi yang sedang berjalan.
 */
class ArchiveUpdater
{
    private string $root;

    public function __construct(private readonly PluginZipExtractor $extractor, ?string $root = null)
    {
        $this->root = rtrim($root ?? base_path(), '/');
    }

    public function forRoot(string $root): self
    {
        return new self($this->extractor, $root);
    }

    public function workspace(UpdateRun $run): string
    {
        return storage_path('app/updates/run-'.$run->id);
    }

    public function download(UpdateRun $run, Release $release): void
    {
        if (! filled($release->downloadUrl)) {
            throw new RuntimeException('Rilis ini tidak menyediakan berkas source siap pakai.');
        }

        $workspace = $this->workspace($run);
        File::ensureDirectoryExists($workspace);

        $target = $workspace.'/package.zip';

        // sink() mengalirkan langsung ke disk. Memuat zip puluhan MB ke memori akan menabrak
        // memory_limit di shared hosting -- justru lingkungan yang jalur ini layani.
        $response = Http::timeout(600)->sink($target)->get($release->downloadUrl);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengunduh berkas pembaruan (HTTP '.$response->status().').');
        }

        $size = is_file($target) ? filesize($target) : 0;

        if ($size < 1024) {
            throw new RuntimeException('Berkas pembaruan yang terunduh tidak wajar kecil ('.$size.' byte).');
        }

        $run->update(['workspace_path' => $workspace]);
        $run->appendLog('Unduhan selesai ('.round($size / 1048576, 1).' MB).');
    }

    /**
     * Checksum diverifikasi terhadap berkas .sha256 milik rilis. Tanpa langkah ini, unduhan yang
     * terpotong di tengah akan lolos ke tahap ekstrak dan baru ketahuan setelah berkas hidup
     * terlanjur ditukar.
     */
    public function verify(UpdateRun $run, Release $release): void
    {
        $zip = $this->workspace($run).'/package.zip';

        if (! is_file($zip)) {
            throw new RuntimeException('Berkas pembaruan tidak ditemukan; ulangi unduhan.');
        }

        if (! filled($release->checksumUrl)) {
            $run->appendLog('PERINGATAN: rilis ini tidak menyediakan checksum, verifikasi dilewati.');

            return;
        }

        $response = Http::timeout(60)->get($release->checksumUrl);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengambil checksum rilis untuk verifikasi.');
        }

        // Berkas .sha256 bisa berisi "<hash>" saja atau "<hash>  <nama berkas>".
        $expected = strtolower(trim(explode(' ', trim($response->body()))[0]));
        $actual = hash_file('sha256', $zip);

        if ($expected === '' || $actual !== $expected) {
            throw new RuntimeException('Checksum berkas pembaruan tidak cocok. Unduhan kemungkinan rusak atau tidak lengkap.');
        }

        $run->appendLog('Checksum cocok ('.substr($actual, 0, 16).'…).');
    }

    public function extract(UpdateRun $run): void
    {
        $workspace = $this->workspace($run);
        $target = $workspace.'/extracted';

        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        // Memakai ulang pengekstrak milik sistem plugin: pengaman yang dibutuhkan di sini persis
        // sama (zip-slip, path absolut, symlink, zip-bomb) dan sudah teruji di jalur itu.
        $this->extractor->extract(
            $workspace.'/package.zip',
            $target,
            (int) config('sikampus.update.max_extracted_size_kb')
        );

        $source = $this->sourceDirectory($run);

        // Artefak rilis membungkus isinya dalam satu direktori (sikampus-<versi>/). Kalau
        // pembungkus itu tidak ada, isinya bukan artefak rilis yang kita harapkan -- berhenti
        // sekarang, sebelum ada berkas hidup yang ditukar dengan isi yang salah.
        foreach (['app', 'vendor', 'public'] as $required) {
            if (! File::isDirectory($source.'/'.$required)) {
                throw new RuntimeException("Isi berkas pembaruan tidak lengkap: direktori \"{$required}\" tidak ditemukan.");
            }
        }

        $run->appendLog('Ekstraksi selesai.');
    }

    /**
     * Direktori berisi aplikasi baru di dalam hasil ekstrak.
     */
    public function sourceDirectory(UpdateRun $run): string
    {
        $extracted = $this->workspace($run).'/extracted';
        $entries = File::directories($extracted);

        // Satu direktori pembungkus adalah bentuk yang dihasilkan scripts/build-release.sh.
        // Kalau isinya ternyata sudah di akar (zip yang dikemas dengan cara lain), pakai akarnya.
        if (count($entries) === 1 && ! File::isDirectory($extracted.'/app')) {
            return $entries[0];
        }

        return $extracted;
    }

    /**
     * TAHAP BERBAHAYA. Tukar direktori & berkas aplikasi dengan versi baru.
     *
     * Migrasi database SENGAJA TIDAK dijalankan di sini, melainkan di request berikutnya:
     * proses PHP yang sedang berjalan sudah memuat autoloader dan kelas dari kode LAMA, jadi
     * menjalankan migrasi sekarang mencampur kode lama di memori dengan berkas baru di disk —
     * kelas baru dari vendor/ yang baru bisa gagal ditemukan. Request berikutnya boot bersih.
     */
    public function swap(UpdateRun $run): void
    {
        $source = $this->sourceDirectory($run);
        $backup = $this->workspace($run).'/backup';

        File::ensureDirectoryExists($backup);
        $run->update(['backup_path' => $backup]);

        $done = [];

        try {
            foreach (UpdatePaths::directories() as $name) {
                if (! File::isDirectory($source.'/'.$name)) {
                    continue;
                }

                $this->swapEntry($name, $source, $backup, isDirectory: true);
                $done[] = ['name' => $name, 'directory' => true];
            }

            foreach (UpdatePaths::files() as $name) {
                if (! is_file($source.'/'.$name)) {
                    continue;
                }

                $this->swapEntry($name, $source, $backup, isDirectory: false);
                $done[] = ['name' => $name, 'directory' => false];
            }
        } catch (Throwable $e) {
            $run->appendLog('Penukaran gagal, mengembalikan berkas lama…');
            $this->rollback($run, $done);

            throw new RuntimeException('Penukaran berkas gagal dan sudah dikembalikan: '.$e->getMessage(), 0, $e);
        }

        $run->appendLog('Penukaran berkas selesai ('.count($done).' item).');
    }

    /**
     * Kembalikan seluruh item yang sudah tertukar ke keadaan semula.
     *
     * @param  list<array{name: string, directory: bool}>  $done
     */
    public function rollback(UpdateRun $run, array $done): void
    {
        $backup = $this->workspace($run).'/backup';

        foreach (array_reverse($done) as $entry) {
            $live = $this->root.'/'.$entry['name'];
            $saved = $backup.'/'.$entry['name'];

            try {
                if ($entry['directory']) {
                    if (File::isDirectory($live)) {
                        File::deleteDirectory($live);
                    }
                    if (File::isDirectory($saved)) {
                        File::moveDirectory($saved, $live);
                    }
                } else {
                    if (is_file($live)) {
                        File::delete($live);
                    }
                    if (is_file($saved)) {
                        File::move($saved, $live);
                    }
                }
            } catch (Throwable $e) {
                // Rollback yang gagal sebagian jauh lebih buruk daripada gagal seluruhnya tanpa
                // jejak: catat item yang tidak bisa dikembalikan supaya ada yang bisa dibereskan
                // manusia, lalu teruskan mengembalikan sisanya.
                $run->appendLog('GAGAL mengembalikan "'.$entry['name'].'": '.$e->getMessage());
            }
        }

        $run->appendLog('Pengembalian berkas lama selesai.');
    }

    /**
     * Setiap pemindahan diperiksa NILAI KEMBALIANNYA, bukan diandalkan melempar exception:
     * File::move() dan File::moveDirectory() mengembalikan false saat gagal dan tidak pernah
     * throw. Tanpa pemeriksaan ini, penukaran yang gagal akan tampak berhasil, rollback tidak
     * pernah terpicu, dan aplikasi ditinggalkan dalam keadaan setengah tertukar.
     */
    private function swapEntry(string $name, string $source, string $backup, bool $isDirectory): void
    {
        $live = $this->root.'/'.$name;
        $saved = $backup.'/'.$name;
        $incoming = $source.'/'.$name;

        if ($isDirectory) {
            if (File::isDirectory($live)) {
                File::ensureDirectoryExists(dirname($saved));

                if (! File::moveDirectory($live, $saved)) {
                    throw new RuntimeException("Gagal memindahkan direktori \"{$name}\" ke backup.");
                }
            }

            if (! File::moveDirectory($incoming, $live)) {
                throw new RuntimeException("Gagal memasang direktori baru \"{$name}\".");
            }

            return;
        }

        if (is_file($live)) {
            File::ensureDirectoryExists(dirname($saved));

            if (! File::move($live, $saved)) {
                throw new RuntimeException("Gagal memindahkan berkas \"{$name}\" ke backup.");
            }
        }

        if (! File::move($incoming, $live)) {
            throw new RuntimeException("Gagal memasang berkas baru \"{$name}\".");
        }
    }

    /**
     * public/storage adalah symlink ke storage/app/public, dan ikut hilang saat public/ diganti.
     * Tanpa dibuat ulang, seluruh berkas unggahan berhenti tampil walau berkasnya masih utuh —
     * kerusakan yang tidak memunculkan error di mana pun. Lihat UpdatePaths::publicStorageLink().
     */
    public function relinkPublicStorage(UpdateRun $run): void
    {
        $link = $this->root.'/'.UpdatePaths::publicStorageLink();

        if (file_exists($link) || is_link($link)) {
            return;
        }

        $target = $this->root.'/storage/app/public';

        if (! File::isDirectory($target)) {
            return;
        }

        try {
            symlink($target, $link);
            $run->appendLog('Symlink public/storage dibuat ulang.');
        } catch (Throwable $e) {
            $run->appendLog('PERINGATAN: gagal membuat ulang symlink public/storage: '.$e->getMessage()
                .' Jalankan "php artisan storage:link" secara manual.');
        }
    }

    public function cleanup(UpdateRun $run): void
    {
        $workspace = $this->workspace($run);

        if (File::isDirectory($workspace)) {
            File::deleteDirectory($workspace);
        }
    }
}
