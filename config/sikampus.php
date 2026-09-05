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

];
