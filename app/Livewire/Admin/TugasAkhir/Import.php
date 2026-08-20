<?php

namespace App\Livewire\Admin\TugasAkhir;

use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\Semester;
use App\Models\TugasAkhir;
use App\Models\TugasAkhirPembimbing;
use App\Models\UjianSidang;
use App\Models\UjianSidangPenguji;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class Import extends Component
{
    use WithFileUploads;

    /** Sama dengan TugasAkhirController::STATUSES. */
    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'returned'];

    /** Sama dengan TugasAkhirController::UJIAN_SIDANG_STATUSES. */
    private const UJIAN_SIDANG_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    public $file = null;

    public bool $processing = false;

    public ?array $result = null;

    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }

    /**
     * Sama persis dengan TugasAkhirController::import. Tidak ada endpoint store() admin-side yang
     * bisa dicerminkan langsung (mahasiswa mengajukan sendiri lewat storePengajuanMahasiswa,
     * terikat KRS TA semester aktif) — import ini dipakai untuk mengisi data historis/hasil
     * migrasi, jadi SENGAJA tidak mensyaratkan KRS Tugas Akhir yang disetujui.
     *
     * Kombinasi (id_mahasiswa, id_semester) adalah kunci baris — kalau sudah ada, baris di-UPDATE
     * (bukan dilewati/dianggap error seperti sebelumnya). Aturan pengisian sel untuk mode update,
     * sama seperti pola angka_mutu/huruf_mutu di Nilai/Import: Judul tetap wajib diisi di setiap
     * baris (bukan cuma saat baris baru), tapi kolom opsional (Status, Judul (English), Topik,
     * Topik (English), Deskripsi, Is Proposal, File) yang dikosongkan berarti "biarkan nilai lama",
     * bukan "hapus/reset ke default" — supaya re-import sebagian data tidak diam-diam menimpa data
     * lain yang sudah benar.
     *
     * Dosen pembimbing & penguji (tugas_akhir_pembimbing, kolom `peran`) opsional — satu mahasiswa
     * boleh punya lebih dari satu dosen per peran, ditulis sebagai daftar kode dosen dipisah koma
     * (pola sama dengan "Kode Tim Dosen" di Kelas/Import). Kalau salah satu kode dosen di baris itu
     * tidak ditemukan, SELURUH baris tugas akhir digagalkan (bukan cuma pembimbingnya) supaya tidak
     * ada baris tugas_akhir yang setengah-lengkap datanya. Kolom pembimbing/penguji yang dikosongkan
     * tidak menyentuh data pembimbing yang sudah ada; kalau diisi, daftarnya DISINKRONKAN (dosen
     * lama yang tidak ada di daftar baru dihapus, yang baru ditambahkan) — pola sama dengan
     * syncKelasDosen di Kelas/Import.
     *
     * Kolom File opsional dan bukan upload — isinya path relatif berkas (karena Excel tidak bisa
     * membawa file biner). Path ini DITULIS APA ADANYA ke kolom `file`, tanpa dicek dulu ke storage
     * disk public — beda dari foto_path di Mahasiswa/Import yang memvalidasi keberadaan berkasnya.
     * Sengaja begini: berkas boleh menyusul diunggah belakangan (mis. proses migrasi bertahap), jadi
     * path yang "belum ada saat ini" tidak boleh membuat data lain di baris gagal atau bahkan bikin
     * kolom file-nya dikosongkan.
     *
     * Data ujian sidang (ujian_sidang) ikut diimport dalam baris yang sama, karena keduanya
     * berkaitan langsung. Seluruh bagian ujian sidang bersifat OPSIONAL per baris: kalau "Kode
     * Semester Ujian Sidang" kosong, baris itu tidak menyentuh data ujian sidang sama sekali (baik
     * create maupun update). Kalau diisi, kombinasi (id_tugas_akhir, id_semester) jadi kunci baris
     * ujian_sidang — kalau sudah ada, di-UPDATE dengan aturan blank-cell-berarti-pertahankan-nilai-
     * lama yang sama (Tanggal Ujian Mulai/Selesai, Status); kalau belum ada, dibuat baru dengan
     * default status 'draft'.
     *
     * Dosen penguji sidang (ujian_sidang_penguji) juga opsional dan mengikuti pola sinkron yang sama
     * dengan pembimbing/penguji tugas akhir: kolom kosong tidak menyentuh data penguji yang sudah
     * ada, kolom terisi men-sinkronkan daftarnya. Sesuai permintaan eksplisit: kalau penguji sidang
     * belum ditentukan, kolom ini cukup dikosongkan — ujian sidang tetap terimport tanpa penguji,
     * bukan error. Kalau kolom diisi tapi salah satu kode dosen tidak ditemukan, SELURUH baris
     * digagalkan — konsisten dengan aturan Kode Dosen Pembimbing/Penguji di atas.
     *
     * Nilai, Catatan, dan Kode Dosen Ketua Sidang bisa ikut diimport per dosen penguji — karena satu
     * baris bisa punya lebih dari satu penguji, Nilai dan Catatan ditulis sebagai daftar yang
     * SEJAJAR URUTAN dengan Kode Dosen Penguji Sidang (index ke-i berlaku untuk dosen ke-i). Nilai
     * dipisah koma, Catatan dipisah karakter "|" (BUKAN koma, supaya teks catatan boleh mengandung
     * koma) — slot kosong berarti dosen itu belum dinilai/dicatat. Karena posisinya harus presisi,
     * kode dosen di kolom ini TIDAK di-dedup — kode yang duplikat dalam satu baris dianggap error.
     * Kode Dosen Ketua Sidang satu kode saja, harus salah satu dari Kode Dosen Penguji Sidang di
     * baris yang sama; dosen itu di-set is_ketua=true, dosen penguji lain di baris itu eksplisit
     * di-set is_ketua=false. Ketiga kolom ini blank-cell-berarti-pertahankan-nilai-lama PER DOSEN
     * saat update — beda dari kolom lain yang preserve per-baris, di sini preserve-nya per-dosen.
     */
    public function import(): void
    {
        $this->result = null;
        $this->processing = true;
        $this->validate();

        try {
            $spreadsheet = IOFactory::load($this->file->getRealPath());
        } catch (\Throwable $e) {
            $this->processing = false;
            $this->addError('file', 'Gagal membaca file Excel. Pastikan format .xlsx/.xls valid; hindari rumus error (#NAME?, #REF!). Salin data ke template lalu tempel sebagai nilai saja jika perlu. Detail: '.$e->getMessage());

            return;
        }

        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (count($rows) < 2) {
            $this->processing = false;
            $this->addError('file', 'File Excel kosong atau tidak valid.');
            $this->reset('file');

            return;
        }

        array_shift($rows);

        $errors = [];
        $successCount = 0;
        $updatedCount = 0;
        $ujianSidangSuccessCount = 0;
        $ujianSidangUpdatedCount = 0;

        $user = Auth::user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;
        $actor = $user ? (trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '')) : 'system';

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $nim = trim((string) ($row[0] ?? ''));
                $kodeSemester = trim((string) ($row[1] ?? ''));
                $judul = trim((string) ($row[2] ?? ''));
                $statusRaw = trim((string) ($row[3] ?? ''));
                $isProposalRaw = trim(strtolower((string) ($row[8] ?? '')));

                if ($nim === '') {
                    $errors[] = "Baris {$rowNumber}: NIM wajib diisi.";

                    continue;
                }

                if ($kodeSemester === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Semester wajib diisi.";

                    continue;
                }

                if ($judul === '') {
                    $errors[] = "Baris {$rowNumber}: Judul wajib diisi.";

                    continue;
                }

                $mahasiswa = Mahasiswa::where('nim', $nim)->first();
                if (! $mahasiswa) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa dengan NIM '{$nim}' tidak ditemukan.";

                    continue;
                }

                if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke mahasiswa NIM '{$nim}' (prodi di luar scope).";

                    continue;
                }

                $semester = Semester::where('kode', $kodeSemester)->first();
                if (! $semester) {
                    $errors[] = "Baris {$rowNumber}: Semester dengan kode '{$kodeSemester}' tidak ditemukan.";

                    continue;
                }

                if ($statusRaw !== '' && ! in_array($statusRaw, self::STATUSES, true)) {
                    $errors[] = "Baris {$rowNumber}: Status '{$statusRaw}' tidak valid. Gunakan salah satu: ".implode(', ', self::STATUSES).'.';

                    continue;
                }

                $existing = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_semester', $semester->id)
                    ->first();
                $isUpdate = $existing !== null;

                // Kolom kosong = pertahankan nilai lama saat update, atau pakai default saat baris
                // baru — lihat catatan di docblock import().
                $status = $statusRaw !== '' ? $statusRaw : ($isUpdate ? $existing->status : 'submitted');

                $isProposal = $isProposalRaw !== ''
                    ? (filter_var($isProposalRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true)
                    : ($isUpdate ? (bool) $existing->is_proposal : true);

                $kodePembimbingRaw = trim((string) ($row[9] ?? ''));
                $kodePengujiRaw = trim((string) ($row[10] ?? ''));

                $pembimbingDosenIds = [];
                if ($kodePembimbingRaw !== '') {
                    foreach (self::splitKodeDosenList($kodePembimbingRaw) as $kd) {
                        $d = Dosen::where('kode_dosen', $kd)->first();
                        if (! $d) {
                            $errors[] = "Baris {$rowNumber}: Dosen pembimbing — kode '{$kd}' tidak ditemukan.";

                            continue 2;
                        }
                        $pembimbingDosenIds[] = (int) $d->id;
                    }
                }

                $pengujiDosenIds = [];
                if ($kodePengujiRaw !== '') {
                    foreach (self::splitKodeDosenList($kodePengujiRaw) as $kd) {
                        $d = Dosen::where('kode_dosen', $kd)->first();
                        if (! $d) {
                            $errors[] = "Baris {$rowNumber}: Dosen penguji — kode '{$kd}' tidak ditemukan.";

                            continue 2;
                        }
                        $pengujiDosenIds[] = (int) $d->id;
                    }
                }

                // Path ditulis apa adanya, TANPA dicek ke storage — lihat catatan di docblock
                // import() soal kenapa (berkas boleh menyusul diunggah belakangan).
                $fileRaw = trim((string) ($row[11] ?? ''));

                // Ujian sidang sepenuhnya opsional per baris — lihat catatan di docblock import().
                // Kode Semester Ujian Sidang kosong = jangan sentuh data ujian sidang sama sekali.
                $kodeSemesterSidangRaw = trim((string) ($row[12] ?? ''));
                $semesterSidang = null;
                $statusSidangRaw = '';
                $tanggalMulaiSidang = null;
                $tanggalSelesaiSidang = null;
                $kodePengujiSidangRaw = '';
                $pengujiSidangEntries = [];
                $ketuaSidangKolomDiisi = false;

                if ($kodeSemesterSidangRaw !== '') {
                    $semesterSidang = Semester::where('kode', $kodeSemesterSidangRaw)->first();
                    if (! $semesterSidang) {
                        $errors[] = "Baris {$rowNumber}: Semester ujian sidang dengan kode '{$kodeSemesterSidangRaw}' tidak ditemukan.";

                        continue;
                    }

                    $statusSidangRaw = trim((string) ($row[15] ?? ''));
                    if ($statusSidangRaw !== '' && ! in_array($statusSidangRaw, self::UJIAN_SIDANG_STATUSES, true)) {
                        $errors[] = "Baris {$rowNumber}: Status ujian sidang '{$statusSidangRaw}' tidak valid. Gunakan salah satu: ".implode(', ', self::UJIAN_SIDANG_STATUSES).'.';

                        continue;
                    }

                    $tanggalMulaiSidangRaw = self::normalizeImportDate($row[13] ?? null);
                    $tanggalSelesaiSidangRaw = self::normalizeImportDate($row[14] ?? null);
                    $tanggalMulaiSidang = $tanggalMulaiSidangRaw !== null ? Carbon::parse($tanggalMulaiSidangRaw) : null;
                    $tanggalSelesaiSidang = $tanggalSelesaiSidangRaw !== null ? Carbon::parse($tanggalSelesaiSidangRaw) : null;
                    if ($tanggalMulaiSidang !== null && $tanggalSelesaiSidang !== null && $tanggalSelesaiSidang->lt($tanggalMulaiSidang)) {
                        $errors[] = "Baris {$rowNumber}: Tanggal selesai ujian sidang harus sama atau setelah tanggal mulai.";

                        continue;
                    }

                    $kodePengujiSidangRaw = trim((string) ($row[16] ?? ''));
                    $nilaiPengujiSidangRaw = trim((string) ($row[17] ?? ''));
                    $catatanPengujiSidangRaw = trim((string) ($row[18] ?? ''));
                    $kodeKetuaSidangRaw = trim((string) ($row[19] ?? ''));
                    $ketuaSidangKolomDiisi = $kodeKetuaSidangRaw !== '';

                    if ($ketuaSidangKolomDiisi && $kodePengujiSidangRaw === '') {
                        $errors[] = "Baris {$rowNumber}: Kode Dosen Ketua Sidang diisi tapi Kode Dosen Penguji Sidang kosong.";

                        continue;
                    }

                    if ($kodePengujiSidangRaw !== '') {
                        // Dipakai TANPA dedup (beda dari splitKodeDosenList) supaya urutannya tetap
                        // sejajar dengan Nilai/Catatan Penguji Sidang — lihat catatan di docblock
                        // import(). Kode ganda dianggap error, bukan disaring diam-diam.
                        $kodeListSidang = self::splitKodeDosenListOrdered($kodePengujiSidangRaw);
                        if (count($kodeListSidang) !== count(array_unique($kodeListSidang))) {
                            $errors[] = "Baris {$rowNumber}: Kode Dosen Penguji Sidang mengandung kode yang duplikat.";

                            continue;
                        }

                        if ($ketuaSidangKolomDiisi && ! in_array($kodeKetuaSidangRaw, $kodeListSidang, true)) {
                            $errors[] = "Baris {$rowNumber}: Kode Dosen Ketua Sidang '{$kodeKetuaSidangRaw}' harus salah satu dari Kode Dosen Penguji Sidang.";

                            continue;
                        }

                        // Nilai/Catatan sengaja TIDAK dipecah dengan splitKodeDosenListOrdered —
                        // posisinya harus sejajar apa adanya (index ke-i) dengan kode dosen ke-i,
                        // termasuk slot kosong di tengah (mis. "85,,90").
                        $nilaiListSidang = $nilaiPengujiSidangRaw !== '' ? explode(',', $nilaiPengujiSidangRaw) : [];
                        $catatanListSidang = $catatanPengujiSidangRaw !== '' ? explode('|', $catatanPengujiSidangRaw) : [];

                        foreach ($kodeListSidang as $i => $kd) {
                            $d = Dosen::where('kode_dosen', $kd)->first();
                            if (! $d) {
                                $errors[] = "Baris {$rowNumber}: Dosen penguji sidang — kode '{$kd}' tidak ditemukan.";

                                continue 2;
                            }

                            $nilaiRawItem = trim((string) ($nilaiListSidang[$i] ?? ''));
                            if ($nilaiRawItem !== '' && (! is_numeric($nilaiRawItem) || (float) $nilaiRawItem < 0 || (float) $nilaiRawItem > 999.99)) {
                                $errors[] = "Baris {$rowNumber}: Nilai penguji sidang untuk kode '{$kd}' tidak valid (harus angka 0–999.99).";

                                continue 2;
                            }

                            $catatanRawItem = trim((string) ($catatanListSidang[$i] ?? ''));

                            $pengujiSidangEntries[] = [
                                'id_dosen' => (int) $d->id,
                                'nilai' => $nilaiRawItem !== '' ? (float) $nilaiRawItem : null,
                                'has_nilai' => $nilaiRawItem !== '',
                                'catatan' => $catatanRawItem !== '' ? $catatanRawItem : null,
                                'has_catatan' => $catatanRawItem !== '',
                                'is_ketua' => $ketuaSidangKolomDiisi && $kd === $kodeKetuaSidangRaw,
                            ];
                        }
                    }
                }

                if ($isUpdate) {
                    $updatePayload = [
                        'judul' => $judul,
                        'is_proposal' => $isProposal,
                        'status' => $status,
                        'updated_by' => $actor,
                    ];
                    foreach (['judul_en' => 4, 'topik' => 5, 'topik_en' => 6, 'deskripsi' => 7] as $field => $col) {
                        $raw = trim((string) ($row[$col] ?? ''));
                        if ($raw !== '') {
                            $updatePayload[$field] = $raw;
                        }
                    }
                    if ($fileRaw !== '') {
                        $updatePayload['file'] = ltrim($fileRaw, '/');
                    }

                    $existing->update($updatePayload);
                    $tugasAkhirRow = $existing;
                    $updatedCount++;
                } else {
                    $tugasAkhirRow = TugasAkhir::create([
                        'id_mahasiswa' => $mahasiswa->id,
                        'id_semester' => $semester->id,
                        'judul' => $judul,
                        'judul_en' => self::nullIfBlank($row[4] ?? null),
                        'topik' => self::nullIfBlank($row[5] ?? null),
                        'topik_en' => self::nullIfBlank($row[6] ?? null),
                        'deskripsi' => self::nullIfBlank($row[7] ?? null),
                        'is_proposal' => $isProposal,
                        'file' => $fileRaw !== '' ? ltrim($fileRaw, '/') : null,
                        'status' => $status,
                        'created_by' => $actor,
                        'updated_by' => $actor,
                    ]);
                    $successCount++;
                }

                // Kolom kosong = jangan sentuh pembimbing/penguji yang sudah ada; kolom terisi =
                // sinkronkan daftarnya (dosen lama yang tidak ada di daftar baru dihapus).
                if ($kodePembimbingRaw !== '') {
                    $this->syncTugasAkhirPembimbing($tugasAkhirRow, 'pembimbing', $pembimbingDosenIds, $actor);
                }
                if ($kodePengujiRaw !== '') {
                    $this->syncTugasAkhirPembimbing($tugasAkhirRow, 'penguji', $pengujiDosenIds, $actor);
                }

                // Kode Semester Ujian Sidang kosong = tidak menyentuh data ujian sidang sama sekali.
                if ($kodeSemesterSidangRaw !== '') {
                    $existingUjianSidang = UjianSidang::where('id_tugas_akhir', $tugasAkhirRow->id)
                        ->where('id_semester', $semesterSidang->id)
                        ->first();
                    $isUpdateUjianSidang = $existingUjianSidang !== null;

                    $statusSidang = $statusSidangRaw !== ''
                        ? $statusSidangRaw
                        : ($isUpdateUjianSidang ? $existingUjianSidang->status : 'draft');
                    $mulaiFinal = $tanggalMulaiSidang ?? ($isUpdateUjianSidang ? $existingUjianSidang->tanggal_ujian_mulai : null);
                    $selesaiFinal = $tanggalSelesaiSidang ?? ($isUpdateUjianSidang ? $existingUjianSidang->tanggal_ujian_selesai : null);

                    if ($isUpdateUjianSidang) {
                        $existingUjianSidang->update([
                            'status' => $statusSidang,
                            'tanggal_ujian_mulai' => $mulaiFinal,
                            'tanggal_ujian_selesai' => $selesaiFinal,
                            'updated_by' => $actor,
                        ]);
                        $ujianSidangRow = $existingUjianSidang;
                        $ujianSidangUpdatedCount++;
                    } else {
                        $ujianSidangRow = UjianSidang::create([
                            'id_tugas_akhir' => $tugasAkhirRow->id,
                            'id_semester' => $semesterSidang->id,
                            'tanggal_daftar' => now(),
                            'status' => $statusSidang,
                            'tanggal_ujian_mulai' => $mulaiFinal,
                            'tanggal_ujian_selesai' => $selesaiFinal,
                            'created_by' => $actor,
                            'updated_by' => $actor,
                        ]);
                        $ujianSidangSuccessCount++;
                    }

                    // Kosong = jangan sentuh penguji yang sudah ada (termasuk kalau memang belum
                    // ditentukan); terisi = sinkronkan daftarnya.
                    if ($kodePengujiSidangRaw !== '') {
                        $this->syncUjianSidangPenguji($ujianSidangRow, $pengujiSidangEntries, $ketuaSidangKolomDiisi, $actor);
                    }
                }
            }

            DB::commit();

            $this->result = [
                'success_count' => $successCount,
                'updated_count' => $updatedCount,
                'ujian_sidang_success_count' => $ujianSidangSuccessCount,
                'ujian_sidang_updated_count' => $ujianSidangUpdatedCount,
                'errors' => $errors,
            ];
            $this->reset('file');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import tugas akhir gagal', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->addError('file', 'Terjadi kesalahan saat mengimport data! Harap periksa kembali data yang diimport.');
        }

        $this->processing = false;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Pecah daftar kode dosen: koma, titik koma, atau baris baru. Sama dengan
     * Kelas/Import::splitKodeDosenList.
     *
     * @return list<string>
     */
    private static function splitKodeDosenList(string $raw): array
    {
        $parts = preg_split('/[,;\n\r]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map('trim', $parts ?: []))));
    }

    /**
     * Sama dengan splitKodeDosenList, TAPI TIDAK dedup dan TIDAK filter kosong — dipakai khusus
     * untuk Kode Dosen Penguji Sidang karena urutan & jumlah itemnya harus tetap sejajar (index
     * ke-i) dengan Nilai/Catatan Penguji Sidang. Sama dengan
     * TugasAkhirController::splitKodeDosenListOrdered.
     *
     * @return list<string>
     */
    private static function splitKodeDosenListOrdered(string $raw): array
    {
        $parts = preg_split('/[,;\n\r]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map('trim', $parts ?: []), fn ($v) => $v !== ''));
    }

    /**
     * Terima tanggal dari Excel baik sebagai string, objek tanggal, maupun serial number Excel.
     * Sama dengan TugasAkhirController::normalizeImportDate.
     */
    private static function normalizeImportDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n > 200 && $n < 120_000) {
                try {
                    return ExcelDate::excelToDateTimeObject($n)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }
        if (is_string($value)) {
            $t = trim($value);
            if ($t === '') {
                return null;
            }
            try {
                return Carbon::parse($t)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Sinkronkan tugas_akhir_pembimbing untuk satu peran ('pembimbing' atau 'penguji') ke daftar
     * dosen baru: dosen yang sudah ada (termasuk yang soft-deleted) dipulihkan/dibiarkan, dosen
     * baru dibuat, dan dosen lama yang tidak ada lagi di daftar dihapus. Dipanggil untuk baris baru
     * maupun baris yang di-update — untuk baris baru, query "dosen lama" otomatis kosong sehingga
     * hasilnya sama dengan membuat baris pembimbing satu-satu. Pola sama dengan
     * Kelas/Import::syncKelasDosen.
     *
     * @param  list<int>  $dosenIds
     */
    private function syncTugasAkhirPembimbing(TugasAkhir $tugasAkhir, string $peran, array $dosenIds, string $actor): void
    {
        $dosenIds = array_values(array_unique(array_map('intval', $dosenIds)));

        $rows = TugasAkhirPembimbing::withTrashed()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->where('peran', $peran)
            ->get();
        $byDosen = $rows->keyBy('id_dosen');

        foreach ($dosenIds as $idDosen) {
            $existingRow = $byDosen->get($idDosen);
            if ($existingRow) {
                if ($existingRow->trashed()) {
                    $existingRow->restore();
                    $existingRow->update(['deleted_by' => null, 'updated_by' => $actor]);
                }
            } else {
                TugasAkhirPembimbing::create([
                    'id_tugas_akhir' => $tugasAkhir->id,
                    'id_dosen' => $idDosen,
                    'peran' => $peran,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ]);
            }
        }

        foreach ($rows as $row) {
            if ($row->trashed()) {
                continue;
            }
            if (! in_array((int) $row->id_dosen, $dosenIds, true)) {
                $row->update(['deleted_by' => $actor, 'updated_by' => $actor]);
                $row->delete();
            }
        }
    }

    /**
     * Sinkronkan ujian_sidang_penguji ke daftar dosen baru — sama dengan
     * TugasAkhirController::syncUjianSidangPenguji: field tambahan per dosen (nilai, catatan,
     * is_ketua) ikut aturan blank-cell-berarti-pertahankan-nilai-lama PER DOSEN (`has_nilai`/
     * `has_catatan` menandai kolom itu benar-benar diisi di Excel); is_ketua hanya disentuh untuk
     * seluruh daftar penguji baris ini kalau kolom Kode Dosen Ketua Sidang diisi
     * ($ketuaSidangKolomDiisi). status tetap selalu default 'draft' dan tidak disentuh saat update.
     *
     * @param  list<array{id_dosen: int, nilai: ?float, has_nilai: bool, catatan: ?string, has_catatan: bool, is_ketua: bool}>  $entries
     */
    private function syncUjianSidangPenguji(UjianSidang $ujianSidang, array $entries, bool $ketuaSidangKolomDiisi, string $actor): void
    {
        $dosenIds = array_values(array_unique(array_map(fn (array $e) => (int) $e['id_dosen'], $entries)));

        $rows = UjianSidangPenguji::withTrashed()
            ->where('id_ujian_sidang', $ujianSidang->id)
            ->get();
        $byDosen = $rows->keyBy('id_dosen');

        foreach ($entries as $entry) {
            $idDosen = (int) $entry['id_dosen'];

            $update = ['updated_by' => $actor];
            if ($entry['has_nilai']) {
                $update['nilai'] = $entry['nilai'];
            }
            if ($entry['has_catatan']) {
                $update['catatan'] = $entry['catatan'];
            }
            if ($ketuaSidangKolomDiisi) {
                $update['is_ketua'] = $entry['is_ketua'];
            }

            $existingRow = $byDosen->get($idDosen);
            if ($existingRow) {
                if ($existingRow->trashed()) {
                    $existingRow->restore();
                    $update['deleted_by'] = null;
                }
                $existingRow->update($update);
            } else {
                UjianSidangPenguji::create(array_merge([
                    'id_ujian_sidang' => $ujianSidang->id,
                    'id_dosen' => $idDosen,
                    'is_ketua' => $entry['is_ketua'],
                    'nilai' => $entry['nilai'],
                    'catatan' => $entry['catatan'],
                    'status' => 'draft',
                    'created_by' => $actor,
                ], $update));
            }
        }

        foreach ($rows as $row) {
            if ($row->trashed()) {
                continue;
            }
            if (! in_array((int) $row->id_dosen, $dosenIds, true)) {
                $row->update(['deleted_by' => $actor, 'updated_by' => $actor]);
                $row->delete();
            }
        }
    }

    public function render()
    {
        return view('livewire.admin.tugas-akhir.import')->extends('layouts.web');
    }
}
