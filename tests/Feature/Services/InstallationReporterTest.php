<?php

use App\Services\InstallationReporter;
use Illuminate\Support\Facades\Http;

it('sends the installed app version to the sikampus server', function () {
    config([
        'sikampus_server.url' => 'https://sikampus.example.com',
        'sikampus.version' => '1.2.3',
    ]);
    Http::fake();

    app(InstallationReporter::class)->report('LICENSE-KEY-0001');

    Http::assertSent(fn ($request) => $request['app_version'] === '1.2.3');
});

// Versi yang dilaporkan ke Sikampus Server dipakai untuk memutuskan instalasi mana yang
// ketinggalan rilis. Kalau config dan berkas VERSION sampai berbeda, keputusan itu diambil
// dari angka yang salah tanpa ada yang gagal — jadi keterikatan keduanya diuji eksplisit.
it('reads the version from the VERSION file at the project root', function () {
    $versionFile = base_path('VERSION');

    expect($versionFile)->toBeReadableFile();
    expect(config('sikampus.version'))->toBe(trim(file_get_contents($versionFile)));
});
