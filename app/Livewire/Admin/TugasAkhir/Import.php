<?php

namespace App\Livewire\Admin\TugasAkhir;

use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\Semester;
use App\Models\TugasAkhir;
use App\Models\TugasAkhirPembimbing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Import extends Component
{
    use WithFileUploads;

    /** Sama dengan TugasAkhirController::STATUSES. */
    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'returned'];

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
            }

            DB::commit();

            $this->result = [
                'success_count' => $successCount,
                'updated_count' => $updatedCount,
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

    public function render()
    {
        return view('livewire.admin.tugas-akhir.import')->extends('layouts.web');
    }
}
