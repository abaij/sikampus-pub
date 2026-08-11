<?php

namespace App\Livewire\Admin\KelompokKelas;

use App\Models\KelompokKelas;
use App\Support\PanelAccess;
use Illuminate\Support\Facades\Auth;
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

    public function mount(): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'grup mahasiswa', 'create'), 403, 'Anda tidak memiliki hak untuk mengimport kelas mahasiswa.');
    }

    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }

    /**
     * Sama persis dengan KelompokKelasController::import — hasil ditaruh di $result, bukan JsonResponse.
     */
    public function import(): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'grup mahasiswa', 'create'), 403, 'Anda tidak memiliki hak untuk mengimport kelas mahasiswa.');

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
        $processedNames = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $nama = trim($row[0] ?? '');

                if (empty($nama)) {
                    $errors[] = "Baris {$rowNumber}: Nama wajib diisi.";

                    continue;
                }

                if (strlen($nama) > 255) {
                    $errors[] = "Baris {$rowNumber}: Nama maksimal 255 karakter.";

                    continue;
                }

                $namaLower = strtolower($nama);
                if (in_array($namaLower, $processedNames, true)) {
                    $errors[] = "Baris {$rowNumber}: Nama '{$nama}' duplikat dalam file import.";

                    continue;
                }

                if (KelompokKelas::withTrashed()->where('nama', $nama)->exists()) {
                    $skipCount++;
                    $processedNames[] = $namaLower;

                    continue;
                }

                KelompokKelas::create(['nama' => $nama]);
                $successCount++;
                $processedNames[] = $namaLower;
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

            $this->addError('file', 'Terjadi kesalahan saat import: '.$e->getMessage());
        }

        $this->processing = false;
    }

    public function render()
    {
        return view('livewire.admin.kelompok-kelas.import')->extends('layouts.web');
    }
}
