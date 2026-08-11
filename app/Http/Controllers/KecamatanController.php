<?php

namespace App\Http\Controllers;

use App\Models\Kecamatan;
use App\Models\Kota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KecamatanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 100);
        $search = $request->get('search');
        $idKota = $request->get('id_kota');

        $query = Kecamatan::query();

        if ($request->get('with') === 'parent') {
            $query->with('kota.provinsi.negara');
        }

        if ($search) {
            $query->where('nama', 'like', "%{$search}%");
        }

        if ($idKota) {
            $query->where('id_kota', $idKota);
        }

        $data = $query->orderBy('nama')->paginate($perPage);

        return response()->json($data);
    }

    public function show(Kecamatan $kecamatan): JsonResponse
    {
        $kecamatan->load('kota.provinsi.negara');

        return response()->json($kecamatan);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'kode' => ['required', 'string', 'max:50', 'unique:kecamatan,kode'],
            'id_kota' => ['nullable', 'exists:kota,id'],
        ]);

        $kecamatan = Kecamatan::create($validated);

        return response()->json($kecamatan, 201);
    }

    public function update(Request $request, Kecamatan $kecamatan): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'kode' => ['required', 'string', 'max:50', Rule::unique('kecamatan', 'kode')->ignore($kecamatan->id)],
            'id_kota' => ['nullable', 'exists:kota,id'],
        ]);

        $kecamatan->update($validated);

        return response()->json($kecamatan);
    }

    public function destroy(Kecamatan $kecamatan): JsonResponse
    {
        $kecamatan->delete();

        return response()->json(['message' => 'Deleted'], 200);
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kecamatan');

        $headers = [
            'Kode',
            'Nama',
            'Kode Kota',
        ];
        $sheet->fromArray([$headers], null, 'A1');

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(35);
        $sheet->getColumnDimension('C')->setWidth(20);

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);

        $exampleRow = [
            '327301',
            'Bandung Wetan',
            '3273',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_kecamatan_'.date('YmdHis').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'File Excel kosong atau tidak valid.',
            ], 400);
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

                $exists = Kecamatan::where('kode', $kode)->exists();
                if ($exists) {
                    $skipCount++;
                    $errors[] = "Baris {$rowNumber}: Kecamatan dengan kode '{$kode}' sudah ada (diabaikan).";

                    continue;
                }

                Kecamatan::create([
                    'kode' => $kode,
                    'nama' => $nama,
                    'id_kota' => $kota->id,
                ]);
                $successCount++;
            }

            if ($successCount === 0 && ! empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang berhasil diimport.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            $message = "Import selesai. Berhasil: {$successCount}";
            if ($skipCount > 0) {
                $message .= ", Diabaikan: {$skipCount}";
            }
            if (count($errors) > 0) {
                $message .= '. Terdapat '.count($errors).' error.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'success_count' => $successCount,
                    'skip_count' => $skipCount,
                    'error_count' => count($errors),
                ],
                'errors' => $errors,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengimport data: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }
}
