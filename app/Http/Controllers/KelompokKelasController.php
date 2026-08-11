<?php

namespace App\Http\Controllers;

use App\Models\KelompokKelas;
use App\Models\Prodi;
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

class KelompokKelasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 100);
        $search = $request->get('search');

        $prodiId = $request->get('id_prodi') ? (int) $request->get('id_prodi') : null;

        $query = KelompokKelas::query()->with('prodi')->withCount('mahasiswa as jumlah_mahasiswa');

        if ($search) {
            $query->where('nama', 'like', "%{$search}%");
        }

        if ($prodiId) {
            $query->where('id_prodi', $prodiId);
        }

        $data = $query->orderBy('nama')->paginate($perPage);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255', 'unique:kelompok_kelas,nama'],
            'id_prodi' => ['nullable', 'integer', 'exists:prodi,id'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $kelompokKelas = KelompokKelas::create($validated);

        return response()->json($kelompokKelas->load('prodi'), 201);
    }

    public function show(KelompokKelas $kelompok_kela): JsonResponse
    {
        return response()->json($kelompok_kela->load('prodi'));
    }

    public function update(Request $request, KelompokKelas $kelompok_kela): JsonResponse
    {
        $validated = $request->validate([
            'nama' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('kelompok_kelas', 'nama')->ignore($kelompok_kela->id),
            ],
            'id_prodi' => ['nullable', 'integer', 'exists:prodi,id'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $kelompok_kela->update($validated);

        return response()->json($kelompok_kela->load('prodi'));
    }

    public function destroy(KelompokKelas $kelompok_kela): JsonResponse
    {
        $kelompok_kela->delete();

        return response()->json(['message' => 'Kelas mahasiswa dihapus']);
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['Nama*', 'Kode Prodi'];
        $sheet->fromArray([$headers], null, 'A1');

        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(20);

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:B1')->applyFromArray($headerStyle);

        $exampleRow = ['Kelompok A', ''];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_kelompok_kelas_'.date('YmdHis').'.xlsx';

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
        $processedNames = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $nama = trim($row[0] ?? '');
                $kodeProdi = trim((string) ($row[1] ?? ''));

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

                $idProdi = null;
                if ($kodeProdi !== '') {
                    $prodi = Prodi::where('kode', $kodeProdi)->first();
                    if (! $prodi) {
                        $errors[] = "Baris {$rowNumber}: Prodi dengan kode '{$kodeProdi}' tidak ditemukan.";

                        continue;
                    }
                    $idProdi = $prodi->id;
                }

                if (KelompokKelas::withTrashed()->where('nama', $nama)->exists()) {
                    $skipCount++;
                    $processedNames[] = $namaLower;

                    continue;
                }

                KelompokKelas::create(['nama' => $nama, 'id_prodi' => $idProdi]);
                $successCount++;
                $processedNames[] = $namaLower;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Import selesai. Berhasil: {$successCount}, Dilewati: {$skipCount}, Error: ".count($errors),
                'success_count' => $successCount,
                'skip_count' => $skipCount,
                'error_count' => count($errors),
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat import: '.$e->getMessage(),
            ], 500);
        }
    }
}
