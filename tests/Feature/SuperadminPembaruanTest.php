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

    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.2.0',
        'name' => 'Sikampus v1.2.0',
        'body' => 'Catatan.',
        'assets' => [
            ['name' => 'sikampus-1.2.0.zip', 'browser_download_url' => 'https://example.test/z.zip'],
            ['name' => 'sikampus-1.2.0.zip.sha256', 'browser_download_url' => 'https://example.test/z.sha256'],
        ],
    ])]);
});

it('is reachable only by superadmin', function () {
    $this->get(route('superadmin.pembaruan'))->assertRedirect(route('login'));
    $this->actingAs(adminUser('admin_akademik'))
        ->get(route('superadmin.pembaruan'))
        ->assertRedirect(route('login'));
});

it('offers the update when a newer release exists', function () {
    $this->actingAs(adminUser())
        ->get(route('superadmin.pembaruan'))
        ->assertOk()
        ->assertSee('Mulai perbarui ke v1.2.0')
        ->assertSee('Backup database Anda sekarang');
});

// Konfirmasi backup adalah gerbang keras, bukan hiasan: isi database tidak bisa dikembalikan
// otomatis oleh rollback mana pun yang kita punya.
it('refuses to start without the backup confirmation', function () {
    $this->actingAs(adminUser())
        ->post(route('superadmin.pembaruan.mulai'), [])
        ->assertSessionHasErrors('confirm');

    expect(UpdateRun::count())->toBe(0);
});

it('refuses a second update while one is still running', function () {
    UpdateRun::create([
        'version_from' => '1.0.0', 'version_to' => '1.1.0',
        'path' => UpdateRun::PATH_ARCHIVE, 'status' => UpdateRun::STATUS_RUNNING, 'step' => 'download',
    ]);

    $this->actingAs(adminUser())
        ->post(route('superadmin.pembaruan.mulai'), ['confirm' => '1'])
        ->assertSessionHas('error');

    expect(UpdateRun::count())->toBe(1);
});

it('creates a run and picks a path when started', function () {
    $this->actingAs(adminUser())
        ->post(route('superadmin.pembaruan.mulai'), ['confirm' => '1'])
        ->assertRedirect(route('superadmin.pembaruan'));

    $run = UpdateRun::sole();
    expect($run->version_to)->toBe('v1.2.0');
    expect($run->status)->toBe(UpdateRun::STATUS_RUNNING);
    expect($run->step)->toBe(UpdateRun::STEPS[$run->path][0]);
    expect($run->path)->toBeIn([UpdateRun::PATH_ARCHIVE, UpdateRun::PATH_GIT]);
});

// Setelah berkas hidup mulai ditukar, "batal" berarti meninggalkan instalasi setengah jadi.
it('refuses to cancel once the swap has begun', function () {
    UpdateRun::create([
        'version_from' => '1.0.0', 'version_to' => '1.2.0',
        'path' => UpdateRun::PATH_ARCHIVE, 'status' => UpdateRun::STATUS_RUNNING, 'step' => 'swap',
    ]);

    $this->actingAs(adminUser())
        ->post(route('superadmin.pembaruan.batal'))
        ->assertSessionHas('error');

    expect(UpdateRun::sole()->status)->toBe(UpdateRun::STATUS_RUNNING);
});

it('allows cancelling before anything has been touched', function () {
    UpdateRun::create([
        'version_from' => '1.0.0', 'version_to' => '1.2.0',
        'path' => UpdateRun::PATH_ARCHIVE, 'status' => UpdateRun::STATUS_RUNNING, 'step' => 'download',
    ]);

    $this->actingAs(adminUser())
        ->post(route('superadmin.pembaruan.batal'))
        ->assertSessionHas('status');

    expect(UpdateRun::sole()->status)->toBe(UpdateRun::STATUS_FAILED);
});

it('refuses to update a cloud-managed installation', function () {
    $this->mock(InstallationInspector::class, function ($mock) {
        $mock->shouldReceive('type')->andReturn(InstallationInspector::TYPE_MANAGED);
        $mock->shouldReceive('typeLabel')->andReturn('Sikampus Cloud (dikelola)');
        $mock->shouldReceive('writablePaths')->andReturn([]);
        $mock->shouldReceive('isFullyWritable')->andReturn(true);
        $mock->shouldReceive('canUseGitPath')->andReturn(false);
    });

    $this->actingAs(adminUser())
        ->post(route('superadmin.pembaruan.mulai'), ['confirm' => '1'])
        ->assertSessionHas('error');

    expect(UpdateRun::count())->toBe(0);
});

it('refuses to update when the application directory is not writable', function () {
    $this->mock(InstallationInspector::class, function ($mock) {
        $mock->shouldReceive('type')->andReturn(InstallationInspector::TYPE_ARCHIVE);
        $mock->shouldReceive('isFullyWritable')->andReturn(false);
    });

    $this->actingAs(adminUser())
        ->post(route('superadmin.pembaruan.mulai'), ['confirm' => '1'])
        ->assertSessionHas('error');

    expect(UpdateRun::count())->toBe(0);
});
