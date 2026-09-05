<?php

/*
|--------------------------------------------------------------------------
| Identitas Rilis Sikampus
|--------------------------------------------------------------------------
|
| Sumber kebenaran versi aplikasi adalah berkas VERSION di root project. Satu berkas
| itu melayani KEDUA cara instalasi tanpa mekanisme terpisah: instalasi hasil klon Git
| mendapatkannya lewat `git pull` (VERSION ikut di-commit saat tagging), dan instalasi
| dari unduhan siap pakai mendapatkannya dari isi zip (ditulis scripts/build-release.sh).
|
| Dibaca SEKALI di sini, bukan lewat helper yang dipanggil di banyak tempat, supaya
| `config:cache` bisa membekukannya persis seperti nilai config lain — tidak ada
| pembacaan berkas per request di server yang meng-cache config.
|
| Nilai "dev" muncul kalau VERSION tidak ada. Itu keadaan normal untuk checkout kerja
| pengembang, dan sengaja TIDAK dianggap error: tidak ada satu pun fitur yang boleh mati
| hanya karena penanda versi belum ada.
|
| Catatan untuk checkout Git di antara dua rilis: VERSION berisi versi rilis TERAKHIR,
| bukan "sedikit lebih baru dari itu". Jadi instalasi yang mengikuti branch main bisa
| melaporkan versi yang sedikit tertinggal dari kode yang benar-benar terpasang — itu
| konsekuensi yang diterima, bukan bug.
|
*/

$versionFile = dirname(__DIR__).'/VERSION';

$version = is_file($versionFile)
    ? trim((string) file_get_contents($versionFile))
    : '';

return [

    'version' => $version !== '' ? $version : 'dev',

    /*
    |--------------------------------------------------------------------------
    | Cek Pembaruan
    |--------------------------------------------------------------------------
    |
    | Rilis diambil dari DUA sumber dengan peran berbeda: Sikampus Server (portal) sebagai
    | pemberi tahu, GitHub Releases sebagai penyedia artefak. Portal dicoba lebih dulu kalau
    | SIKAMPUS_SERVER_URL terisi — lewat sana instalasi ini sekalian melaporkan versinya,
    | sehingga daftar "siapa tertinggal versi" di portal ikut segar tanpa mekanisme terpisah.
    | Kalau portal kosong atau tidak bisa dihubungi, GitHub dipakai langsung: instalasi
    | self-hosted yang tidak pernah mengisi license key TETAP harus bisa tahu ada versi baru.
    |
    | Semua kegagalan jaringan di sini bersifat "tidak tahu", bukan error — halaman pembaruan
    | tidak boleh rusak hanya karena GitHub sedang tidak bisa dihubungi.
    |
    */

    'update' => [

        // Matikan kalau kampus tidak ingin instalasinya menghubungi internet sama sekali.
        'enabled' => filter_var(env('SIKAMPUS_UPDATE_CHECK', true), FILTER_VALIDATE_BOOLEAN),

        'github_repo' => env('SIKAMPUS_UPDATE_GITHUB_REPO', 'sikampus-dev/sikampus-pub'),

        // Hasil pengecekan di-cache supaya membuka halaman berkali-kali tidak menembak API
        // GitHub setiap kali — API publik mereka dibatasi per IP, dan satu server bisa
        // menampung banyak pengguna panel.
        'cache_minutes' => (int) env('SIKAMPUS_UPDATE_CACHE_MINUTES', 60),

        // Sengaja pendek: halaman ini harus tetap terbuka cepat walau sumber rilis lambat.
        'timeout' => (int) env('SIKAMPUS_UPDATE_TIMEOUT', 8),

    ],

];
