<?php

namespace App\Services;

use App\Models\Krs;
use Illuminate\Support\Collection;

/**
 * Pengurutan daftar KRS/nilai berdasarkan nama mata kuliah.
 *
 * Diurutkan di PHP, bukan lewat ORDER BY, karena nama efektif mata kuliah adalah
 * kurikulum_matkul.nama_matkul dengan fallback ke matkul.nama — pola yang dipakai di seluruh
 * repo ini. Menuliskannya sebagai ORDER BY berarti menambahkan join matkul + COALESCE ke
 * belasan query yang bentuknya berbeda-beda (sebagian sudah pakai whereHas/join sendiri) hanya
 * untuk meniru fallback yang sama. Daftar ini selalu milik satu mahasiswa (puluhan baris), jadi
 * biaya mengurutkan di PHP tidak signifikan.
 */
class UrutanMatkulService
{
    /**
     * Nama mata kuliah yang dipakai sebagai kunci urut.
     *
     * matkul.nama yang diutamakan, BUKAN kurikulum_matkul.nama_matkul — semua daftar KRS/nilai
     * per mahasiswa (panel, API, xlsx, PDF) menampilkan matkul.nama, jadi kalau diurutkan pakai
     * kunci lain urutannya akan terlihat acak di layar. Prioritas sebaliknya (nama_matkul dulu)
     * memang dipakai di repo ini, tapi khusus endpoint yang berpusat pada kelas
     * (mis. NilaiController::getMyMataKuliah) — bukan daftar yang diurutkan di sini.
     */
    public static function namaMatkul(?Krs $krs): string
    {
        $kurikulumMatkul = $krs?->kelas?->kurikulumMatkul;

        $nama = trim((string) ($kurikulumMatkul?->matkul?->nama ?? ''));
        if ($nama === '') {
            $nama = trim((string) ($kurikulumMatkul?->nama_matkul ?? ''));
        }

        return $nama;
    }

    /**
     * Urutkan koleksi KRS berdasarkan nama mata kuliah (abaikan besar-kecil huruf, angka
     * diurutkan secara natural: "Kalkulus 2" sebelum "Kalkulus 10").
     *
     * values() wajib: sortBy mempertahankan key aslinya, dan koleksi dengan key non-berurutan
     * akan ter-encode sebagai object JSON (bukan array) di endpoint API.
     *
     * @param  Collection<int, Krs>  $krsList
     * @return Collection<int, Krs>
     */
    public static function urutkanKrs(Collection $krsList): Collection
    {
        return $krsList
            ->sortBy(fn (Krs $krs) => mb_strtolower(self::namaMatkul($krs)), SORT_NATURAL)
            ->values();
    }
}
