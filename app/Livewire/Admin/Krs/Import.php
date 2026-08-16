<?php

namespace App\Livewire\Admin\Krs;

use App\Models\Kelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Semester;
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
     * Sama persis dengan KrsController::import. Baris dengan kombinasi (id_mahasiswa, id_kelas)
     * yang sudah ada dilewati (skip), bukan diperbarui — beda dengan Nilai::import yang meng-upsert.
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

        $worksheet = $spreadsheet->getActiveSheet();
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

        $user = Auth::user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;
        $activeSemester = Semester::where('is_active', true)->first();

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $nim = trim((string) ($row[0] ?? ''));
                $kodeMatkul = trim((string) ($row[1] ?? ''));
                $kodeSemester = trim((string) ($row[2] ?? ''));
                $status = trim(strtolower((string) ($row[3] ?? '')));

                if ($nim === '') {
                    $errors[] = "Baris {$rowNumber}: NIM wajib diisi.";

                    continue;
                }

                if ($kodeMatkul === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Mata Kuliah wajib diisi.";

                    continue;
                }

                if ($status !== '' && ! in_array($status, ['pending', 'acc'], true)) {
                    $errors[] = "Baris {$rowNumber}: Status harus 'pending' atau 'acc'.";

                    continue;
                }

                $mahasiswa = Mahasiswa::where('nim', $nim)->first();
                if (! $mahasiswa) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa dengan NIM '{$nim}' tidak ditemukan.";

                    continue;
                }

                // Satu kode mata kuliah bisa dipakai beberapa prodi sekaligus, masing-masing
                // sebagai baris matkul terpisah (mis. 'MKW201' ada di 3 prodi). ->first() polos
                // memilih baris milik prodi mana saja, sehingga pencarian kelas di bawah meleset
                // ke kurikulum prodi lain — kelasnya dilaporkan "tidak ditemukan" padahal ada,
                // atau lebih buruk: mahasiswa masuk ke kelas milik prodi lain lewat fallback.
                $matkul = Matkul::where('kode', $kodeMatkul)->where('id_prodi', $mahasiswa->id_prodi)->first()
                    ?: Matkul::where('kode', $kodeMatkul)->first();
                if (! $matkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah dengan kode '{$kodeMatkul}' tidak ditemukan.";

                    continue;
                }

                if ($kodeSemester !== '') {
                    $semester = Semester::where('kode', $kodeSemester)->first();
                    if (! $semester) {
                        $errors[] = "Baris {$rowNumber}: Semester dengan kode '{$kodeSemester}' tidak ditemukan.";

                        continue;
                    }
                } else {
                    if (! $activeSemester) {
                        $errors[] = "Baris {$rowNumber}: Kode Semester wajib diisi atau set semester aktif di sistem.";

                        continue;
                    }
                    $semester = $activeSemester;
                }

                $kurikulumMatkulList = KurikulumMatkul::where('id_matkul', $matkul->id)->get();
                if ($kurikulumMatkulList->isEmpty()) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah '{$kodeMatkul}' tidak ditemukan dalam kurikulum.";

                    continue;
                }

                // Prioritaskan kelas dari prodi mahasiswa, baru cari tanpa filter prodi.
                $kelas = Kelas::with('prodi')
                    ->whereIn('id_kurikulum_matkul', $kurikulumMatkulList->pluck('id'))
                    ->where('id_semester', $semester->id)
                    ->where('id_prodi', $mahasiswa->id_prodi)
                    ->first();

                if (! $kelas) {
                    $kelas = Kelas::with('prodi')
                        ->whereIn('id_kurikulum_matkul', $kurikulumMatkulList->pluck('id'))
                        ->where('id_semester', $semester->id)
                        ->first();
                }

                if (! $kelas) {
                    $errors[] = "Baris {$rowNumber}: Kelas dengan semester '{$semester->kode}' dan mata kuliah '{$kodeMatkul}' tidak ditemukan.";

                    continue;
                }

                if ($allowedProdiIds !== null) {
                    if (! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                        $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke mahasiswa NIM '{$nim}' (prodi di luar scope).";

                        continue;
                    }
                    if (! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                        // Kelas yang cocok bisa saja berasal dari prodi lain (fallback query di
                        // atas tidak memfilter prodi) — sebutkan prodi kelasnya supaya jelas kenapa
                        // ditolak, bukan cuma "di luar scope".
                        $prodiKelas = $kelas->prodi->nama ?? null;
                        $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke kelas mata kuliah '{$kodeMatkul}' (prodi di luar scope)."
                            .($prodiKelas ? " Kelas ini berasal dari prodi '{$prodiKelas}'." : '');

                        continue;
                    }
                }

                $exists = Krs::where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_kelas', $kelas->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    // Sengaja tidak masuk $errors — ini bukan masalah yang perlu ditinjau admin,
                    // cukup dihitung lewat skip_count (ditampilkan di kartu "Dilewati").
                    $skipCount++;

                    continue;
                }

                $isApproved = ($status ?: 'pending') === 'acc';

                Krs::create([
                    'id_mahasiswa' => $mahasiswa->id,
                    'id_kelas' => $kelas->id,
                    'approved_by' => $isApproved ? ($user->name ?? $user->email ?? null) : null,
                    'approved_at' => $isApproved ? now() : null,
                ]);

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
            Log::error('Import KRS gagal', [
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
        return view('livewire.admin.krs.import')->extends('layouts.web');
    }
}
