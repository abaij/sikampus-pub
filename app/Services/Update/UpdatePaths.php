<?php

namespace App\Services\Update;

/**
 * Daftar tunggal apa yang DIGANTI dan apa yang DIPERTAHANKAN saat pembaruan.
 *
 * Dipakai bersama oleh penukar direktori dan pendeteksi perubahan lokal — kalau keduanya
 * memakai daftar sendiri-sendiri, mereka akan berbeda pendapat suatu hari, dan bedanya baru
 * ketahuan setelah ada instalasi kampus yang kehilangan berkas.
 */
class UpdatePaths
{
    /**
     * Direktori yang isinya diganti UTUH (dipindahkan ke backup, lalu diganti versi baru).
     *
     * Diganti utuh, bukan ditimpa per berkas, karena menimpa tidak pernah menghapus berkas yang
     * sudah dihapus di versi baru — sisa seperti itu bisa mengubah perilaku aplikasi dengan cara
     * yang sangat sulit dilacak (mis. route atau migration lama yang masih ikut terbaca).
     *
     * @return list<string>
     */
    public static function directories(): array
    {
        return ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor'];
    }

    /**
     * Berkas di akar project yang ikut diganti.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        return [
            'artisan',
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'vite.config.js',
            'VERSION',
            'sikampus-manifest.json',
        ];
    }

    /**
     * Milik instalasi, BUKAN bagian rilis — tidak pernah disentuh pembaruan.
     *
     * storage/ berisi unggahan, sesi, dan plugin terpasang; plugins/ berisi plugin yang dipasang
     * di akar; .env berisi kredensial. Menghapus salah satunya berarti kehilangan data kampus,
     * bukan sekadar rollback yang merepotkan.
     *
     * @return list<string>
     */
    public static function preserved(): array
    {
        return ['.env', 'storage', 'plugins', '.git', 'node_modules', 'dist'];
    }

    /**
     * public/storage adalah SYMLINK ke storage/app/public. Direktori public/ diganti utuh, jadi
     * symlink itu ikut hilang bersamanya dan harus dibuat ulang setelah penukaran — kalau tidak,
     * seluruh berkas unggahan (foto mahasiswa, logo, lampiran) berhenti tampil walau berkasnya
     * masih utuh di storage/. Kegagalan seperti ini tidak memunculkan error di mana pun.
     */
    public static function publicStorageLink(): string
    {
        return 'public/storage';
    }
}
