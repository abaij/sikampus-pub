<?php

namespace App\Livewire\Admin\Yudisium;

use App\Models\JenisKeluar;
use App\Models\Mahasiswa;
use App\Models\Yudisium;
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
     * Sama persis dengan YudisiumController::import. Modul yudisium create-only (tidak ada
     * update/destroy), jadi baris duplikat (kombinasi mahasiswa + jenis keluar yang sudah ada)
     * dilaporkan sebagai error, bukan diperbarui.
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

        $user = Auth::user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $nim = trim((string) ($row[0] ?? ''));
                $namaJenisKeluar = trim((string) ($row[1] ?? ''));
                $ipkRaw = trim((string) ($row[6] ?? ''));

                if ($nim === '') {
                    $errors[] = "Baris {$rowNumber}: NIM wajib diisi.";

                    continue;
                }

                if ($namaJenisKeluar === '') {
                    $errors[] = "Baris {$rowNumber}: Jenis Keluar wajib diisi.";

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

                $jenisKeluar = JenisKeluar::where('nama', $namaJenisKeluar)->first();
                if (! $jenisKeluar) {
                    $errors[] = "Baris {$rowNumber}: Jenis Keluar '{$namaJenisKeluar}' tidak ditemukan.";

                    continue;
                }

                $duplikat = Yudisium::withTrashed()
                    ->where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_jenis_keluar', $jenisKeluar->id)
                    ->exists();
                if ($duplikat) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa NIM '{$nim}' sudah memiliki data yudisium dengan jenis keluar '{$namaJenisKeluar}'.";

                    continue;
                }

                $ipk = null;
                if ($ipkRaw !== '') {
                    $ipkValue = filter_var($ipkRaw, FILTER_VALIDATE_FLOAT);
                    if ($ipkValue === false) {
                        $errors[] = "Baris {$rowNumber}: IPK '{$ipkRaw}' tidak valid.";

                        continue;
                    }
                    if ($ipkValue < 0 || $ipkValue > 4.0) {
                        $errors[] = "Baris {$rowNumber}: IPK '{$ipkRaw}' harus di antara 0 dan 4.00.";

                        continue;
                    }
                    $ipk = $ipkValue;
                }

                Yudisium::create([
                    'id_mahasiswa' => $mahasiswa->id,
                    'id_jenis_keluar' => $jenisKeluar->id,
                    'tgl_keluar' => self::normalizeImportDate($row[2] ?? null),
                    'no_ijazah' => self::nullIfBlank($row[3] ?? null),
                    'no_sk_yudisium' => self::nullIfBlank($row[4] ?? null),
                    'tanggal_sk_yudisium' => self::normalizeImportDate($row[5] ?? null),
                    'ipk' => $ipk,
                    'judul_skripsi' => self::nullIfBlank($row[7] ?? null),
                    'keterangan' => self::nullIfBlank($row[8] ?? null),
                ]);
                $successCount++;
            }

            DB::commit();

            $this->result = [
                'success_count' => $successCount,
                'errors' => $errors,
            ];
            $this->reset('file');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import yudisium gagal', [
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
     * Terima tanggal dari Excel baik sebagai string, objek tanggal, maupun serial number Excel.
     * Sama dengan pola normalizeImportDate di Mahasiswa/Import & MahasiswaController.
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

    public function render()
    {
        return view('livewire.admin.yudisium.import')->extends('layouts.web');
    }
}
