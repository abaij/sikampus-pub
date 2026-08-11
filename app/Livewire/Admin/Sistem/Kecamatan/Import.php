<?php

namespace App\Livewire\Admin\Sistem\Kecamatan;

use App\Models\Kecamatan;
use App\Models\Kota;
use Illuminate\Support\Facades\DB;
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
     * Sama persis dengan KecamatanController::import (kolom A = kode, B = nama, C = kode kota) —
     * hasil ditaruh di $result, bukan JsonResponse.
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
            $this->addError('file', 'Gagal membaca file Excel. Pastikan format .xlsx/.xls valid. Detail: '.$e->getMessage());

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

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $kode = trim((string) ($row[0] ?? ''));
                $nama = trim((string) ($row[1] ?? ''));
                $kodeKota = trim((string) ($row[2] ?? ''));

                if ($kode === '') {
                    $errors[] = "Baris {$rowNumber}: Kode wajib diisi.";

                    continue;
                }
                if ($nama === '') {
                    $errors[] = "Baris {$rowNumber}: Nama wajib diisi.";

                    continue;
                }
                if ($kodeKota === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Kota wajib diisi.";

                    continue;
                }

                $kota = Kota::where('kode', $kodeKota)->first();
                if (! $kota) {
                    $errors[] = "Baris {$rowNumber}: Kota dengan kode '{$kodeKota}' tidak ditemukan.";

                    continue;
                }

                if (Kecamatan::where('kode', $kode)->exists()) {
                    $skipCount++;
                    $errors[] = "Baris {$rowNumber}: Kecamatan dengan kode '{$kode}' sudah ada (diabaikan).";

                    continue;
                }

                Kecamatan::create(['kode' => $kode, 'nama' => $nama, 'id_kota' => $kota->id]);
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

            $this->addError('file', 'Terjadi kesalahan saat mengimport data: '.$e->getMessage());
        }

        $this->processing = false;
    }

    public function render()
    {
        return view('livewire.admin.sistem.kecamatan.import')->extends('layouts.web');
    }
}
