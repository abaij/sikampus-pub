<?php

use App\Models\UpdateRun;
use App\Services\Update\ArchiveUpdater;
use Illuminate\Support\Facades\File;

/**
 * Penukaran berkas adalah satu-satunya langkah pembaruan yang bisa merusak instalasi kampus.
 * Semua test di sini bekerja pada direktori sementara, bukan pada instalasi yang sedang
 * berjalan — justru itu yang membuat perilaku rollback bisa diuji sama sekali.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/sikampus-swap-'.uniqid();

    // Instalasi "kampus" yang sudah berjalan, lengkap dengan berkas miliknya sendiri.
    File::ensureDirectoryExists($this->root.'/app/Models');
    File::ensureDirectoryExists($this->root.'/vendor');
    File::ensureDirectoryExists($this->root.'/storage/app/public');
    File::ensureDirectoryExists($this->root.'/plugins/absensi-qr');
    File::put($this->root.'/app/Models/Mahasiswa.php', 'LAMA');
    File::put($this->root.'/vendor/autoload.php', 'LAMA');
    File::put($this->root.'/artisan', 'LAMA');
    File::put($this->root.'/VERSION', '1.0.0');
    File::put($this->root.'/.env', 'APP_KEY=rahasia');
    File::put($this->root.'/storage/app/public/foto.jpg', 'unggahan kampus');
    File::put($this->root.'/plugins/absensi-qr/plugin.json', '{}');

    $this->run = UpdateRun::create([
        'version_from' => '1.0.0',
        'version_to' => '1.1.0',
        'path' => UpdateRun::PATH_ARCHIVE,
        'status' => UpdateRun::STATUS_RUNNING,
        'step' => 'swap',
    ]);

    // Isi rilis baru, ditaruh persis di tempat yang dibaca sourceDirectory().
    $this->source = storage_path('app/updates/run-'.$this->run->id.'/extracted/sikampus-1.1.0');
    File::ensureDirectoryExists($this->source.'/app/Models');
    File::ensureDirectoryExists($this->source.'/vendor');
    File::ensureDirectoryExists($this->source.'/public');
    File::put($this->source.'/app/Models/Mahasiswa.php', 'BARU');
    File::put($this->source.'/vendor/autoload.php', 'BARU');
    File::put($this->source.'/artisan', 'BARU');
    File::put($this->source.'/VERSION', '1.1.0');

    $this->updater = app(ArchiveUpdater::class)->forRoot($this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory(storage_path('app/updates/run-'.$this->run->id));
});

it('replaces application files with the new version', function () {
    $this->updater->swap($this->run);

    expect(File::get($this->root.'/app/Models/Mahasiswa.php'))->toBe('BARU');
    expect(File::get($this->root.'/vendor/autoload.php'))->toBe('BARU');
    expect(File::get($this->root.'/artisan'))->toBe('BARU');
    expect(File::get($this->root.'/VERSION'))->toBe('1.1.0');
});

// Ketiganya milik instalasi, bukan bagian rilis. Menghapus salah satunya berarti kehilangan
// data kampus — kredensial, unggahan, atau plugin berbayar yang mereka pasang sendiri.
it('never touches .env, storage, or plugins', function () {
    $this->updater->swap($this->run);

    expect(File::get($this->root.'/.env'))->toBe('APP_KEY=rahasia');
    expect(File::get($this->root.'/storage/app/public/foto.jpg'))->toBe('unggahan kampus');
    expect(File::get($this->root.'/plugins/absensi-qr/plugin.json'))->toBe('{}');
});

it('keeps the previous version in the backup directory', function () {
    $this->updater->swap($this->run);

    $backup = $this->updater->workspace($this->run).'/backup';

    expect(File::get($backup.'/app/Models/Mahasiswa.php'))->toBe('LAMA');
    expect(File::get($backup.'/VERSION'))->toBe('1.0.0');
});

// Berkas yang dihapus di versi baru harus benar-benar hilang. Kalau direktori hanya DITIMPA
// per berkas, sisa seperti ini tertinggal dan bisa mengubah perilaku aplikasi diam-diam.
it('removes files that no longer exist in the new version', function () {
    File::put($this->root.'/app/Models/Usang.php', 'harus hilang');

    $this->updater->swap($this->run);

    expect(is_file($this->root.'/app/Models/Usang.php'))->toBeFalse();
});

// Inti keamanan seluruh fase ini: kalau penukaran gagal di tengah, instalasi harus kembali
// persis seperti semula — bukan tertinggal setengah tertukar.
it('rolls everything back when a swap fails midway', function () {
    // artisan ada di daftar SETELAH direktori, jadi direktori sudah tertukar saat ini gagal.
    // Direktori (bukan berkas) di posisi live membuat rename gagal.
    File::deleteDirectory($this->root.'/artisan');
    File::delete($this->root.'/artisan');
    File::ensureDirectoryExists($this->root.'/artisan/menghalangi');

    expect(fn () => $this->updater->swap($this->run))
        ->toThrow(RuntimeException::class);

    // Semua yang sempat tertukar dikembalikan ke isi lamanya.
    expect(File::get($this->root.'/app/Models/Mahasiswa.php'))->toBe('LAMA');
    expect(File::get($this->root.'/vendor/autoload.php'))->toBe('LAMA');
    expect(File::get($this->root.'/VERSION'))->toBe('1.0.0');
    expect(File::get($this->root.'/.env'))->toBe('APP_KEY=rahasia');
    expect(File::get($this->root.'/storage/app/public/foto.jpg'))->toBe('unggahan kampus');
});

it('recreates the public/storage symlink that the swap destroys', function () {
    $this->updater->swap($this->run);

    expect(is_link($this->root.'/public/storage'))->toBeFalse();

    $this->updater->relinkPublicStorage($this->run);

    expect(is_link($this->root.'/public/storage'))->toBeTrue();
    expect(File::get($this->root.'/public/storage/foto.jpg'))->toBe('unggahan kampus');
});
