<?php

use App\Models\UpdateRun;
use App\Services\Update\InstallationInspector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'sikampus.version' => '1.0.0',
        'sikampus.update.enabled' => true,
        'sikampus_server.url' => '',
    ]);
});

function fakeRelease(string $tag = 'v1.2.0'): void
{
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => $tag,
        'assets' => [['name' => 's.zip', 'browser_download_url' => 'https://example.test/s.zip']],
    ])]);
}

it('reports when the installation is already up to date', function () {
    fakeRelease('v1.0.0');

    $this->artisan('sikampus:update', ['--yes' => true])
        ->expectsOutputToContain('Tidak ada pembaruan')
        ->assertSuccessful();

    expect(UpdateRun::count())->toBe(0);
});

it('refuses to run on a cloud-managed installation', function () {
    fakeRelease();
    $this->mock(InstallationInspector::class, function ($mock) {
        $mock->shouldReceive('type')->andReturn(InstallationInspector::TYPE_MANAGED);
    });

    $this->artisan('sikampus:update', ['--yes' => true])
        ->expectsOutputToContain('dikelola Sikampus Cloud')
        ->assertFailed();
});

it('refuses to run when the application directory is not writable', function () {
    fakeRelease();
    $this->mock(InstallationInspector::class, function ($mock) {
        $mock->shouldReceive('type')->andReturn(InstallationInspector::TYPE_ARCHIVE);
        $mock->shouldReceive('isFullyWritable')->andReturn(false);
        $mock->shouldReceive('writablePaths')->andReturn(['app' => false, 'vendor' => true]);
    });

    $this->artisan('sikampus:update', ['--yes' => true])
        ->expectsOutputToContain('tidak bisa ditulis')
        ->assertFailed();
});

// Inti dari perintah ini: melanjutkan pembaruan yang sudah ada, bukan memulai yang baru.
// Memulai yang baru akan mengunduh ulang dan meninggalkan direktori kerja bertumpuk.
it('resumes an existing run instead of starting a new one', function () {
    fakeRelease();

    $run = UpdateRun::create([
        'version_from' => '1.0.0',
        'version_to' => 'v1.2.0',
        'path' => UpdateRun::PATH_ARCHIVE,
        'status' => UpdateRun::STATUS_RUNNING,
        'step' => 'download',
    ]);

    // Unduhan akan gagal (URL palsu), tapi yang diuji di sini adalah bahwa perintah MENERUSKAN
    // run yang ada — bukan hasil langkahnya.
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v1.2.0', 'assets' => []]),
        '*' => Http::response('', 500),
    ]);

    $this->artisan('sikampus:update', ['--yes' => true])
        ->expectsOutputToContain('Melanjutkan pembaruan');

    expect(UpdateRun::count())->toBe(1);
    expect(UpdateRun::sole()->id)->toBe($run->id);
});
