<?php

use App\Livewire\Admin\Sistem\Pembaruan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    config([
        'sikampus.update.enabled' => true,
        'sikampus_server.url' => '',
        'sikampus.version' => '1.0.0',
    ]);
});

function fakeLatestRelease(string $tag = 'v1.2.0'): void
{
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => $tag,
        'name' => "Sikampus {$tag}",
        'body' => 'Perbaikan impor perkuliahan.',
        'published_at' => '2026-09-05T08:17:55Z',
        'html_url' => 'https://example.test/release',
        'assets' => [
            ['name' => 'sikampus.zip', 'browser_download_url' => 'https://example.test/z.zip'],
        ],
    ])]);
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.sistem.pembaruan'))->assertRedirect(route('login'));
});

it('forbids an admin who is not superadmin', function () {
    $this->actingAs(adminUser('admin_akademik'))
        ->get(route('admin.sistem.pembaruan'))
        ->assertForbidden();
});

it('shows the installed version and the newer release', function () {
    fakeLatestRelease();

    Livewire::actingAs(adminUser())
        ->test(Pembaruan::class)
        ->assertSee('1.0.0')
        ->assertSee('v1.2.0')
        ->assertSee('Versi baru tersedia');
});

it('says the installation is up to date when versions match', function () {
    fakeLatestRelease('v1.0.0');

    Livewire::actingAs(adminUser())
        ->test(Pembaruan::class)
        ->assertSee('sudah memakai versi terbaru')
        ->assertDontSee('Versi baru tersedia');
});

// Halaman ini justru dibuka ketika ada yang tidak beres, jadi sumber rilis yang mati harus
// menghasilkan halaman yang tetap terbuka dengan penjelasan — bukan 500.
it('still renders with an explanation when the release source is unreachable', function () {
    Http::fake(['*' => Http::response('', 503)]);

    Livewire::actingAs(adminUser())
        ->test(Pembaruan::class)
        ->assertOk()
        ->assertSee('Tidak bisa menghubungi sumber rilis');
});

it('explains that a dev checkout cannot be compared', function () {
    config(['sikampus.version' => 'dev']);
    fakeLatestRelease();

    Livewire::actingAs(adminUser())
        ->test(Pembaruan::class)
        ->assertSee('tidak bisa dibandingkan')
        ->assertDontSee('Versi baru tersedia');
});

// Changelog datang dari body GitHub Release — teks yang ditulis di luar aplikasi ini, jadi
// tidak boleh pernah dirender sebagai HTML.
it('renders the changelog as plain text, never as markup', function () {
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.2.0',
        'body' => '<script>alert(1)</script>',
        'assets' => [],
    ])]);

    Livewire::actingAs(adminUser())
        ->test(Pembaruan::class)
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('<script>alert(1)</script>');
});

it('shows git commands when the installation came from a git clone', function () {
    fakeLatestRelease();

    Livewire::actingAs(adminUser())
        ->test(Pembaruan::class)
        ->assertSee('git pull origin');
})->skip(fn () => ! is_dir(base_path('.git')), 'Hanya relevan pada checkout Git.');
