<?php

namespace App\Http\Controllers;

use App\Models\JenisKeluar;
use App\Models\Mahasiswa;
use App\Models\Yudisium;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class YudisiumController extends Controller
{
    /**
     * Query daftar yudisium sesuai filter (sama untuk index & kedua export).
     */
    private function buildYudisiumQuery(Request $request)
    {
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi') ? (int) $request->get('id_prodi') : null;
        $semesterMasukId = $request->get('id_semester_masuk') ? (int) $request->get('id_semester_masuk') : null;
        $noSkYudisium = $request->get('no_sk_yudisium');

        $query = Yudisium::with([
            'mahasiswa.prodi',
            'mahasiswa.semester_masuk',
            'jenis_keluar',
        ]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('mahasiswa', function ($q) use ($allowedProdiIds) {
                    $q->whereIn('id_prodi', $allowedProdiIds);
                });
                if ($prodiId !== null && ! in_array($prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('mahasiswa', function ($mahasiswaQuery) use ($search) {
                    $mahasiswaQuery->where('nama', 'like', "%{$search}%")
                        ->orWhere('nim', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                    ->orWhere('no_ijazah', 'like', "%{$search}%")
                    ->orWhere('no_sk_yudisium', 'like', "%{$search}%")
                    ->orWhere('judul_skripsi', 'like', "%{$search}%");
            });
        }

        if ($prodiId !== null) {
            $query->whereHas('mahasiswa', function ($q) use ($prodiId) {
                $q->where('id_prodi', $prodiId);
            });
        }

        if ($semesterMasukId !== null) {
            $query->whereHas('mahasiswa', function ($q) use ($semesterMasukId) {
                $q->where('id_semester_masuk', $semesterMasukId);
            });
        }

        if ($noSkYudisium) {
            $query->where('no_sk_yudisium', $noSkYudisium);
        }

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $data = $this->buildYudisiumQuery($request)->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($data);
    }

    /**
     * Cetak lampiran SK Yudisium (PDF) — daftar mahasiswa sesuai filter (mis. per no_sk_yudisium
     * untuk cetak satu SK tertentu, atau per prodi/periode untuk rekap yudisium).
     */
    public function exportPdf(Request $request): StreamedResponse
    {
        $rows = $this->buildYudisiumQuery($request)->orderBy('created_at')->get();

        $noSk = $request->get('no_sk_yudisium');
        $subtitle = $noSk ? 'No. SK: '.$noSk : 'Sesuai filter yang dipilih';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; }
        .title { text-align: center; font-size: 13pt; font-weight: bold; margin-bottom: 4px; }
        .subtitle { text-align: center; font-size: 10pt; margin-bottom: 12px; }
        table.peserta { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.peserta th { background-color: #334155; color: #fff; padding: 6px 4px; border: 1px solid #000; font-size: 8pt; text-align: center; }
        table.peserta td { padding: 4px; border: 1px solid #000; font-size: 8pt; vertical-align: top; }
        table.peserta td.num, table.peserta td.ipk { text-align: center; }
        .footer { margin-top: 14px; font-size: 8pt; text-align: right; color: #444; }
        </style></head><body>';

        $html .= '<div class="title">LAMPIRAN SURAT KEPUTUSAN YUDISIUM</div>';
        $html .= '<div class="subtitle">'.htmlspecialchars($subtitle).'</div>';

        $html .= '<table class="peserta"><thead><tr>
            <th style="width:30px">No</th>
            <th>NIM</th>
            <th>Nama</th>
            <th>Program Studi</th>
            <th style="width:50px">IPK</th>
            <th>Jenis Keluar</th>
            <th>Judul Skripsi/TA</th>
        </tr></thead><tbody>';

        if ($rows->isEmpty()) {
            $html .= '<tr><td colspan="7" style="text-align:center">Tidak ada data sesuai filter.</td></tr>';
        } else {
            $no = 1;
            foreach ($rows as $row) {
                $m = $row->mahasiswa;
                $prodiText = $m?->prodi ? ($m->prodi->nama.($m->prodi->kode ? ' ('.$m->prodi->kode.')' : '')) : '—';
                $html .= '<tr>'
                    .'<td class="num">'.$no.'</td>'
                    .'<td>'.htmlspecialchars($m?->nim ?? '—').'</td>'
                    .'<td>'.htmlspecialchars($m?->nama ?? '—').'</td>'
                    .'<td>'.htmlspecialchars($prodiText).'</td>'
                    .'<td class="ipk">'.htmlspecialchars($row->ipk !== null ? number_format((float) $row->ipk, 2) : '—').'</td>'
                    .'<td>'.htmlspecialchars($row->jenis_keluar?->nama ?? '—').'</td>'
                    .'<td>'.htmlspecialchars($row->judul_skripsi ?? '—').'</td>'
                    .'</tr>';
                $no++;
            }
        }

        $html .= '</tbody></table>';
        $html .= '<div class="footer">Dicetak: '.now()->format('d/m/Y H:i').'</div></body></html>';

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'lampiran_sk_yudisium_'.date('Y-m-d_His').'.pdf';

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Unduh daftar yudisium sesuai filter (Excel).
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $rows = $this->buildYudisiumQuery($request)->orderBy('created_at')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Yudisium');

        $headers = ['No', 'NIM', 'Nama', 'Program Studi', 'IPK', 'Jenis Keluar', 'No. SK Yudisium', 'Tanggal SK', 'No. Ijazah', 'Judul Skripsi/TA'];
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $rowNum = 2;
        $no = 1;
        foreach ($rows as $row) {
            $m = $row->mahasiswa;
            $prodiText = $m?->prodi ? ($m->prodi->nama.($m->prodi->kode ? ' ('.$m->prodi->kode.')' : '')) : '—';
            $sheet->fromArray([[
                $no,
                $m?->nim ?? '—',
                $m?->nama ?? '—',
                $prodiText,
                $row->ipk !== null ? (float) $row->ipk : '—',
                $row->jenis_keluar?->nama ?? '—',
                $row->no_sk_yudisium ?? '—',
                $row->tanggal_sk_yudisium ?? '—',
                $row->no_ijazah ?? '—',
                $row->judul_skripsi ?? '—',
            ]], null, 'A'.$rowNum);
            $rowNum++;
            $no++;
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'yudisium_'.date('Y-m-d_His').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function show(Request $request, Yudisium $yudisium): JsonResponse
    {
        $yudisium->load([
            'mahasiswa.prodi',
            'mahasiswa.semester_masuk',
            'mahasiswa.status_akademik',
            'mahasiswa.grup_mahasiswa',
            'jenis_keluar',
        ]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && $yudisium->mahasiswa && ! in_array((int) $yudisium->mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data yudisium ini.');
            }
        }

        return response()->json([
            'success' => true,
            'data' => $yudisium,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_mahasiswa' => ['required', 'integer', 'exists:mahasiswa,id'],
            'id_jenis_keluar' => [
                'required',
                'integer',
                'exists:jenis_keluar,id',
                Rule::unique('yudisium', 'id_jenis_keluar')->where(function ($query) use ($request) {
                    return $query->where('id_mahasiswa', $request->id_mahasiswa);
                }),
            ],
            'tgl_keluar' => ['nullable', 'string', 'max:255'],
            'no_ijazah' => ['nullable', 'string', 'max:255'],
            'no_sk_yudisium' => ['nullable', 'string', 'max:255'],
            'tanggal_sk_yudisium' => ['nullable', 'string', 'max:255'],
            'ipk' => ['nullable', 'numeric', 'min:0', 'max:4.00'],
            'judul_skripsi' => ['nullable', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
        ], [
            'id_jenis_keluar.unique' => 'Mahasiswa ini sudah memiliki yudisium dengan jenis keluar yang sama.',
        ]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $mahasiswa = Mahasiswa::find($validated['id_mahasiswa']);
            if ($mahasiswa) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke mahasiswa prodi ini.');
                }
            }
        }

        try {
            DB::beginTransaction();

            $yudisium = Yudisium::create($validated);

            $yudisium->load([
                'mahasiswa.prodi',
                'mahasiswa.semester_masuk',
                'mahasiswa.status_akademik',
                'mahasiswa.grup_mahasiswa',
                'jenis_keluar',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data yudisium berhasil dibuat',
                'data' => $yudisium,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat data yudisium: '.$e->getMessage(),
            ], 500);
        }
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'NIM*',
            'Jenis Keluar*',
            'Tanggal Keluar (Opsional)',
            'No. Ijazah (Opsional)',
            'No. SK Yudisium (Opsional)',
            'Tanggal SK Yudisium (Opsional)',
            'IPK (Opsional)',
            'Judul Skripsi/TA (Opsional)',
            'Keterangan (Opsional)',
        ];
        $sheet->fromArray([$headers], null, 'A1');

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(24);
        }

        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $exampleRow = [
            '2020001',
            'Lulus',
            '2026-05-04',
            '009/IJZ/UNMI/V/2026',
            '0909/UNMI/VI/2026',
            '2026-04-15',
            '3.85',
            'Sistem Informasi Akademik Berbasis Web',
            '',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_yudisium_'.date('YmdHis').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Import massal data yudisium dari Excel. Setiap baris memvalidasi ulang aturan yang sama
     * dengan store(): mahasiswa & jenis keluar wajib ada, kombinasi keduanya unik per mahasiswa,
     * dan scope prodi admin dihormati. Modul yudisium create-only (tidak ada update/destroy),
     * jadi baris duplikat dilaporkan sebagai error, bukan diperbarui.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (count($rows) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'File Excel kosong atau tidak valid.',
            ], 400);
        }

        array_shift($rows);

        $errors = [];
        $successCount = 0;

        $user = $request->user();
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

            return response()->json([
                'success' => true,
                'message' => "Import selesai. Berhasil: {$successCount}, Error: ".count($errors),
                'success_count' => $successCount,
                'error_count' => count($errors),
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import yudisium gagal', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengimpor data: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Terima tanggal dari Excel baik sebagai string, objek tanggal, maupun serial number Excel.
     * Sama dengan pola normalizeImportDate di MahasiswaController.
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
}
