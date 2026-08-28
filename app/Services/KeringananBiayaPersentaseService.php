<?php

namespace App\Services;

use App\Models\JenisKeringananBiaya;
use App\Models\KeringananBiaya;
use App\Models\Tagihan;

/**
 * Keringanan bertipe persentase: mengubah persen jadi rupiah.
 *
 * keringanan_biaya.nominal SELALU rupiah — KeringananBiayaKreditService menjumlahkannya mentah
 * di SQL maupun Eloquent, dan tidak mengenal persen sama sekali. Jadi persentase diselesaikan
 * sekali di titik approve, bukan dihitung ulang tiap kali dibaca.
 *
 * Dasarnya total tagihan pada (mahasiswa, semester), bukan sisanya: diskon 10% berarti 10% dari
 * tagihan penuh, tidak bergantung pada sudah berapa yang dibayar saat admin menekan setujui.
 *
 * Hasilnya di-snapshot (persentase + dasar_perhitungan) supaya keringanan yang sudah disetujui
 * tidak berubah arti kalau master jenis_keringanan_biaya diedit belakangan.
 */
class KeringananBiayaPersentaseService
{
    /** Total tagihan satu mahasiswa pada satu semester — dasar perhitungan persentase. */
    public static function dasarPerhitungan(int $mahasiswaId, int $semesterId): float
    {
        return (float) Tagihan::query()
            ->where('id_mahasiswa', $mahasiswaId)
            ->where('id_semester', $semesterId)
            ->sum('total');
    }

    /** Persen dari master, atau null kalau jenisnya bukan persentase. */
    public static function persentaseMaster(?int $jenisId): ?float
    {
        if (! $jenisId) {
            return null;
        }

        $jenis = JenisKeringananBiaya::find($jenisId);
        if (! $jenis || ! $jenis->is_persentase) {
            return null;
        }

        return (float) $jenis->nominal;
    }

    /**
     * Isi nominal + snapshot pada baris yang akan disetujui.
     *
     * Untuk jenis non-persentase tidak melakukan apa-apa: nominalnya sudah rupiah apa adanya.
     *
     * @return string|null pesan kegagalan, null kalau berhasil
     */
    public static function terapkanSaatApprove(KeringananBiaya $row): ?string
    {
        // Persentase yang sudah pernah disnapshot dipakai ulang; master boleh berubah setelahnya.
        $persen = $row->persentase !== null
            ? (float) $row->persentase
            : self::persentaseMaster($row->id_jenis_keringanan_biaya ? (int) $row->id_jenis_keringanan_biaya : null);

        if ($persen === null) {
            return null;
        }

        $dasar = self::dasarPerhitungan((int) $row->id_mahasiswa, (int) $row->id_semester);
        if ($dasar <= 0) {
            return 'Belum ada tagihan pada semester itu, sehingga keringanan persentase belum bisa dihitung. '
                .'Terbitkan tagihannya lebih dulu, baru setujui keringanan ini.';
        }

        $row->persentase = $persen;
        $row->dasar_perhitungan = $dasar;
        $row->dasar_dihitung_pada = now();
        $row->nominal = round($dasar * $persen / 100, 2);

        return null;
    }

    /** Apakah baris ini nominalnya ditentukan sistem (bukan diketik admin). */
    public static function dihitungSistem(KeringananBiaya $row): bool
    {
        if ($row->persentase !== null) {
            return true;
        }

        return self::persentaseMaster(
            $row->id_jenis_keringanan_biaya ? (int) $row->id_jenis_keringanan_biaya : null
        ) !== null;
    }
}
