<?php

namespace App\Console\Commands;

use App\Models\Kelas;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Semester;
use Illuminate\Console\Command;

/**
 * Alat bantu diagnosa: kenapa sebuah kelas tidak muncul di halaman pengajuan KRS mahasiswa.
 *
 * Perintah ini mengulang persis filter yang dipakai
 * KrsController::getJadwalPengajuan() (GET /api/krs/pengajuan/jadwal), lalu melaporkan
 * syarat mana yang tidak terpenuhi untuk tiap kelas kandidat.
 */
class DiagnoseKrsPengajuan extends Command
{
    protected $signature = 'krs:diagnose
        {nim : NIM mahasiswa, mis. 044228240005}
        {--matkul= : Kode mata kuliah yang ditelusuri, mis. BID501 (opsional)}';

    protected $description = 'Diagnosa kenapa kelas tidak muncul di halaman pengajuan KRS mahasiswa';

    public function handle(): int
    {
        $nim = (string) $this->argument('nim');
        $kodeMatkul = $this->option('matkul');

        $mahasiswa = Mahasiswa::with(['prodi', 'semester_masuk', 'kelompok_kelas'])
            ->whereNull('deleted_at')
            ->where('nim', $nim)
            ->first();

        if (! $mahasiswa) {
            $this->error("Mahasiswa dengan NIM {$nim} tidak ditemukan (atau sudah soft-deleted).");

            return self::FAILURE;
        }

        $this->info('=== DATA MAHASISWA ===');
        $this->table(['Field', 'Nilai'], [
            ['id', $mahasiswa->id],
            ['nim', $mahasiswa->nim],
            ['nama', $mahasiswa->nama],
            ['id_user', $mahasiswa->id_user ?? '(NULL) -> endpoint akan balas 404'],
            ['id_prodi', $this->fmt($mahasiswa->id_prodi).' ('.($mahasiswa->prodi->nama ?? '-').')'],
            ['id_semester_masuk', $this->fmt($mahasiswa->id_semester_masuk).' ('.($mahasiswa->semester_masuk->kode ?? '-').')'],
            ['id_kelompok_kelas', $this->fmt($mahasiswa->id_kelompok_kelas).' ('.($mahasiswa->kelompok_kelas->nama ?? '-').')'],
        ]);

        if (! $mahasiswa->id_user) {
            $this->warn('id_user kosong: mahasiswa tidak terhubung ke akun user, endpoint pengajuan KRS akan menjawab 404.');
        }

        $activeSemester = Semester::where('is_active', true)->whereNull('deleted_at')->first();
        if (! $activeSemester) {
            $this->error('Tidak ada semester dengan is_active = 1. Endpoint akan menjawab 404 "Tidak ada semester aktif".');

            return self::FAILURE;
        }
        $this->info('=== SEMESTER AKTIF ===');
        $this->line("id={$activeSemester->id} kode={$activeSemester->kode} nama={$activeSemester->nama}");

        // Query persis seperti di getJadwalPengajuan()
        $query = Kelas::query()
            ->whereNull('kelas.deleted_at')
            ->where('id_semester', $activeSemester->id)
            ->where('is_active', true);

        if ($mahasiswa->id_prodi) {
            $query->where('id_prodi', $mahasiswa->id_prodi);
        }
        if ($mahasiswa->id_semester_masuk) {
            $query->where('id_angkatan', $mahasiswa->id_semester_masuk);
        }
        if ($mahasiswa->id_kelompok_kelas !== null && $mahasiswa->id_kelompok_kelas !== '') {
            $query->where('id_kelompok_kelas', $mahasiswa->id_kelompok_kelas);
        }

        $lolos = $query->count();
        $this->info('=== HASIL QUERY ENDPOINT ===');
        $this->line("Jumlah kelas yang muncul di halaman pengajuan KRS: {$lolos}");

        // Kandidat kelas yang diperiksa satu per satu.
        $kandidat = Kelas::withTrashed()
            ->with(['kurikulumMatkul.matkul', 'prodi', 'semester', 'angkatan', 'kelompokKelas', 'jadwal'])
            ->where('id_semester', $activeSemester->id);

        if ($kodeMatkul) {
            $kandidat->whereHas('kurikulumMatkul', function ($km) use ($kodeMatkul) {
                $km->where(function ($w) use ($kodeMatkul) {
                    $w->where('kode_matkul', $kodeMatkul)
                        ->orWhereHas('matkul', fn ($m) => $m->where('kode', $kodeMatkul));
                });
            });
        } elseif ($mahasiswa->id_prodi) {
            $kandidat->where('id_prodi', $mahasiswa->id_prodi);
        }

        $kandidatList = $kandidat->orderBy('id')->get();

        $this->info('=== PEMERIKSAAN KELAS KANDIDAT'.($kodeMatkul ? " (matkul {$kodeMatkul})" : '').' ===');
        if ($kandidatList->isEmpty()) {
            $this->warn('Tidak ada satu pun baris `kelas` pada semester aktif untuk kriteria ini.');
            $this->warn('Artinya kelas belum dibuat di semester aktif, atau dibuat pada id_semester yang lain.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($kandidatList as $kelas) {
            $gagal = [];

            if ($kelas->deleted_at !== null) {
                $gagal[] = 'kelas terhapus (deleted_at terisi)';
            }
            if ((int) $kelas->id_semester !== (int) $activeSemester->id) {
                $gagal[] = 'id_semester != semester aktif';
            }
            if ($kelas->is_active === null) {
                $gagal[] = 'is_active NULL (bukan true)';
            } elseif (! $kelas->is_active) {
                $gagal[] = 'is_active = false (kelas belum diaktifkan)';
            }
            if ($mahasiswa->id_prodi && (int) $kelas->id_prodi !== (int) $mahasiswa->id_prodi) {
                $gagal[] = 'id_prodi kelas ('.$this->fmt($kelas->id_prodi).') != prodi mahasiswa ('.$this->fmt($mahasiswa->id_prodi).')';
            }
            if ($mahasiswa->id_semester_masuk && (int) $kelas->id_angkatan !== (int) $mahasiswa->id_semester_masuk) {
                $gagal[] = 'id_angkatan kelas ('.$this->fmt($kelas->id_angkatan).'/'.($kelas->angkatan->kode ?? '-').') != semester masuk mahasiswa ('.$this->fmt($mahasiswa->id_semester_masuk).'/'.($mahasiswa->semester_masuk->kode ?? '-').')';
            }
            if ($mahasiswa->id_kelompok_kelas !== null && $mahasiswa->id_kelompok_kelas !== ''
                && (int) $kelas->id_kelompok_kelas !== (int) $mahasiswa->id_kelompok_kelas) {
                $gagal[] = 'id_kelompok_kelas kelas ('.$this->fmt($kelas->id_kelompok_kelas).') != kelompok mahasiswa ('.$this->fmt($mahasiswa->id_kelompok_kelas).')';
            }

            $sudahKrs = Krs::where('id_mahasiswa', $mahasiswa->id)
                ->whereNull('deleted_at')
                ->where('id_kelas', $kelas->id)
                ->exists();

            $rows[] = [
                $kelas->id,
                $kelas->kurikulumMatkul->kode_matkul ?: ($kelas->kurikulumMatkul->matkul->kode ?? '-'),
                $kelas->kode ?: '-',
                $kelas->jadwal->count(),
                $sudahKrs ? 'ya' : 'tidak',
                empty($gagal) ? 'MUNCUL' : 'TIDAK MUNCUL: '.implode('; ', $gagal),
            ];
        }

        $this->table(
            ['id_kelas', 'kode_matkul', 'kode_kelas', 'jml_jadwal', 'sudah_di_krs', 'status'],
            $rows
        );

        return self::SUCCESS;
    }

    private function fmt($value): string
    {
        return $value === null ? 'NULL' : (string) $value;
    }
}
