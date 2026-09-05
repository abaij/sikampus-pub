<?php

/*
 * Susun manifest sha256 untuk sebuah artefak rilis. Dipanggil scripts/build-release.sh;
 * bukan untuk dijalankan sendiri saat pengembangan biasa.
 *
 * Manifest ini yang nanti membuat updater bisa menjawab tiga pertanyaan yang tidak bisa
 * dijawab oleh nomor versi saja:
 *
 *   1. Berkas mana yang DIMODIFIKASI lokal oleh kampus? (bandingkan instalasi terhadap
 *      manifest versi yang sedang terpasang) — dibutuhkan supaya update satu klik tidak
 *      diam-diam menimpa penyesuaian yang mereka buat sendiri.
 *   2. Berkas mana yang HARUS DIHAPUS saat naik versi? (selisih manifest lama vs baru) —
 *      berkas yang dihapus upstream tidak akan hilang kalau update cuma menimpa, dan sisa
 *      seperti itu jadi kelas bug yang sulit dilacak.
 *   3. Apakah unduhan/ekstraksi utuh? (verifikasi per berkas, bukan cuma checksum zip)
 *
 * Manifest juga diterbitkan sebagai berkas terpisah di samping zip. Itu penting untuk
 * instalasi hasil klon Git, yang tidak pernah punya salinan lokalnya (manifest dibuat saat
 * build, tidak ikut di-commit): updater di sana mengunduh manifest versi terpasangnya dari
 * saluran rilis, lalu membandingkan instalasi terhadap manifest itu.
 *
 * vendor/ dan public/build/ SENGAJA dikecualikan. Keduanya artefak yang dihasilkan ulang,
 * selalu diganti utuh saat update (bukan digabung per berkas), jadi mendeteksi modifikasi
 * atau penghapusan di dalamnya tidak menghasilkan keputusan apa pun yang berbeda. Memasukkan
 * keduanya hanya akan menggelembungkan manifest belasan ribu entri.
 */

if ($argc < 3) {
    fwrite(STDERR, "Pemakaian: build-manifest.php <direktori-sumber> <versi>\n");
    exit(1);
}

[, $sourceDir, $version] = $argv;

$sourceDir = rtrim($sourceDir, '/');

if (! is_dir($sourceDir)) {
    fwrite(STDERR, "Direktori sumber tidak ada: {$sourceDir}\n");
    exit(1);
}

const MANIFEST_BASENAME = 'sikampus-manifest.json';

$excludedPrefixes = ['vendor/', 'public/build/'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$files = [];

foreach ($iterator as $item) {
    if (! $item->isFile()) {
        continue;
    }

    $relative = substr($item->getPathname(), strlen($sourceDir) + 1);

    // Manifest tidak bisa memuat hash dirinya sendiri.
    if ($relative === MANIFEST_BASENAME) {
        continue;
    }

    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            continue 2;
        }
    }

    $hash = hash_file('sha256', $item->getPathname());

    if ($hash === false) {
        fwrite(STDERR, "Gagal membaca berkas: {$relative}\n");
        exit(1);
    }

    $files[$relative] = $hash;
}

// Urutkan supaya membangun versi yang sama dua kali menghasilkan manifest yang identik
// byte-per-byte — tanpa itu, membandingkan dua build jadi tidak ada artinya.
ksort($files);

$manifest = [
    'version' => $version,
    'generated_at' => gmdate('c'),
    'algorithm' => 'sha256',
    'excluded' => $excludedPrefixes,
    'file_count' => count($files),
    'files' => $files,
];

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    fwrite(STDERR, "Gagal menyusun JSON manifest.\n");
    exit(1);
}

file_put_contents($sourceDir.'/'.MANIFEST_BASENAME, $json."\n");

fwrite(STDOUT, count($files)." berkas ter-hash.\n");
