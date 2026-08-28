<?php

namespace App\Services;

use App\Models\Mahasiswa;
use App\Models\Semester;

/**
 * Konsistensi kelas.id_angkatan terhadap kelompok kelas (rombongan) yang dipilih.
 *
 * kelas.id_angkatan berarti "semester masuk mahasiswa", bukan semester berjalan — halaman
 * pengajuan KRS menyaring kelas dengan id_angkatan = mahasiswa.id_semester_masuk (lihat
 * KrsController::getJadwalPengajuan dan App\Livewire\Mahasiswa\Krs\Pengajuan). Kalau angkatan
 * telanjur diisi semester berjalan, kelasnya tidak pernah muncul untuk satu pun mahasiswa dan
 * kesalahan itu tidak kelihatan sama sekali dari panel admin.
 */
class KelasAngkatanService
{
    /**
     * Semester masuk yang benar-benar dipakai mahasiswa aktif pada satu kelompok kelas.
     *
     * @return array<int, int>
     */
    public static function angkatanIdsForKelompokKelas(?int $idKelompokKelas): array
    {
        if (! $idKelompokKelas) {
            return [];
        }

        return Mahasiswa::query()
            ->whereNull('deleted_at')
            ->where('id_kelompok_kelas', $idKelompokKelas)
            ->whereNotNull('id_semester_masuk')
            ->distinct()
            ->pluck('id_semester_masuk')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Angkatan yang aman disarankan otomatis: hanya kalau kelompok kelasnya homogen satu angkatan.
     */
    public static function angkatanSaranForKelompokKelas(?int $idKelompokKelas): ?int
    {
        $ids = self::angkatanIdsForKelompokKelas($idKelompokKelas);

        return count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Pesan error kalau angkatan pasti salah — tidak satu pun mahasiswa di kelompok kelas itu
     * punya semester masuk yang dipilih, jadi kelasnya mustahil muncul di pengajuan KRS.
     *
     * Sengaja hanya menolak kasus yang terbukti mubazir: kelompok kelas kosong atau tidak dipilih
     * tetap dibiarkan lewat supaya kelas boleh dibuat sebelum mahasiswanya ditempatkan.
     *
     * @return string|null null = konsisten atau tidak bisa dinilai
     */
    public static function pesanKetidakcocokan(?int $idKelompokKelas, ?int $idAngkatan): ?string
    {
        if (! $idKelompokKelas || ! $idAngkatan) {
            return null;
        }

        $angkatanIds = self::angkatanIdsForKelompokKelas($idKelompokKelas);
        if ($angkatanIds === [] || in_array((int) $idAngkatan, $angkatanIds, true)) {
            return null;
        }

        $kodeSeharusnya = Semester::whereIn('id', $angkatanIds)
            ->orderBy('kode')
            ->pluck('kode')
            ->implode(', ');
        $kodeDipilih = Semester::where('id', $idAngkatan)->value('kode');

        return 'Angkatan tidak cocok dengan kelas mahasiswa yang dipilih: mahasiswa di kelompok itu '
            ."angkatan {$kodeSeharusnya}, bukan {$kodeDipilih}. Isi angkatan dengan semester masuk "
            .'mahasiswa (bukan semester berjalan), kalau tidak kelas ini tidak akan muncul di pengajuan KRS.';
    }
}
