<?php

namespace App\Livewire\Dosen\Arsip;

use App\Models\Dosen;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Semester;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Index extends Component
{
    #[Locked]
    public int $dosenId;

    /**
     * Terikat ke query string `id_semester` supaya tautan "Kembali" dari halaman rincian bisa
     * mengembalikan pengguna ke semester yang sedang dilihatnya, bukan selalu ke semester aktif.
     */
    #[Url(as: 'id_semester')]
    public string $filterSemester = '';

    public function mount(): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        // Sengaja TIDAK dikunci ke semester aktif seperti halaman Kelas/Jadwal: isi arsip justru
        // semester-semester yang sudah lewat, jadi membuka halaman dengan filter semester aktif
        // membuatnya nyaris selalu tampak kosong. Default di sini = semua semester, dan nilai dari
        // query string `id_semester` (tautan "Kembali") dibiarkan apa adanya.
    }

    #[Computed]
    public function semesterOptions(): array
    {
        return Semester::whereNull('deleted_at')
            ->orderByDesc('kode')
            ->get(['id', 'nama', 'kode'])
            ->mapWithKeys(fn (Semester $s) => [$s->id => $s->kode ? "{$s->nama} ({$s->kode})" : $s->nama])
            ->all();
    }

    /**
     * Daftar kelas unik yang pernah diampu dosen ini, satu baris per kelas.
     *
     * Sumbernya SENGAJA dua tabel — beda dengan JadwalDosenController::getMyJadwal yang hanya
     * membaca jadwal_dosen:
     *   - jadwal_dosen (status active) → dosen yang ditugaskan pada slot jadwal;
     *   - kelas_dosen                  → dosen pengampu kelas (PIC maupun tim), sumber yang sama
     *                                    dengan halaman Kelas Mata Kuliah.
     * Tanpa kelas_dosen, kelas yang punya pengampu tapi belum/tidak punya slot jadwal tidak akan
     * pernah muncul di arsip walau jelas diampu.
     *
     * @return array<int, Kelas>
     */
    #[Computed]
    public function rows(): array
    {
        $kelasIdsDariJadwal = JadwalDosen::where('id_dosen', $this->dosenId)
            ->where('status', 'active')
            ->with('jadwal')
            ->get()
            ->pluck('jadwal.id_kelas');

        $kelasIdsDariPengampu = KelasDosen::where('id_dosen', $this->dosenId)
            ->whereNull('deleted_at')
            ->pluck('id_kelas');

        $kelasIds = $kelasIdsDariJadwal
            ->merge($kelasIdsDariPengampu)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($kelasIds === []) {
            return [];
        }

        return Kelas::with(['kurikulumMatkul.matkul', 'prodi', 'semester'])
            ->whereIn('id', $kelasIds)
            ->whereNull('deleted_at')
            // Filter semester diterapkan di sini, bukan per sumber, supaya aturannya satu tempat.
            ->when($this->filterSemester !== '', fn ($q) => $q->where('id_semester', (int) $this->filterSemester))
            ->get()
            // Daftar ini lintas semester, jadi semester terbaru didahulukan; di dalam satu semester
            // urutannya tetap menurut kode mata kuliah seperti sebelumnya.
            ->sortBy(fn (Kelas $k) => $k->kurikulumMatkul?->kodeMatkulLabel() ?? '')
            ->sortByDesc(fn (Kelas $k) => $k->semester?->kode ?? '')
            ->values()
            ->all();
    }

    /**
     * Baris siap cetak untuk kedua format ekspor — diambil dari computed rows() supaya isi
     * berkas selalu mengikuti filter semester yang sedang aktif di layar.
     *
     * Pola ekspornya sengaja disalin dari App\Livewire\Dosen\Kelas\Index (bukan di-share lewat
     * trait), mengikuti kebiasaan repo ini menyalin Concerns per modul: kolomnya memang berbeda,
     * dan tiap halaman bebas berubah tanpa menyeret halaman lain.
     *
     * @return array<int, array<int, string|int>>
     */
    private function exportRows(): array
    {
        $hasil = [];

        foreach ($this->rows as $idx => $kelas) {
            $km = $kelas->kurikulumMatkul;
            $semester = $kelas->semester;

            $hasil[] = [
                $idx + 1,
                (string) ($km?->kodeMatkulLabel() ?? '—'),
                (string) ($km?->namaMatkulLabel() ?? '—'),
                $semester
                    ? trim($semester->nama.($semester->kode ? ' ('.$semester->kode.')' : ''))
                    : '—',
                (int) ($km?->sksLabel() ?? 0),
            ];
        }

        return $hasil;
    }

    /**
     * @return array<int, string>
     */
    private function exportHeaders(): array
    {
        return ['No.', 'Kode mata kuliah', 'Nama mata kuliah', 'Semester', 'SKS'];
    }

    /**
     * Nama dosen lengkap dengan gelar — pola yang sama dengan DosenBimbinganExportController.
     */
    private function namaDosenLengkap(Dosen $dosen): string
    {
        $nama = trim(
            ($dosen->gelar_depan ? $dosen->gelar_depan.' ' : '').
            ($dosen->nama ?? '').
            ($dosen->gelar_belakang ? ', '.$dosen->gelar_belakang : '')
        );

        return $nama !== '' ? $nama : '-';
    }

    private function semesterLabel(): string
    {
        if ($this->filterSemester === '') {
            return 'Semua semester';
        }

        return (string) ($this->semesterOptions[(int) $this->filterSemester] ?? 'Semua semester');
    }

    private function namaBerkas(string $ekstensi): string
    {
        $semester = $this->filterSemester !== ''
            ? Semester::whereKey((int) $this->filterSemester)->value('kode')
            : null;

        return 'Arsip_Perkuliahan'
            .($semester ? '_'.preg_replace('/\s+/', '_', (string) $semester) : '')
            .'_'.now()->format('Y-m-d').'.'.$ekstensi;
    }

    public function exportExcel(): StreamedResponse
    {
        $dosen = Dosen::findOrFail($this->dosenId);
        $rows = $this->exportRows();
        $kolomTerakhir = 'E';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Arsip');

        $sheet->setCellValue('A1', 'Arsip perkuliahan');
        $sheet->mergeCells('A1:'.$kolomTerakhir.'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Dosen: '.$this->namaDosenLengkap($dosen));
        $sheet->mergeCells('A2:'.$kolomTerakhir.'2');
        $sheet->setCellValue('A3', 'Semester: '.$this->semesterLabel());
        $sheet->mergeCells('A3:'.$kolomTerakhir.'3');
        $sheet->setCellValue('A4', 'Diekspor: '.now()->format('Y-m-d H:i:s'));
        $sheet->mergeCells('A4:'.$kolomTerakhir.'4');

        $headerRow = 6;
        $sheet->fromArray([$this->exportHeaders()], null, 'A'.$headerRow);
        $sheet->getStyle('A'.$headerRow.':'.$kolomTerakhir.$headerRow)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        if ($rows !== []) {
            // strictNullComparison = true: tanpa ini fromArray() membandingkan longgar dengan
            // $nullValue, sehingga sel bernilai 0 (SKS/revisi/nilai nol) dianggap null dan
            // dilewati — kolomnya jadi kosong di Excel padahal terisi di layar dan di PDF.
            $sheet->fromArray($rows, null, 'A'.($headerRow + 1), true);
        } else {
            $sheet->setCellValue('A'.($headerRow + 1), 'Tidak ada arsip kelas.');
            $sheet->mergeCells('A'.($headerRow + 1).':'.$kolomTerakhir.($headerRow + 1));
        }

        foreach (range('A', $kolomTerakhir) as $kolom) {
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $this->namaBerkas('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportPdf(): StreamedResponse
    {
        $dosen = Dosen::findOrFail($this->dosenId);
        $rows = $this->exportRows();

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; }
        .title { text-align: center; font-size: 13pt; font-weight: bold; margin-bottom: 4px; }
        .subtitle { text-align: center; margin-bottom: 2px; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background-color: #4472C4; color: white; padding: 6px; border: 1px solid #000; text-align: center; }
        td { padding: 5px; border: 1px solid #000; }
        td.num { text-align: center; }
        .footer { margin-top: 16px; text-align: right; font-size: 8pt; }
        </style></head><body>';

        $html .= '<div class="title">ARSIP PERKULIAHAN</div>';
        $html .= '<div class="subtitle">'.htmlspecialchars($this->namaDosenLengkap($dosen)).'</div>';
        $html .= '<div class="subtitle">Semester: '.htmlspecialchars($this->semesterLabel()).'</div>';

        if ($rows === []) {
            $html .= '<p>Tidak ada arsip kelas.</p>';
        } else {
            $lebar = ['6%', '21%', '36%', '28%', '9%'];

            $html .= '<table><thead><tr>';
            foreach ($this->exportHeaders() as $i => $header) {
                $html .= '<th style="width:'.$lebar[$i].'">'.htmlspecialchars($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $i => $sel) {
                    // Kolom No. dan SKS rata tengah; sisanya rata kiri.
                    $kelasSel = in_array($i, [0, 4], true) ? ' class="num"' : '';
                    $html .= '<td'.$kelasSel.'>'.htmlspecialchars((string) $sel).'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="footer">Dicetak: '.now()->format('d/m/Y H:i').'</div></body></html>';

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $this->namaBerkas('pdf'), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.dosen.arsip.index')->extends('layouts.dosen');
    }
}
