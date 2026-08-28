<?php

namespace App\Livewire\Admin\Kelas;

use App\Models\Dosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\KelompokKelas;
use App\Models\Kurikulum;
use App\Models\KurikulumMatkul;
use App\Models\Matkul;
use App\Models\Prodi;
use App\Models\Semester;
use App\Services\KelasAngkatanService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Import extends Component
{
    use WithFileUploads;

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
     * Cek duplikat berdasarkan unique (id_kelompok_kelas, id_kurikulum_matkul, id_semester,
     * id_angkatan) — sama persis dengan KelasController::kelasDuplicateExists.
     */
    private function kelasDuplicateExists(array $data): bool
    {
        $q = Kelas::query()
            ->where('id_kurikulum_matkul', $data['id_kurikulum_matkul'])
            ->where('id_semester', $data['id_semester'])
            ->where('id_angkatan', $data['id_angkatan']);
        if (! empty($data['id_kelompok_kelas'])) {
            $q->where('id_kelompok_kelas', (int) $data['id_kelompok_kelas']);
        } else {
            $q->whereNull('id_kelompok_kelas');
        }

        return $q->exists();
    }

    /**
     * Sama persis dengan KelasController::syncKelasDosen.
     */
    private function syncKelasDosen(Kelas $kelas, ?int $picId, array $timDosenIds): void
    {
        $timIds = array_values(array_unique(array_map('intval', array_filter(
            $timDosenIds,
            fn ($v) => $v !== null && $v !== ''
        ))));
        $picId = $picId ? (int) $picId : null;

        $allIds = $timIds;
        if ($picId !== null && ! in_array($picId, $allIds, true)) {
            $allIds[] = $picId;
        }

        $rows = KelasDosen::withTrashed()->where('id_kelas', $kelas->id)->get();
        $byDosen = $rows->keyBy('id_dosen');

        foreach ($allIds as $dosenId) {
            $isPic = $picId !== null && $dosenId === $picId;
            $existing = $byDosen->get($dosenId);
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                if ((bool) $existing->is_pic !== $isPic) {
                    $existing->update(['is_pic' => $isPic]);
                }
            } else {
                KelasDosen::create([
                    'id_kelas' => $kelas->id,
                    'id_dosen' => $dosenId,
                    'is_pic' => $isPic,
                ]);
            }
        }

        foreach ($rows as $row) {
            if ($row->trashed()) {
                continue;
            }
            if (! in_array((int) $row->id_dosen, $allIds, true)) {
                $row->delete();
            }
        }
    }

    private static function normalizeKodeSemesterCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $f = (float) $value;
            if ($f == floor($f)) {
                return (string) (int) $f;
            }
        }

        return trim((string) $value);
    }

    private static function parseIntCell(mixed $value, ?int $default = null): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return $default;
        }
        if (is_numeric($s)) {
            return (int) round((float) $s);
        }

        return $default;
    }

    private static function parseMingguanCell(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower(trim((string) $value));
        if ($s === '' || in_array($s, ['ya', 'y', 'yes', '1', 'true', 'benar'], true)) {
            return true;
        }
        if (in_array($s, ['tidak', 't', 'no', '0', 'false', 'salah'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Pecah daftar kode dosen (tim): koma, titik koma, atau baris baru.
     *
     * @return list<string>
     */
    private static function splitKodeDosenList(string $raw): array
    {
        $parts = preg_split('/[,;\n\r]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map('trim', $parts ?: []))));
    }

    /**
     * Sama persis dengan KelasController::import — mendukung format baru (13 kolom, kolom A
     * berisi kode prodi) maupun format lama (6 kolom, kolom A berisi kode matkul). Hasil
     * ditaruh di $result, bukan JsonResponse.
     */
    public function import(): void
    {
        $this->result = null;
        $this->processing = true;
        $this->validate();

        $user = Auth::user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;

        try {
            $spreadsheet = IOFactory::load($this->file->getRealPath());
        } catch (\Throwable $e) {
            $this->processing = false;
            $this->addError('file', 'Gagal membaca file Excel. Pastikan format .xlsx/.xls valid; hindari rumus error (#NAME?, #REF!). Salin data ke template lalu tempel sebagai nilai saja jika perlu. Detail: '.$e->getMessage());

            return;
        }

        $worksheet = $spreadsheet->getSheetByName('Data') ?? $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            $this->processing = false;
            $this->addError('file', 'File Excel kosong atau tidak valid.');
            $this->reset('file');

            return;
        }

        array_shift($rows);

        $errors = [];
        $successCount = 0;
        $skipCount = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row, fn ($v) => $v !== null && $v !== '' && trim((string) $v) !== ''))) {
                    continue;
                }

                $col0 = trim((string) ($row[0] ?? ''));
                $col1 = trim((string) ($row[1] ?? ''));
                $prodiByCol0 = $col0 !== '' ? Prodi::where('kode', $col0)->first() : null;

                $kodeMatkul = '';
                $kodeSemester = '';
                $kodeDosen = '';
                $namaKelompokKelas = '';
                $kodeAngkatan = '';
                $kodeKelas = null;
                $jmlPertemuan = 16;
                $isMingguan = true;
                $kuota = 0;
                $timDosenRaw = '';
                $prodi = null;
                $kurikulum = null;
                $isActive = false;

                if ($prodiByCol0) {
                    $prodi = $prodiByCol0;
                    if ($col1 === '') {
                        $errors[] = "Baris {$rowNumber}: Kode kurikulum (kolom B) wajib diisi untuk format baru.";

                        continue;
                    }
                    $kurikulum = Kurikulum::where('id_prodi', $prodi->id)->where('kode', $col1)->first();
                    if (! $kurikulum) {
                        $errors[] = "Baris {$rowNumber}: Kurikulum dengan kode '{$col1}' untuk prodi '{$col0}' tidak ditemukan.";

                        continue;
                    }
                    $kodeMatkul = trim((string) ($row[2] ?? ''));
                    $kodeSemester = self::normalizeKodeSemesterCell($row[3] ?? null);
                    $kKelas = trim((string) ($row[4] ?? ''));
                    if ($kKelas !== '') {
                        if (mb_strlen($kKelas) > 255) {
                            $errors[] = "Baris {$rowNumber}: Kode kelas maksimal 255 karakter.";

                            continue;
                        }
                        $kodeKelas = $kKelas;
                    }
                    $jmlPertemuan = self::parseIntCell($row[5] ?? null, 16) ?? 16;
                    if ($jmlPertemuan < 1 || $jmlPertemuan > 99) {
                        $errors[] = "Baris {$rowNumber}: Jumlah pertemuan harus antara 1 dan 99.";

                        continue;
                    }
                    $m = self::parseMingguanCell($row[6] ?? null);
                    $isMingguan = $m !== null ? $m : true;
                    $kuota = self::parseIntCell($row[7] ?? null, 0) ?? 0;
                    if ($kuota < 0 || $kuota > 32767) {
                        $errors[] = "Baris {$rowNumber}: Kuota harus 0–32767.";

                        continue;
                    }
                    $kodeDosen = trim((string) ($row[8] ?? ''));
                    $timDosenRaw = trim((string) ($row[9] ?? ''));
                    $namaKelompokKelas = trim((string) ($row[10] ?? ''));
                    $kodeAngkatan = self::normalizeKodeSemesterCell($row[11] ?? null);
                    $activeParsed = self::parseMingguanCell($row[12] ?? null);
                    $isActive = $activeParsed !== null ? $activeParsed : false;
                } else {
                    $kodeMatkul = trim((string) ($row[0] ?? ''));
                    $kodeProdi = trim((string) ($row[1] ?? ''));
                    $kodeSemester = self::normalizeKodeSemesterCell($row[2] ?? null);
                    $kodeDosen = trim((string) ($row[3] ?? ''));
                    $namaKelompokKelas = trim((string) ($row[4] ?? ''));
                    $kodeAngkatan = self::normalizeKodeSemesterCell($row[5] ?? null);

                    if ($kodeMatkul === '') {
                        $errors[] = "Baris {$rowNumber}: Kode mata kuliah wajib diisi (format lama: kolom A = kode MK, B = kode prodi).";

                        continue;
                    }
                    if ($kodeProdi === '') {
                        $errors[] = "Baris {$rowNumber}: Kode prodi wajib diisi.";

                        continue;
                    }
                    $prodi = Prodi::where('kode', $kodeProdi)->first();
                    if (! $prodi) {
                        $errors[] = "Baris {$rowNumber}: Prodi dengan kode '{$kodeProdi}' tidak ditemukan.";

                        continue;
                    }

                    $kurikulum = Kurikulum::query()
                        ->join('semester as sem_th', 'sem_th.id', '=', 'kurikulum.id_tahun_berlaku')
                        ->where('kurikulum.id_prodi', $prodi->id)
                        ->where('kurikulum.status', 'active')
                        ->orderBy('sem_th.kode', 'desc')
                        ->select('kurikulum.*')
                        ->first();

                    if (! $kurikulum) {
                        $kurikulum = Kurikulum::query()
                            ->join('semester as sem_th', 'sem_th.id', '=', 'kurikulum.id_tahun_berlaku')
                            ->where('kurikulum.id_prodi', $prodi->id)
                            ->orderBy('sem_th.kode', 'desc')
                            ->select('kurikulum.*')
                            ->first();
                    }

                    if (! $kurikulum) {
                        $errors[] = "Baris {$rowNumber}: Kurikulum untuk prodi '{$kodeProdi}' tidak ditemukan.";

                        continue;
                    }
                }

                if ($kodeMatkul === '') {
                    $errors[] = "Baris {$rowNumber}: Kode mata kuliah wajib diisi.";

                    continue;
                }

                if ($kodeSemester === '') {
                    $errors[] = "Baris {$rowNumber}: Kode semester berjalan wajib diisi (sama dengan kode di master Semester, mis. 20241).";

                    continue;
                }

                $matkul = Matkul::where('kode', $kodeMatkul)
                    ->whereHas('prodi', function ($query) use ($prodi) {
                        $query->where('id', $prodi->id);
                    })->first();

                if (! $matkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah dengan kode '{$kodeMatkul}' untuk prodi '{$prodi->kode}' tidak ditemukan.";

                    continue;
                }

                $kurikulumMatkul = KurikulumMatkul::where('id_matkul', $matkul->id)
                    ->where('id_kurikulum', $kurikulum->id)
                    ->first();

                if (! $kurikulumMatkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah '{$kodeMatkul}' tidak ada pada kurikulum '{$kurikulum->kode}'.";

                    continue;
                }

                $semester = Semester::where('kode', $kodeSemester)->first();
                if (! $semester) {
                    $hint = preg_match('/^\d{4}$/', $kodeSemester) ? ' Gunakan kode semester lengkap (bukan hanya tahun).' : '';
                    $errors[] = "Baris {$rowNumber}: Semester berjalan dengan kode '{$kodeSemester}' tidak ditemukan.{$hint}";

                    continue;
                }

                if ($allowedProdiIds !== null && ! in_array((int) $prodi->id, $allowedProdiIds, true)) {
                    $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke program studi '{$prodi->kode}'.";
                    $skipCount++;

                    continue;
                }

                $dosenId = null;
                if ($kodeDosen !== '') {
                    $dosen = Dosen::where('kode_dosen', $kodeDosen)->first();
                    if (! $dosen) {
                        $errors[] = "Baris {$rowNumber}: Dosen PIC dengan kode '{$kodeDosen}' tidak ditemukan.";

                        continue;
                    }
                    $dosenId = $dosen->id;
                }

                $kelompokKelasId = null;
                if ($namaKelompokKelas !== '') {
                    $kelompokKelas = KelompokKelas::where('nama', $namaKelompokKelas)->first();
                    if (! $kelompokKelas) {
                        $errors[] = "Baris {$rowNumber}: Kelas mahasiswa '{$namaKelompokKelas}' tidak ditemukan.";

                        continue;
                    }
                    $kelompokKelasId = $kelompokKelas->id;
                }

                // id_angkatan = semester masuk mahasiswa, bukan semester berjalan. Kolom kosong
                // diturunkan dari kelas mahasiswa yang dipilih; kalau tidak bisa (kelompok kosong
                // atau angkatannya campuran) baru jatuh ke semester berjalan.
                $semesterAngkatan = $semester;
                if ($kodeAngkatan !== '') {
                    $semesterAngkatan = Semester::where('kode', $kodeAngkatan)->first();
                    if (! $semesterAngkatan) {
                        $errors[] = "Baris {$rowNumber}: Angkatan/semester dengan kode '{$kodeAngkatan}' tidak ditemukan.";

                        continue;
                    }
                } else {
                    $saranAngkatan = KelasAngkatanService::angkatanSaranForKelompokKelas($kelompokKelasId);
                    if ($saranAngkatan !== null) {
                        $semesterAngkatan = Semester::find($saranAngkatan) ?? $semester;
                    }
                }

                $pesanAngkatan = KelasAngkatanService::pesanKetidakcocokan($kelompokKelasId, (int) $semesterAngkatan->id);
                if ($pesanAngkatan !== null) {
                    $errors[] = "Baris {$rowNumber}: {$pesanAngkatan}";

                    continue;
                }

                $kelasData = [
                    'kode' => $kodeKelas,
                    'id_kurikulum_matkul' => $kurikulumMatkul->id,
                    'id_prodi' => $prodi->id,
                    'id_semester' => $semester->id,
                    'id_angkatan' => $semesterAngkatan->id,
                    'id_dosen_pic' => $dosenId,
                    'id_kelompok_kelas' => $kelompokKelasId,
                    'jml_pertemuan' => $jmlPertemuan,
                    'is_mingguan' => $isMingguan,
                    'kuota' => $kuota,
                    'is_active' => $isActive,
                ];

                if ($this->kelasDuplicateExists($kelasData)) {
                    $skipCount++;
                    $errors[] = "Baris {$rowNumber}: Kelas dengan kombinasi yang sama sudah ada (diabaikan).";

                    continue;
                }

                $dosenTimIdsResolved = [];
                if ($timDosenRaw !== '') {
                    foreach (self::splitKodeDosenList($timDosenRaw) as $kd) {
                        $d = Dosen::where('kode_dosen', $kd)->first();
                        if (! $d) {
                            $errors[] = "Baris {$rowNumber}: Tim dosen — kode '{$kd}' tidak ditemukan.";

                            continue 2;
                        }
                        $dosenTimIdsResolved[] = (int) $d->id;
                    }
                }

                $kelas = Kelas::create($kelasData);
                $this->syncKelasDosen($kelas, $dosenId ? (int) $dosenId : null, $dosenTimIdsResolved);

                $successCount++;
            }

            DB::commit();

            $this->result = [
                'success_count' => $successCount,
                'skip_count' => $skipCount,
                'errors' => $errors,
            ];
            $this->reset('file');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import kelas gagal', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->addError('file', 'Terjadi kesalahan saat mengimport data! Harap periksa kembali data yang diimport.');
        }

        $this->processing = false;
    }

    public function render()
    {
        return view('livewire.admin.kelas.import')->extends('layouts.web');
    }
}
