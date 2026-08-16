<?php

namespace App\Livewire\Admin\Nilai;

use App\Models\Kelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Nilai;
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
     * Sama persis dengan NilaiController::import. Baris dengan KRS yang sudah punya nilai
     * akan MEMPERBARUI nilai itu (bukan dilewati) — dihitung sebagai "Diperbarui", bukan
     * "Dilewati", meniru arti updated_count/success_count pada respons controller-nya.
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
        $updatedCount = 0;

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
                $kodeMatkul = trim((string) ($row[1] ?? ''));
                $kodeSemester = trim((string) ($row[2] ?? ''));
                $angkaMutu = trim((string) ($row[3] ?? ''));
                $hurufMutu = trim((string) ($row[4] ?? ''));
                $isFinal = trim(strtolower((string) ($row[5] ?? 'false')));

                if ($nim === '') {
                    $errors[] = "Baris {$rowNumber}: NIM wajib diisi.";

                    continue;
                }

                if ($kodeMatkul === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Mata Kuliah wajib diisi.";

                    continue;
                }

                if ($kodeSemester === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Semester wajib diisi.";

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
                // ke kurikulum prodi lain — kelasnya dilaporkan "tidak ditemukan" padahal ada.
                $matkul = Matkul::where('kode', $kodeMatkul)->where('id_prodi', $mahasiswa->id_prodi)->first()
                    ?: Matkul::where('kode', $kodeMatkul)->first();
                if (! $matkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah dengan kode '{$kodeMatkul}' tidak ditemukan.";

                    continue;
                }

                $semester = Semester::where('kode', $kodeSemester)->first();
                if (! $semester) {
                    $errors[] = "Baris {$rowNumber}: Semester dengan kode '{$kodeSemester}' tidak ditemukan.";

                    continue;
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
                    $errors[] = "Baris {$rowNumber}: Kelas dengan semester '{$kodeSemester}' dan mata kuliah '{$kodeMatkul}' tidak ditemukan.";

                    continue;
                }

                $krs = Krs::where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_kelas', $kelas->id)
                    ->whereNull('deleted_at')
                    ->first();

                if (! $krs) {
                    // Kelas yang cocok bisa saja berasal dari prodi lain (fallback query di atas
                    // tidak memfilter prodi) — sebutkan prodi kelasnya supaya jelas kenapa KRS
                    // mahasiswa tidak nyambung ke kelas ini, bukan cuma "tidak ditemukan".
                    $prodiKelas = $kelas->prodi->nama ?? null;
                    $errors[] = "Baris {$rowNumber}: KRS dengan NIM '{$nim}', mata kuliah '{$kodeMatkul}', dan semester '{$kodeSemester}' tidak ditemukan."
                        .($prodiKelas ? " Kelas yang cocok ditemukan pada prodi '{$prodiKelas}'." : '');

                    continue;
                }

                if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke mahasiswa NIM '{$nim}' (prodi di luar scope).";

                    continue;
                }

                $nilaiData = [
                    'id_krs' => $krs->id,
                    'sks' => $matkul->sks ?? null,
                ];

                if ($angkaMutu !== '') {
                    $angkaMutuValue = filter_var($angkaMutu, FILTER_VALIDATE_FLOAT);
                    if ($angkaMutuValue === false) {
                        $errors[] = "Baris {$rowNumber}: Angka Mutu '{$angkaMutu}' tidak valid.";

                        continue;
                    }
                    $nilaiData['angka_mutu'] = $angkaMutuValue;
                }

                if ($hurufMutu !== '') {
                    $nilaiData['huruf_mutu'] = strtoupper($hurufMutu);
                }

                $isFinalValue = filter_var($isFinal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $nilaiData['is_final'] = $isFinalValue === null ? false : $isFinalValue;

                $existingNilai = Nilai::where('id_krs', $krs->id)->whereNull('deleted_at')->first();

                if ($existingNilai) {
                    $existingNilai->update($nilaiData);
                    $updatedCount++;
                } else {
                    Nilai::create($nilaiData);
                    $successCount++;
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
            Log::error('Import nilai gagal', [
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
        return view('livewire.admin.nilai.import')->extends('layouts.web');
    }
}
