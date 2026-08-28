<?php

namespace App\Services;

use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;

/**
 * Kode kelas otomatis saat admin mengosongkan isian kode di form kelas.
 *
 * Sumber utama nama kelompok kelas ("Bidan 2024" -> BID24), cadangannya kode mata kuliah
 * ("BID501" -> BID50). Hasilnya maksimal 5 karakter dan sengaja tidak dijamin unik — kolom
 * kelas.kode memang bukan identitas (nilai 'REG' saja dipakai ratusan kelas).
 */
class KelasKodeGenerator
{
    private const PANJANG_MAKS = 5;

    /**
     * Kode dari data kelas. null kalau tidak ada bahan sama sekali.
     */
    public static function untukKelas(?int $idKelompokKelas, ?int $idKurikulumMatkul): ?string
    {
        $nama = $idKelompokKelas
            ? KelompokKelas::whereKey($idKelompokKelas)->value('nama')
            : null;

        $kode = self::dariNama((string) $nama);
        if ($kode !== null) {
            return $kode;
        }

        $kodeMatkul = $idKurikulumMatkul
            ? KurikulumMatkul::with('matkul')->find($idKurikulumMatkul)?->matkul?->kode
            : null;

        return self::potong((string) $kodeMatkul);
    }

    /**
     * Singkatan huruf + 2 digit terakhir tahun, mis. "Bidan 2024" -> BID24, "KEB 2022" -> KEB22.
     *
     * Satu kata diambil huruf-huruf awalnya (BID, ILK, SIP); dua kata atau lebih diambil
     * inisialnya ("Kelas Percobaan" -> KP, "REG SORE SEM I" -> RSS). Token satu huruf seperti
     * seksi "A"/"B" diabaikan, jadi KEP 2023 A dan KEP 2023 B memang menghasilkan kode yang sama.
     * Nama tanpa huruf sama sekali (mis. "22201") dipakai apa adanya.
     */
    public static function dariNama(string $nama): ?string
    {
        $nama = trim($nama);
        if ($nama === '') {
            return null;
        }

        $tokens = preg_split('/[^A-Za-z0-9]+/', $nama, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tahun = '';
        $kata = [];
        foreach ($tokens as $token) {
            if ($tahun === '' && preg_match('/^(19|20)\d{2}$/', $token)) {
                $tahun = substr($token, -2);

                continue;
            }
            // Token satu huruf (seksi "A", "I") dilewati supaya tidak memakan jatah singkatan.
            if (preg_match('/^[A-Za-z]{2,}$/', $token)) {
                $kata[] = $token;
            }
        }

        if ($kata === []) {
            // Tidak ada kata yang bisa disingkat — pakai namanya apa adanya (mis. kode numerik).
            return self::potong($nama);
        }

        $jatahHuruf = self::PANJANG_MAKS - strlen($tahun);
        $huruf = count($kata) === 1
            ? substr($kata[0], 0, $jatahHuruf)
            : substr(implode('', array_map(fn (string $k) => $k[0], $kata)), 0, $jatahHuruf);

        return self::potong($huruf.$tahun);
    }

    private static function potong(string $nilai): ?string
    {
        $nilai = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', trim($nilai)) ?? '');

        return $nilai === '' ? null : substr($nilai, 0, self::PANJANG_MAKS);
    }
}
