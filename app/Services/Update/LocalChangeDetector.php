<?php

namespace App\Services\Update;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cari berkas aplikasi yang DIUBAH atau DIHAPUS secara lokal oleh kampus, dengan membandingkan
 * instalasi terhadap manifest sha256 milik versi yang sedang terpasang.
 *
 * KENAPA PENTING: pembaruan mengganti direktori secara utuh. Kampus yang pernah menyesuaikan
 * satu berkas — mengubah template cetak, menambal perhitungan nilai — akan kehilangan
 * penyesuaian itu tanpa pernah diberi tahu. Deteksi ini yang membuat updater bisa berhenti dan
 * bertanya lebih dulu, bukan menghapus diam-diam lalu menyisakan orang yang kebingungan
 * mencari fitur yang "tiba-tiba hilang".
 *
 * Berkas TAMBAHAN yang dibuat kampus sengaja TIDAK dilaporkan sebagai penghalang: berkas yang
 * tidak dikenal manifest berada di direktori yang akan diganti, jadi memang akan hilang — tapi
 * melaporkannya bersama modifikasi akan menenggelamkan yang benar-benar penting di antara
 * berkas cache dan sampah editor yang tidak berarti apa-apa.
 */
class LocalChangeDetector
{
    /**
     * @return array{available: bool, reason: ?string, modified: list<string>, missing: list<string>}
     */
    public function detect(): array
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return [
                'available' => false,
                'reason' => 'Manifest versi terpasang tidak ditemukan, jadi perubahan lokal tidak bisa diperiksa.',
                'modified' => [],
                'missing' => [],
            ];
        }

        $modified = [];
        $missing = [];

        foreach ($manifest as $relative => $hash) {
            if (! $this->isReplaceable($relative)) {
                continue;
            }

            $path = base_path($relative);

            if (! is_file($path)) {
                $missing[] = $relative;

                continue;
            }

            if (hash_file('sha256', $path) !== $hash) {
                $modified[] = $relative;
            }
        }

        return [
            'available' => true,
            'reason' => null,
            'modified' => $modified,
            'missing' => $missing,
        ];
    }

    /**
     * Manifest hanya mencakup berkas yang memang akan diganti. Berkas di luar itu (mis. yang
     * pernah ada di rilis lama tapi kini berada di direktori yang dipertahankan) tidak relevan
     * untuk keputusan "apakah aman menimpa".
     */
    private function isReplaceable(string $relative): bool
    {
        if (in_array($relative, UpdatePaths::files(), true)) {
            return true;
        }

        foreach (UpdatePaths::directories() as $dir) {
            if (str_starts_with($relative, $dir.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manifest versi yang SEDANG terpasang.
     *
     * Sumber pertama berkas lokal yang ikut di dalam zip rilis. Instalasi hasil klon Git tidak
     * pernah punya berkas itu (dibuat saat build, tidak di-commit), jadi sumber kedua adalah
     * aset manifest pada rilis dengan tag yang sama di GitHub. Tanpa jalur kedua, deteksi ini
     * mati justru untuk instalasi Git — yang paling mungkin memodifikasi kode.
     *
     * @return array<string, string>|null
     */
    private function manifest(): ?array
    {
        $local = base_path('sikampus-manifest.json');

        if (is_file($local)) {
            return $this->parse(file_get_contents($local) ?: '');
        }

        return $this->downloadManifestForInstalledVersion();
    }

    /**
     * @return array<string, string>|null
     */
    private function downloadManifestForInstalledVersion(): ?array
    {
        $version = (string) config('sikampus.version');
        $repo = trim((string) config('sikampus.update.github_repo'));

        if ($version === 'dev' || $version === '' || $repo === '') {
            return null;
        }

        try {
            $timeout = (int) config('sikampus.update.timeout');

            $release = Http::timeout($timeout)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}/releases/tags/v".ltrim($version, 'vV'));

            if (! $release->successful()) {
                return null;
            }

            foreach ($release->json('assets') ?? [] as $asset) {
                if (str_ends_with((string) ($asset['name'] ?? ''), '.manifest.json')) {
                    $body = Http::timeout($timeout)->get($asset['browser_download_url']);

                    return $body->successful() ? $this->parse($body->body()) : null;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function parse(string $json): ?array
    {
        $data = json_decode($json, true);

        return is_array($data['files'] ?? null) ? $data['files'] : null;
    }
}
