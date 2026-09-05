<?php

namespace App\Services\Update;

use Illuminate\Support\Carbon;

/**
 * Satu rilis Sikampus, dalam bentuk yang sama entah datang dari Sikampus Server atau dari
 * GitHub Releases. Penormalan itu inti dari kelas ini: pemanggil tidak boleh perlu tahu
 * sumbernya, supaya menambah/menukar sumber rilis nanti tidak menyentuh UI sama sekali.
 */
class Release
{
    public function __construct(
        public readonly string $version,
        public readonly ?string $name = null,
        public readonly ?string $changelog = null,
        public readonly ?Carbon $publishedAt = null,
        public readonly ?string $htmlUrl = null,
        public readonly ?string $downloadUrl = null,
        public readonly ?string $checksumUrl = null,
        public readonly ?string $manifestUrl = null,
        public readonly ?string $source = null,
    ) {}

    /**
     * Bandingkan versi terpasang dengan rilis ini.
     *
     * Mengembalikan null (bukan false) kalau perbandingan TIDAK BISA dipercaya — versi
     * terpasang "dev", atau salah satunya bukan versi yang bisa dibandingkan. Memisahkan
     * "tidak tahu" dari "sudah terbaru" itu penting: keduanya sama-sama berarti tombol update
     * tidak muncul, tapi hanya yang pertama yang layak menampilkan penjelasan ke pengguna.
     */
    public function isNewerThan(string $installedVersion): ?bool
    {
        $installed = static::normalizeVersion($installedVersion);
        $available = static::normalizeVersion($this->version);

        if ($installed === null || $available === null) {
            return null;
        }

        return version_compare($available, $installed, '>');
    }

    /**
     * Buang awalan "v" yang lazim dipakai di tag Git, lalu tolak apa pun yang tidak berbentuk
     * angka bertitik. version_compare() TIDAK pernah gagal — string sembarang tetap
     * menghasilkan jawaban — jadi penyaringan harus dilakukan di sini, bukan diandalkan padanya.
     */
    public static function normalizeVersion(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');

        return preg_match('/^\d+(\.\d+)*([-+].+)?$/', $version) === 1 ? $version : null;
    }
}
