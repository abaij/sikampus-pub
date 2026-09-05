<?php

namespace App\Services\Update;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ambil rilis Sikampus terbaru dari Sikampus Server, dengan GitHub Releases sebagai cadangan.
 * Lihat blok 'update' di config/sikampus.php untuk alasan pembagian peran kedua sumber itu.
 *
 * TIDAK PERNAH melempar exception. Gagal menghubungi sumber rilis adalah keadaan "tidak tahu
 * versi terbaru", bukan kesalahan yang boleh merusak halaman — dan halaman ini justru yang
 * dibuka orang ketika ada yang tidak beres dengan instalasinya.
 */
class ReleaseChecker
{
    private const CACHE_KEY = 'sikampus.update.latest-release';

    /**
     * @return array{release: ?Release, source: ?string, error: ?string}
     */
    public function latest(bool $force = false): array
    {
        if (! config('sikampus.update.enabled')) {
            return $this->result(null, null, 'Pengecekan pembaruan dimatikan lewat konfigurasi (SIKAMPUS_UPDATE_CHECK).');
        }

        if ($force) {
            Cache::forget(self::CACHE_KEY);
        }

        // Hasil GAGAL ikut di-cache, bukan cuma yang berhasil. Tanpa itu, sumber rilis yang
        // sedang mati membuat setiap pembukaan halaman menunggu timeout penuh lagi.
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $this->hydrate($cached);
        }

        $payload = $this->fetchFromPortal() ?? $this->fetchFromGithub();

        if ($payload === null) {
            $payload = ['error' => 'Tidak bisa menghubungi sumber rilis. Periksa koneksi internet server, lalu coba lagi.'];
        }

        Cache::put(self::CACHE_KEY, $payload, now()->addMinutes((int) config('sikampus.update.cache_minutes')));

        return $this->hydrate($payload);
    }

    /**
     * Portal dicoba lebih dulu HANYA kalau alamatnya terisi. License key ikut dikirim bila ada
     * supaya portal bisa mencatat versi instalasi ini — itu satu-satunya alasan jalur portal
     * ada, karena artefaknya sendiri tetap dari GitHub.
     */
    private function fetchFromPortal(): ?array
    {
        $url = trim((string) config('sikampus_server.url'));

        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('sikampus.update.timeout'))
                ->acceptJson()
                ->get(rtrim($url, '/').'/api/releases/latest', array_filter([
                    'license_key' => $this->licenseKey(),
                    'app_version' => config('sikampus.version'),
                ]));

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            if (! is_array($data) || ! filled($data['version'] ?? null)) {
                return null;
            }

            return [
                'source' => 'portal',
                'version' => (string) $data['version'],
                'name' => $data['name'] ?? null,
                'changelog' => $data['changelog'] ?? null,
                'published_at' => $data['published_at'] ?? null,
                'html_url' => $data['html_url'] ?? null,
                'download_url' => $data['download_url'] ?? null,
                'checksum_url' => $data['checksum_url'] ?? null,
                'manifest_url' => $data['manifest_url'] ?? null,
            ];
        } catch (Throwable $e) {
            Log::info('Gagal mengambil rilis dari Sikampus Server, jatuh ke GitHub.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    private function fetchFromGithub(): ?array
    {
        $repo = trim((string) config('sikampus.update.github_repo'));

        if ($repo === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('sikampus.update.timeout'))
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            if (! is_array($data) || ! filled($data['tag_name'] ?? null)) {
                return null;
            }

            $assets = is_array($data['assets'] ?? null) ? $data['assets'] : [];

            return [
                'source' => 'github',
                'version' => (string) $data['tag_name'],
                'name' => $data['name'] ?? null,
                'changelog' => $data['body'] ?? null,
                'published_at' => $data['published_at'] ?? null,
                'html_url' => $data['html_url'] ?? null,
                // Pencocokan aset memakai akhiran, bukan "mengandung": nama
                // sikampus-1.0.0.zip.sha256 TIDAK berakhiran .zip, jadi ketiga baris di bawah
                // tidak bisa saling merebut aset walau namanya beririsan.
                'download_url' => $this->findAsset($assets, '.zip'),
                'checksum_url' => $this->findAsset($assets, '.zip.sha256'),
                'manifest_url' => $this->findAsset($assets, '.manifest.json'),
            ];
        } catch (Throwable $e) {
            Log::info('Gagal mengambil rilis dari GitHub.', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     */
    private function findAsset(array $assets, string $suffix): ?string
    {
        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '');

            // ".zip" tidak boleh cocok dengan "....zip.sha256": aset yang diminta adalah yang
            // BERAKHIR dengan akhiran itu, bukan yang sekadar mengandungnya.
            if ($name !== '' && str_ends_with($name, $suffix)) {
                return $asset['browser_download_url'] ?? null;
            }
        }

        return null;
    }

    private function licenseKey(): ?string
    {
        try {
            return Setting::where('key', 'app_license_key')->value('value') ?: null;
        } catch (Throwable) {
            // Tabel settings belum ada (mis. sebelum migrate pertama) — bukan alasan gagal.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{release: ?Release, source: ?string, error: ?string}
     */
    private function hydrate(array $payload): array
    {
        if (filled($payload['error'] ?? null)) {
            return $this->result(null, null, (string) $payload['error']);
        }

        $publishedAt = null;

        if (filled($payload['published_at'] ?? null)) {
            try {
                $publishedAt = Carbon::parse($payload['published_at']);
            } catch (Throwable) {
                $publishedAt = null;
            }
        }

        return $this->result(new Release(
            version: (string) $payload['version'],
            name: $payload['name'] ?? null,
            changelog: $payload['changelog'] ?? null,
            publishedAt: $publishedAt,
            htmlUrl: $payload['html_url'] ?? null,
            downloadUrl: $payload['download_url'] ?? null,
            checksumUrl: $payload['checksum_url'] ?? null,
            manifestUrl: $payload['manifest_url'] ?? null,
            source: $payload['source'] ?? null,
        ), $payload['source'] ?? null, null);
    }

    /**
     * @return array{release: ?Release, source: ?string, error: ?string}
     */
    private function result(?Release $release, ?string $source, ?string $error): array
    {
        return ['release' => $release, 'source' => $source, 'error' => $error];
    }
}
