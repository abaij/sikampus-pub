<?php

namespace App\Livewire\Admin\TugasAkhir;

use App\Models\Mahasiswa;
use App\Models\Semester;
use App\Models\TugasAkhir;
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
     * migrasi, jadi SENGAJA tidak mensyaratkan KRS Tugas Akhir yang disetujui. Modul tugas akhir
     * create-only lewat import (tidak ada update/destroy), jadi baris duplikat (mahasiswa yang
     * sudah punya data tugas akhir di semester yang sama) dilaporkan sebagai error.
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

                $status = $statusRaw === '' ? 'submitted' : $statusRaw;
                if (! in_array($status, self::STATUSES, true)) {
                    $errors[] = "Baris {$rowNumber}: Status '{$statusRaw}' tidak valid. Gunakan salah satu: ".implode(', ', self::STATUSES).'.';

                    continue;
                }

                $duplikat = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_semester', $semester->id)
                    ->exists();
                if ($duplikat) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa NIM '{$nim}' sudah memiliki data tugas akhir untuk semester '{$kodeSemester}'.";

                    continue;
                }

                $isProposal = $isProposalRaw === '' ? true : filter_var($isProposalRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isProposal === null) {
                    $isProposal = true;
                }

                TugasAkhir::create([
                    'id_mahasiswa' => $mahasiswa->id,
                    'id_semester' => $semester->id,
                    'judul' => $judul,
                    'judul_en' => self::nullIfBlank($row[4] ?? null),
                    'topik' => self::nullIfBlank($row[5] ?? null),
                    'topik_en' => self::nullIfBlank($row[6] ?? null),
                    'deskripsi' => self::nullIfBlank($row[7] ?? null),
                    'is_proposal' => $isProposal,
                    'status' => $status,
                    'created_by' => $actor,
                    'updated_by' => $actor,
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

    public function render()
    {
        return view('livewire.admin.tugas-akhir.import')->extends('layouts.web');
    }
}
