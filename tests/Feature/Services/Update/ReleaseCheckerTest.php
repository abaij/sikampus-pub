<?php

use App\Models\Setting;
use App\Services\Update\Release;
use App\Services\Update\ReleaseChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'sikampus.update.enabled' => true,
        'sikampus.update.github_repo' => 'sikampus-dev/sikampus-pub',
        'sikampus_server.url' => '',
    ]);
});

function githubReleasePayload(array $overrides = []): array
{
    return array_merge([
        'tag_name' => 'v1.2.0',
        'name' => 'Sikampus v 1.2.0',
        'body' => '**Full Changelog**: https://example.test/compare',
        'published_at' => '2026-09-05T08:17:55Z',
        'html_url' => 'https://github.com/sikampus-dev/sikampus-pub/releases/tag/v1.2.0',
        'assets' => [
            ['name' => 'sikampus-1.2.0.zip.sha256', 'browser_download_url' => 'https://example.test/z.sha256'],
            ['name' => 'sikampus-1.2.0.manifest.json', 'browser_download_url' => 'https://example.test/m.json'],
            ['name' => 'sikampus-1.2.0.zip', 'browser_download_url' => 'https://example.test/z.zip'],
        ],
    ], $overrides);
}

it('falls back to github when no portal url is configured', function () {
    Http::fake(['api.github.com/*' => Http::response(githubReleasePayload())]);

    $result = app(ReleaseChecker::class)->latest();

    expect($result['error'])->toBeNull();
    expect($result['source'])->toBe('github');
    expect($result['release']->version)->toBe('v1.2.0');
    expect($result['release']->changelog)->toContain('Full Changelog');
});

// Aset .zip dan .zip.sha256 punya nama yang beririsan, dan yang .sha256 sengaja ditaruh lebih
// dulu di payload uji — kalau pencocokan memakai "mengandung" alih-alih "berakhiran", zip-nya
// akan tertukar dengan berkas checksum dan updater mengunduh berkas 65 byte sebagai artefak.
it('matches release assets by suffix so the checksum file is never mistaken for the zip', function () {
    Http::fake(['api.github.com/*' => Http::response(githubReleasePayload())]);

    $release = app(ReleaseChecker::class)->latest()['release'];

    expect($release->downloadUrl)->toBe('https://example.test/z.zip');
    expect($release->checksumUrl)->toBe('https://example.test/z.sha256');
    expect($release->manifestUrl)->toBe('https://example.test/m.json');
});

it('prefers the portal and sends the license key so the portal can record this version', function () {
    config(['sikampus_server.url' => 'https://app.sikampus.example']);
    Setting::create(['key' => 'app_license_key', 'value' => 'KEY-0001']);

    Http::fake([
        'app.sikampus.example/*' => Http::response([
            'version' => '2.0.0',
            'name' => 'Sikampus 2.0.0',
            'download_url' => 'https://example.test/portal.zip',
        ]),
    ]);

    $result = app(ReleaseChecker::class)->latest();

    expect($result['source'])->toBe('portal');
    expect($result['release']->version)->toBe('2.0.0');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'license_key=KEY-0001'));
});

it('falls back to github when the portal cannot be reached', function () {
    config(['sikampus_server.url' => 'https://app.sikampus.example']);

    Http::fake([
        'app.sikampus.example/*' => Http::response('', 500),
        'api.github.com/*' => Http::response(githubReleasePayload()),
    ]);

    expect(app(ReleaseChecker::class)->latest()['source'])->toBe('github');
});

// Halaman pembaruan justru dibuka orang ketika ada yang tidak beres. Sumber rilis yang mati
// harus jadi pesan, bukan exception yang membuat halamannya ikut mati.
it('reports an unreachable release source as a message instead of throwing', function () {
    Http::fake(['*' => Http::response('', 503)]);

    $result = app(ReleaseChecker::class)->latest();

    expect($result['release'])->toBeNull();
    expect($result['error'])->toContain('Tidak bisa menghubungi sumber rilis');
});

// Tanpa ini, sumber rilis yang mati membuat SETIAP pembukaan halaman menunggu timeout penuh.
it('caches failures too, not just successes', function () {
    Http::fake(['*' => Http::response('', 503)]);

    app(ReleaseChecker::class)->latest();
    app(ReleaseChecker::class)->latest();

    Http::assertSentCount(1);
});

it('does not contact anything when update checking is disabled', function () {
    config(['sikampus.update.enabled' => false]);
    Http::fake();

    $result = app(ReleaseChecker::class)->latest();

    expect($result['error'])->toContain('dimatikan');
    Http::assertNothingSent();
});

it('compares versions ignoring the git tag v prefix', function () {
    $release = new Release(version: 'v1.2.0');

    expect($release->isNewerThan('1.0.0'))->toBeTrue();
    expect($release->isNewerThan('1.2.0'))->toBeFalse();
    expect($release->isNewerThan('2.0.0'))->toBeFalse();
});

// "dev" adalah versi checkout pengembang. Menjawab "sudah terbaru" untuk itu sama
// menyesatkannya dengan menjawab "ada update" — jawabannya memang tidak diketahui.
it('returns null rather than a verdict when either version is not comparable', function () {
    $release = new Release(version: 'v1.2.0');

    expect($release->isNewerThan('dev'))->toBeNull();
    expect((new Release(version: 'nightly'))->isNewerThan('1.0.0'))->toBeNull();
});
