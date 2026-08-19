<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\Semester;
use App\Models\Setting;
use App\Services\TranskripPdfGenerator;
use App\Services\UrutanMatkulService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export (xlsx) & cetak (PDF, tab baru) untuk halaman detail nilai — dipanggil dari tombol
 * Export/Cetak di App\Livewire\Admin\Nilai\Show. Tombol Cetak menawarkan dua bentuk:
 * pdf() = "Laporan Nilai" (mengikuti filter semester di layar, menampilkan seluruh KRS termasuk
 * yang belum dinilai) dan transkrip() = "Transkrip Nilai" (dokumen resmi dwibahasa, selalu
 * seluruh masa studi, hanya mata kuliah bernilai final). Logikanya sengaja disalin ulang dari
 * NilaiController::exportNilaiMahasiswa/exportNilaiMahasiswaPdf (bukan di-share), sama seperti
 * KrsCetakController terhadap KrsController — lihat skill siak-livewire-module.
 */
class NilaiExportController extends Controller
{
    private function loadData(int $idMahasiswa, Request $request): array
    {
        $search = $request->get('search');
        $semesterId = $request->get('id_semester');

        $mahasiswa = Mahasiswa::with(['prodi', 'semester_masuk'])->findOrFail($idMahasiswa);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data nilai mahasiswa ini.');
            }
        }

        $query = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
        ])
            ->where('id_mahasiswa', $idMahasiswa)
            ->whereNull('deleted_at');

        if ($semesterId) {
            $query->whereHas('kelas', fn ($q) => $q->where('id_semester', $semesterId));
        }

        if ($search) {
            $query->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('kode', 'like', "%{$search}%");
            });
        }

        // Diurutkan berdasarkan nama mata kuliah; created_at tetap jadi tie-breaker karena
        // sortBy di PHP 8 stabil.
        $krsList = UrutanMatkulService::urutkanKrs($query->orderByDesc('created_at')->get());

        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = empty($krsIds)
            ? collect()
            : Nilai::whereIn('id_krs', $krsIds)->whereNull('deleted_at')->get()->keyBy('id_krs');

        return [$mahasiswa, $krsList, $nilaiMap, $semesterId];
    }

    public function excel(int $id, Request $request): StreamedResponse
    {
        [$mahasiswa, $krsList, $nilaiMap] = $this->loadData($id, $request);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nilai Mahasiswa');

        $row = 1;
        $sheet->setCellValue('A'.$row, 'LAPORAN NILAI MAHASISWA');
        $sheet->mergeCells('A'.$row.':F'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue('A'.$row, 'NIM:');
        $sheet->setCellValue('B'.$row, $mahasiswa->nim);
        $row++;

        $sheet->setCellValue('A'.$row, 'Nama:');
        $sheet->setCellValue('B'.$row, $mahasiswa->nama);
        $row++;

        $sheet->setCellValue('A'.$row, 'Program Studi:');
        $sheet->setCellValue('B'.$row, $mahasiswa->prodi?->nama ?? '-');
        $row++;

        $sheet->setCellValue('A'.$row, 'Semester Masuk:');
        $sheet->setCellValue('B'.$row, $mahasiswa->semester_masuk?->nama ?? '-');
        $row++;

        $sheet->setCellValue('A'.$row, 'Tanggal Export:');
        $sheet->setCellValue('B'.$row, date('d/m/Y H:i:s'));
        $row += 2;

        $headers = ['No', 'Kode Mata Kuliah', 'Nama Mata Kuliah', 'SKS', 'Semester', 'Huruf Mutu', 'Angka Mutu', 'Status'];
        $sheet->fromArray([$headers], null, 'A'.$row);

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $lastHeaderCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A'.$row.':'.$lastHeaderCol.$row)->applyFromArray($headerStyle);

        $headerRow = $row;
        $row++;
        $no = 1;
        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = $nilaiMap->get($krs->id);

            $sheet->setCellValue('A'.$row, $no);
            $sheet->setCellValue('B'.$row, $matkul?->kode ?? '-');
            $sheet->setCellValue('C'.$row, $matkul?->nama ?? '-');
            $sheet->setCellValue('D'.$row, $matkul?->sks ?? '-');
            $sheet->setCellValue('E'.$row, $semester?->nama ?? '-');
            $sheet->setCellValue('F'.$row, $nilai?->huruf_mutu ?? '-');
            $sheet->setCellValue('G'.$row, $nilai?->angka_mutu ?? '-');
            $sheet->setCellValue('H'.$row, ! $nilai ? 'Belum Ada Nilai' : ($nilai->is_final ? 'Final' : 'Belum Final'));

            foreach (['A', 'D', 'F', 'G', 'H'] as $col) {
                $sheet->getStyle($col.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $row++;
            $no++;
        }

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(8);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(18);

        if ($row > $headerRow + 1) {
            $sheet->setAutoFilter('A'.$headerRow.':'.$lastHeaderCol.($row - 1));
        }

        $nimPart = trim(str_replace([' ', "\t", "\n", "\r"], '_', (string) $mahasiswa->nim), '_');
        $filename = trim(preg_replace('/_+/', '_', 'nilai_'.$nimPart.'_'.date('YmdHis').'.xlsx'), '_');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function pdf(int $id, Request $request)
    {
        [$mahasiswa, $krsList, $nilaiMap, $semesterId] = $this->loadData($id, $request);

        $semesterFilter = $semesterId ? Semester::find($semesterId) : Semester::where('is_active', true)->first();

        $totalSks = 0;
        $totalAngkaMutu = 0.0;
        $totalSksDenganNilai = 0;
        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $nilai = $nilaiMap->get($krs->id);
            $sks = $matkul?->sks ?? 0;
            $totalSks += $sks;

            if ($nilai && $nilai->is_final && $nilai->angka_mutu !== null) {
                $totalAngkaMutu += (float) $nilai->angka_mutu * $sks;
                $totalSksDenganNilai += $sks;
            }
        }
        $ipk = $totalSksDenganNilai > 0 ? number_format($totalAngkaMutu / $totalSksDenganNilai, 2) : '-';

        $kop = Setting::whereIn('key', [
            'app_univ_name', 'app_univ_address', 'app_univ_email', 'app_univ_website', 'app_univ_yayasan',
        ])->pluck('value', 'key');
        $logoSrc = $this->resolveLogoBase64();

        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 10mm 15mm; }
            body { font-family: Arial, sans-serif; font-size: 11pt; line-height: 1.4; color: #171717; }
            .kop-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
            .kop-table td { border: none; padding: 0; vertical-align: middle; }
            .kop-logo { width: 90px; text-align: center; }
            .kop-logo img { width: 80px; height: 80px; }
            .kop-text { text-align: center; }
            .kop-text h1 { margin: 0 0 3px 0; font-size: 18pt; font-weight: bold; }
            .kop-text p { margin: 2px 0; font-size: 10pt; }
            hr.rule { border: none; border-top: 3px solid #000; margin: 6px 0 18px 0; }
            .info-row { display: table; width: 100%; margin-bottom: 4px; }
            .info-label { display: table-cell; width: 160px; font-weight: bold; }
            .info-value { display: table-cell; }
            table.matkul { width: 100%; border-collapse: collapse; margin-top: 10px; }
            table.matkul th { background-color: #171717; color: #fff; padding: 6px; text-align: center; border: 1px solid #171717; font-size: 9pt; }
            table.matkul td { padding: 5px; border: 1px solid #a3a3a3; font-size: 9pt; }
            table.matkul tr:nth-child(even) { background-color: #f9fafb; }
            .text-center { text-align: center; }
            .ringkasan { width: 100%; border-collapse: collapse; margin-top: 14px; }
            .ringkasan td { border: 1px solid #a3a3a3; padding: 6px; font-size: 9pt; }
            .footer { margin-top: 20px; text-align: right; font-size: 9pt; color: #525252; }
        </style></head><body>';

        $html .= '<table class="kop-table"><tr>';
        $html .= '<td class="kop-logo">'.($logoSrc ? '<img src="'.$logoSrc.'">' : '').'</td>';
        $html .= '<td class="kop-text">';
        if (! empty($kop->get('app_univ_yayasan'))) {
            $html .= '<p>'.$esc(mb_strtoupper($kop->get('app_univ_yayasan'))).'</p>';
        }
        $html .= '<h1>'.$esc(mb_strtoupper($kop->get('app_univ_name') ?: 'Sikampus')).'</h1>';
        if (! empty($kop->get('app_univ_address'))) {
            $html .= '<p>'.$esc($kop->get('app_univ_address')).'</p>';
        }
        $contact = array_filter([
            $kop->get('app_univ_email') ? 'Email: '.$esc($kop->get('app_univ_email')) : null,
            $kop->get('app_univ_website') ? 'Website: '.$esc($kop->get('app_univ_website')) : null,
        ]);
        if (! empty($contact)) {
            $html .= '<p>'.implode(' &nbsp;|&nbsp; ', $contact).'</p>';
        }
        $html .= '</td>';
        $html .= '<td style="width:90px"></td>'; // penyeimbang visual supaya blok teks tetap tampak di tengah, mengimbangi lebar kolom logo
        $html .= '</tr></table>';
        $html .= '<hr class="rule">';

        $html .= '<h2 style="text-align:center;font-size:14pt;">LAPORAN NILAI MAHASISWA</h2>';

        $html .= '<div class="info-row"><div class="info-label">NIM</div><div class="info-value">: '.$esc($mahasiswa->nim).'</div></div>';
        $html .= '<div class="info-row"><div class="info-label">Nama</div><div class="info-value">: '.$esc($mahasiswa->nama).'</div></div>';
        $html .= '<div class="info-row"><div class="info-label">Program Studi</div><div class="info-value">: '.$esc($mahasiswa->prodi?->nama ?? '-').'</div></div>';
        $html .= '<div class="info-row"><div class="info-label">Semester Masuk</div><div class="info-value">: '.$esc($mahasiswa->semester_masuk?->nama ?? '-').'</div></div>';
        $html .= '<div class="info-row"><div class="info-label">Semester</div><div class="info-value">: '.$esc($semesterFilter ? $semesterFilter->nama.' ('.$semesterFilter->kode.')' : 'Semua Semester').'</div></div>';

        $html .= '<table class="matkul"><thead><tr>';
        foreach (['No' => '5%', 'Kode' => '10%', 'Mata Kuliah' => '33%', 'SKS' => '7%', 'Semester' => '15%', 'Huruf Mutu' => '10%', 'Angka Mutu' => '10%', 'Status' => '10%'] as $label => $width) {
            $html .= '<th style="width:'.$width.'">'.$label.'</th>';
        }
        $html .= '</tr></thead><tbody>';

        if ($krsList->isEmpty()) {
            $html .= '<tr><td colspan="8" class="text-center">Tidak ada data nilai.</td></tr>';
        } else {
            $no = 1;
            foreach ($krsList as $krs) {
                $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
                $semester = $krs->kelas->semester ?? null;
                $nilai = $nilaiMap->get($krs->id);
                $status = ! $nilai ? 'Belum Ada Nilai' : ($nilai->is_final ? 'Final' : 'Belum Final');

                $html .= '<tr>';
                $html .= '<td class="text-center">'.$no.'</td>';
                $html .= '<td>'.$esc($matkul?->kode ?? '-').'</td>';
                $html .= '<td>'.$esc($matkul?->nama ?? '-').'</td>';
                $html .= '<td class="text-center">'.$esc($matkul?->sks ?? '-').'</td>';
                $html .= '<td>'.$esc($semester?->nama ?? '-').'</td>';
                $html .= '<td class="text-center">'.$esc($nilai?->huruf_mutu ?? '-').'</td>';
                $html .= '<td class="text-center">'.$esc($nilai?->angka_mutu ?? '-').'</td>';
                $html .= '<td class="text-center">'.$esc($status).'</td>';
                $html .= '</tr>';
                $no++;
            }
        }
        $html .= '</tbody></table>';

        $html .= '<table class="ringkasan"><tr>';
        $html .= '<td>Jumlah Mata Kuliah<br><strong>'.$krsList->count().'</strong></td>';
        $html .= '<td>Total SKS<br><strong>'.$totalSks.'</strong></td>';
        $html .= '<td>IPK<br><strong>'.$esc($ipk).'</strong></td>';
        $html .= '</tr></table>';

        $html .= '<div class="footer">Dicetak pada: '.now()->format('d/m/Y H:i').'</div>';
        $html .= '</body></html>';

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'nilai_'.preg_replace('/\s+/', '_', $mahasiswa->nim).'_'.date('YmdHis').'.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Sama persis dengan KrsCetakController::resolveLogoBase64 — Dompdf tidak diizinkan fetch
     * remote, jadi logo harus jadi data URI base64.
     */
    private function resolveLogoBase64(): ?string
    {
        $value = trim((string) Setting::where('key', 'app_univ_logo')->value('value'));
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'data:image')) {
            return $value;
        }

        $relative = preg_match('~storage/([^\s?#]+)~', $value, $m)
            ? 'storage/'.$m[1]
            : ltrim($value, '/');

        $candidates = array_unique([
            public_path($relative),
            storage_path('app/public/'.preg_replace('#^storage/#', '', $relative)),
        ]);

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $mime = @mime_content_type($path) ?: 'image/png';
                $raw = @file_get_contents($path);
                if ($raw !== false) {
                    return 'data:'.$mime.';base64,'.base64_encode($raw);
                }
            }
        }

        return null;
    }

    /**
     * Cetak transkrip nilai resmi (PDF, tab baru).
     *
     * Sengaja TIDAK menerima filter semester/pencarian seperti pdf(): transkrip adalah rekap
     * seluruh masa studi, memfilternya per semester akan menghasilkan dokumen resmi yang isinya
     * tidak lengkap. Pemeriksaan scope prodi tetap sama dengan loadData().
     */
    public function transkrip(int $id): Response
    {
        $mahasiswa = Mahasiswa::findOrFail($id);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data nilai mahasiswa ini.');
            }
        }

        $pdf = (new TranskripPdfGenerator)->pdf($mahasiswa);

        $filename = 'transkrip_'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $mahasiswa->nim).'.pdf';

        // "inline" (bukan attachment) supaya PDF-nya terbuka langsung di tab baru untuk diperiksa
        // sebelum dicetak — sama posturnya dengan pdf() dan KrsCetakController::show().
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
