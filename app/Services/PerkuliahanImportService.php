<?php

namespace App\Services;

use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;
use App\Models\MateriPerkuliahan;
use App\Models\Perkuliahan;
use App\Models\Ruangan;
use App\Models\Semester;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

/**
 * Logika import spreadsheet Perkuliahan — diekstrak dari App\Livewire\Admin\Perkuliahan\Import
 * (dulunya jalan sinkron di request HTTP, sekarang dipanggil dari App\Jobs\ImportPerkuliahanJob
 * lewat antrian supaya tidak kena batas waktu eksekusi PHP per-request untuk file besar) supaya
 * bisa dites langsung tanpa perlu jalan lewat job/HTTP. Sama persis dengan
 * PerkuliahanController::importSpreadsheet, hanya sumber file-nya path di disk, bukan
 * UploadedFile dari request.
 */
class PerkuliahanImportService
{
    /**
     * @param  array<int>|null  $allowedProdiIds  null = tanpa scope restriction
     * @return array{success_count: int, materi_perkuliahan_count: int, skip_count: int, failed_count: int, errors: array<int, string>}
     */
    public function run(string $filePath, ?array $allowedProdiIds, string $actor): array
    {
        // Regresi yang pernah kejadian: tanpa try/catch di sini, error mentah dari PhpSpreadsheet
        // (mis. TypeError array_intersect_key() dari mesin kalkulasi formula-nya kalau sel di file
        // sumber mengandung formula yang tidak didukung) bocor apa adanya sampai ke error_message
        // batch — bukan pesan ramah seperti importer lain di app ini (lihat Kelas/Krs/Nilai/dst
        // \Import.php: pesan yang sama, "hindari rumus error").
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Gagal membaca file Excel. Pastikan format .xlsx/.xls valid; hindari rumus error (#NAME?, #REF!). Salin data ke template lalu tempel sebagai nilai saja jika perlu. Detail: '.$e->getMessage(), previous: $e);
        }

        if (count($rows) < 2) {
            throw new \RuntimeException('File Excel kosong atau tidak valid.');
        }

        array_shift($rows);

        $errors = [];
        $perkuliahanSuccessCount = 0;
        $materiPerkuliahanSuccessCount = 0;
        $skipCount = 0;
        // Dilacak eksplisit (bukan diturunkan dari count($errors) - $skipCount) supaya tidak diam-diam
        // ikut salah kalau ada $errors[] baru ditambahkan di masa depan tanpa menandai jenisnya —
        // setiap error yang BUKAN "sudah ada/diabaikan" atau "di luar akses prodi" (dua-duanya sudah
        // dihitung $skipCount) berarti baris itu gagal divalidasi dan sama sekali tidak tersimpan.
        $failedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row, fn ($c) => $c !== null && $c !== ''))) {
                    continue;
                }

                $idJadwalRaw = trim((string) ($row[0] ?? ''));
                $kodeSemester = trim((string) ($row[1] ?? ''));
                $kodeMatkul = trim((string) ($row[2] ?? ''));
                $namaKelompokKelas = trim((string) ($row[3] ?? ''));
                $urutanRaw = trim((string) ($row[4] ?? ''));
                $namaRuangan = trim((string) ($row[5] ?? ''));
                $waktuMulaiRaw = $row[6] ?? null;
                $waktuSelesaiRaw = $row[7] ?? null;
                $materi = trim((string) ($row[8] ?? ''));
                $realisasiMateri = trim((string) ($row[9] ?? ''));
                $namaMateriFile = trim((string) ($row[10] ?? ''));
                $pathMateriFileRaw = trim((string) ($row[11] ?? ''));

                $waktuMulai = $this->parseImportDateTime($waktuMulaiRaw);
                $hasPerkuliahanRow = $waktuMulai !== null;

                if (! $hasPerkuliahanRow && $pathMateriFileRaw === '') {
                    $errors[] = "Baris {$rowNumber}: Isi Waktu Mulai (perkuliahan) atau Path file materi (minimal salah satu).";
                    $failedCount++;

                    continue;
                }

                if ($hasPerkuliahanRow && $namaMateriFile !== '' && $pathMateriFileRaw === '') {
                    $errors[] = "Baris {$rowNumber}: Kolom Nama berkas materi diisi tetapi Path file materi kosong.";
                    $failedCount++;

                    continue;
                }

                $waktuSelesai = $this->parseImportDateTime($waktuSelesaiRaw);

                if ($hasPerkuliahanRow) {
                    if ($waktuSelesai && $waktuSelesai->lte($waktuMulai)) {
                        $errors[] = "Baris {$rowNumber}: Waktu Selesai harus setelah Waktu Mulai.";
                        $failedCount++;

                        continue;
                    }

                    if (strlen($materi) > 255) {
                        $errors[] = "Baris {$rowNumber}: Materi ringkas maksimal 255 karakter.";
                        $failedCount++;

                        continue;
                    }
                }

                if (strlen($namaMateriFile) > 255) {
                    $errors[] = "Baris {$rowNumber}: Nama berkas materi maksimal 255 karakter.";
                    $failedCount++;

                    continue;
                }

                $jadwal = null;

                if ($idJadwalRaw !== '' && ctype_digit($idJadwalRaw)) {
                    $jid = (int) $idJadwalRaw;
                    $jadwal = Jadwal::with('kelas')->whereNull('deleted_at')->find($jid);
                    if (! $jadwal) {
                        $errors[] = "Baris {$rowNumber}: id_jadwal {$jid} tidak ditemukan.";
                        $failedCount++;

                        continue;
                    }
                    if ($allowedProdiIds !== null && ! in_array((int) $jadwal->kelas->id_prodi, $allowedProdiIds, true)) {
                        $errors[] = "Baris {$rowNumber}: Tidak ada akses ke prodi kelas jadwal ini.";
                        $skipCount++;

                        continue;
                    }
                } else {
                    if ($kodeSemester === '' || $kodeMatkul === '') {
                        $errors[] = "Baris {$rowNumber}: Isi id_jadwal atau kombinasi Kode Semester + Kode Mata Kuliah.";
                        $failedCount++;

                        continue;
                    }
                    if ($urutanRaw === '' || ! ctype_digit($urutanRaw) || (int) $urutanRaw < 1 || (int) $urutanRaw > 99) {
                        $errors[] = "Baris {$rowNumber}: Pertemuan ke- wajib (angka 1-99).";
                        $failedCount++;

                        continue;
                    }
                    $urutan = (int) $urutanRaw;

                    [$kelas, $kelasErr] = $this->resolveKelasFromImportKeys($kodeSemester, $kodeMatkul, $namaKelompokKelas);
                    if ($kelasErr !== null) {
                        $errors[] = "Baris {$rowNumber}: {$kelasErr}";
                        $failedCount++;

                        continue;
                    }
                    if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                        $errors[] = "Baris {$rowNumber}: Tidak ada akses ke prodi ini.";
                        $skipCount++;

                        continue;
                    }

                    [$jadwal, $jadwalErr] = $this->findJadwalSlotForImport($kelas, $urutan, $namaRuangan);
                    if ($jadwalErr !== null || ! $jadwal) {
                        $errors[] = "Baris {$rowNumber}: ".($jadwalErr ?? 'Jadwal tidak ditemukan.');
                        $failedCount++;

                        continue;
                    }
                }

                $materiVal = $materi !== '' ? $materi : null;
                $realisasiVal = $realisasiMateri !== '' ? $realisasiMateri : null;

                $createdPerkuliahan = false;

                if ($hasPerkuliahanRow) {
                    $exists = Perkuliahan::where('id_jadwal', $jadwal->id)
                        ->where('waktu_mulai', $waktuMulai->format('Y-m-d H:i:s'))
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($exists) {
                        $skipCount++;
                        $errors[] = "Baris {$rowNumber}: Sudah ada perkuliahan untuk jadwal ini pada waktu mulai yang sama (diabaikan).";
                    } else {
                        Perkuliahan::create([
                            'id_jadwal' => $jadwal->id,
                            'tanggal' => $waktuMulai->toDateString(),
                            'waktu_mulai' => $waktuMulai,
                            'waktu_selesai' => $waktuSelesai,
                            'materi' => $materiVal,
                            'realisasi_materi' => $realisasiVal,
                            'created_by' => $actor,
                        ]);
                        $createdPerkuliahan = true;
                        $perkuliahanSuccessCount++;
                    }
                }

                if ($pathMateriFileRaw !== '') {
                    $normalizedPath = $this->normalizeMateriFilePathForImport($pathMateriFileRaw);
                    if ($normalizedPath === '') {
                        $errors[] = "Baris {$rowNumber}: Path file materi tidak valid.";
                        $failedCount++;

                        continue;
                    }
                    if (strlen($normalizedPath) > 255) {
                        $errors[] = "Baris {$rowNumber}: Path file materi maksimal 255 karakter.";
                        $failedCount++;

                        continue;
                    }
                    if (! Storage::disk('public')->exists($normalizedPath)) {
                        $errors[] = "Baris {$rowNumber}: Berkas tidak ditemukan di storage public: {$normalizedPath} (unggah ke storage/app/public atau salin ke folder tersebut).";
                        $failedCount++;

                        continue;
                    }

                    $dupMateri = MateriPerkuliahan::where('id_jadwal', $jadwal->id)
                        ->where('file', $normalizedPath)
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($dupMateri) {
                        $skipCount++;
                        $errors[] = "Baris {$rowNumber}: Materi file dengan path yang sama sudah ada untuk slot jadwal ini (diabaikan).";
                    } else {
                        MateriPerkuliahan::create([
                            'id_jadwal' => $jadwal->id,
                            'nama' => $namaMateriFile !== '' ? $namaMateriFile : null,
                            'file' => $normalizedPath,
                        ]);
                        $materiPerkuliahanSuccessCount++;
                    }
                } elseif ($pathMateriFileRaw === '' && $hasPerkuliahanRow && ! $createdPerkuliahan) {
                    continue;
                }
            }

            DB::commit();

            return [
                'success_count' => $perkuliahanSuccessCount,
                'materi_perkuliahan_count' => $materiPerkuliahanSuccessCount,
                'skip_count' => $skipCount,
                'failed_count' => $failedCount,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            // \Throwable, bukan \Exception — TypeError/Error di tengah loop (mis. bug kalkulasi
            // formula PhpSpreadsheet yang lolos dari try/catch load() di atas karena baru muncul
            // saat toArray() dipanggil ulang, atau error lain) tetap harus memicu rollback,
            // bukan meninggalkan transaksi menggantung.
            DB::rollBack();

            throw new \RuntimeException('Terjadi kesalahan saat mengimport data: '.$e->getMessage(), previous: $e);
        }
    }

    private function parseImportDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return Carbon::instance(SpreadsheetDate::excelToDateTimeObject((float) $value));
            } catch (\Throwable) {
                // lanjut coba string
            }
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?Kelas, 1: ?string} Kelas atau pesan error
     */
    private function resolveKelasFromImportKeys(string $kodeSemester, string $kodeMatkul, string $namaKelompokKelas): array
    {
        $semester = Semester::where('kode', $kodeSemester)->first();
        if (! $semester) {
            return [null, "Semester '{$kodeSemester}' tidak ditemukan."];
        }

        $kurikulumMatkulIds = KurikulumMatkul::query()
            ->where('kode_matkul', $kodeMatkul)
            ->pluck('id');
        if ($kurikulumMatkulIds->isEmpty()) {
            return [null, "Mata kuliah dengan kode '{$kodeMatkul}' tidak ditemukan di kurikulum."];
        }

        $kelasQuery = Kelas::query()
            ->whereIn('id_kurikulum_matkul', $kurikulumMatkulIds)
            ->where('id_semester', $semester->id);

        if ($namaKelompokKelas !== '') {
            $kelompok = KelompokKelas::query()
                ->whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower($namaKelompokKelas)])
                ->first();
            if (! $kelompok) {
                return [null, "Kelas mahasiswa '{$namaKelompokKelas}' tidak ditemukan."];
            }
            $kelasQuery->where('id_kelompok_kelas', $kelompok->id);
        } else {
            $kelasQuery->whereNull('id_kelompok_kelas');
        }

        $kelasCandidates = $kelasQuery->get();
        if ($kelasCandidates->isEmpty()) {
            return [null, 'Kelas tidak ditemukan untuk kombinasi semester, kode mata kuliah, dan nama kelas mahasiswa (kosong = tanpa kelas mahasiswa).'];
        }
        if ($kelasCandidates->count() > 1) {
            return [null, 'Ditemukan beberapa baris kelas yang cocok — perjelas di data master kelas.'];
        }

        return [$kelasCandidates->first(), null];
    }

    /**
     * @return array{0: ?Jadwal, 1: ?string}
     */
    private function findJadwalSlotForImport(Kelas $kelas, int $urutan, string $namaRuangan): array
    {
        $candidates = Jadwal::where('id_kelas', $kelas->id)
            ->where('urutan_pertemuan', $urutan)
            ->whereNull('deleted_at')
            ->get();

        if ($candidates->isEmpty()) {
            return [null, "Tidak ada slot jadwal untuk pertemuan ke-{$urutan} pada kelas ini."];
        }

        if ($candidates->count() === 1) {
            return [$candidates->first(), null];
        }

        if ($namaRuangan !== '') {
            $ruangan = Ruangan::where('nama', 'like', '%'.$namaRuangan.'%')->first();
            if (! $ruangan) {
                return [null, "Ruangan '{$namaRuangan}' tidak ditemukan (diperlukan untuk membedakan beberapa slot jadwal)."];
            }
            $match = $candidates->firstWhere('id_ruangan', $ruangan->id);
            if (! $match) {
                return [null, 'Tidak ada jadwal dengan ruangan tersebut untuk pertemuan ini.'];
            }

            return [$match, null];
        }

        return [null, 'Ditemukan beberapa slot jadwal untuk pertemuan ini — isi kolom Nama Ruangan atau gunakan id_jadwal.'];
    }

    /**
     * Normalisasi path relatif ke root disk "public" (storage/app/public).
     */
    private function normalizeMateriFilePathForImport(string $raw): string
    {
        $p = trim(str_replace('\\', '/', $raw));
        $p = ltrim($p, '/');
        if (str_starts_with($p, 'storage/')) {
            $p = substr($p, strlen('storage/'));
        }

        return $p;
    }
}
