<?php

namespace App\Livewire\Dosen\Kelas;

use App\Models\Dosen;
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

    private const HARI_LABEL = [
        'senin' => 'Senin',
        'selasa' => 'Selasa',
        'rabu' => 'Rabu',
        'kamis' => 'Kamis',
        'jumat' => 'Jumat',
        'sabtu' => 'Sabtu',
        'minggu' => 'Minggu',
    ];

    public function mount(): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $activeSemester = Semester::where('is_active', true)->whereNull('deleted_at')->first();
        // Query string menang: kalau halaman dibuka lewat tautan "Kembali" yang membawa
        // id_semester, Livewire sudah mengisi properti ini sebelum mount() jalan — jangan ditimpa
        // dengan default semester aktif.
        if ($this->filterSemester === '') {
            $this->filterSemester = $activeSemester ? (string) $activeSemester->id : '';
        }
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
     * Sama persis dengan JadwalDosenController::getKelasAmpu — daftar kelas dari kelas_dosen
     * (termasuk sebagai PIC atau tim pengampu), bukan slot jadwal per hari.
     *
     * @return array<int, array{kelas: Kelas, is_pic: bool, jadwal_ringkas: string}>
     */
    #[Computed]
    public function rows(): array
    {
        $kelasDosenRows = KelasDosen::where('id_dosen', $this->dosenId)->whereNull('deleted_at')->get();
        $kelasIds = $kelasDosenRows->pluck('id_kelas')->unique()->values()->all();
        $picByKelas = $kelasDosenRows->keyBy('id_kelas');

        if ($kelasIds === []) {
            return [];
        }

        $query = Kelas::with([
            'kurikulumMatkul.matkul',
            'prodi.jenjang',
            'kelompokKelas',
            'jadwal' => fn ($q) => $q->whereNull('deleted_at')->orderBy('hari')->orderBy('jam_mulai'),
        ])
            ->whereIn('id', $kelasIds)
            ->whereNull('deleted_at');

        if ($this->filterSemester !== '') {
            $query->where('id_semester', (int) $this->filterSemester);
        }

        return $query->get()
            ->sortBy(fn (Kelas $k) => ($k->kurikulumMatkul?->kodeMatkulLabel() ?? '').'-'.$k->id)
            ->map(fn (Kelas $kelas) => [
                'kelas' => $kelas,
                'is_pic' => (bool) ($picByKelas->get($kelas->id)?->is_pic ?? false),
                'jadwal_ringkas' => $this->jadwalRingkas($kelas->jadwal),
            ])
            ->values()
            ->all();
    }

    /**
     * Sama persis dengan formatJadwalRingkas di dosen/kelas/page.tsx.
     */
    private function jadwalRingkas($jadwalList): string
    {
        if ($jadwalList->isEmpty()) {
            return '—';
        }

        $first = $jadwalList->first();
        $hari = self::HARI_LABEL[strtolower((string) $first->hari)] ?? $first->hari ?? '—';
        $mulai = substr((string) $first->jam_mulai, 0, 5);
        $selesai = substr((string) $first->jam_selesai, 0, 5);

        $label = "{$hari}, {$mulai}–{$selesai}";

        if ($jadwalList->count() > 1) {
            $label .= ' (+'.($jadwalList->count() - 1).')';
        }

        return $label;
    }

    /**
     * Baris siap cetak untuk kedua format ekspor — diambil dari computed rows() supaya isi
     * berkas selalu mengikuti filter semester yang sedang aktif di layar.
     *
     * @return array<int, array<int, string|int>>
     */
    private function exportRows(): array
    {
        $hasil = [];

        foreach ($this->rows as $idx => $row) {
            $kelas = $row['kelas'];
            $km = $kelas->kurikulumMatkul;

            $hasil[] = [
                $idx + 1,
                (string) ($kelas->kode ?? '—'),
                (string) ($km?->kodeMatkulLabel() ?? '—'),
                (string) ($km?->namaMatkulLabel() ?? '—'),
                (int) ($km?->sksLabel() ?? 0),
                (string) ($kelas->prodi?->nama ?? '—'),
                (string) ($kelas->prodi?->jenjang?->nama ?? '—'),
                (string) ($kelas->kelompokKelas?->nama ?? '—'),
                $row['is_pic'] ? 'Ya' : 'Bukan',
                (string) $row['jadwal_ringkas'],
            ];
        }

        return $hasil;
    }

    /**
     * @return array<int, string>
     */
    private function exportHeaders(): array
    {
        return [
            'No.', 'Kode kelas', 'Kode MK', 'Mata kuliah', 'SKS',
            'Program studi', 'Jenjang', 'Kelompok', 'PIC', 'Jadwal (ringkas)',
        ];
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

        return 'Kelas_Diampu'
            .($semester ? '_'.preg_replace('/\s+/', '_', (string) $semester) : '')
            .'_'.now()->format('Y-m-d').'.'.$ekstensi;
    }

    public function exportExcel(): StreamedResponse
    {
        $dosen = Dosen::findOrFail($this->dosenId);
        $rows = $this->exportRows();
        $headers = $this->exportHeaders();
        $kolomTerakhir = 'J';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kelas');

        $sheet->setCellValue('A1', 'Kelas mata kuliah yang diampu');
        $sheet->mergeCells('A1:'.$kolomTerakhir.'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Dosen: '.$this->namaDosenLengkap($dosen));
        $sheet->mergeCells('A2:'.$kolomTerakhir.'2');
        $sheet->setCellValue('A3', 'Semester: '.$this->semesterLabel());
        $sheet->mergeCells('A3:'.$kolomTerakhir.'3');
        $sheet->setCellValue('A4', 'Diekspor: '.now()->format('Y-m-d H:i:s'));
        $sheet->mergeCells('A4:'.$kolomTerakhir.'4');

        $headerRow = 6;
        $sheet->fromArray([$headers], null, 'A'.$headerRow);
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
            $sheet->setCellValue('A'.($headerRow + 1), 'Tidak ada kelas.');
            $sheet->mergeCells('A'.($headerRow + 1).':'.$kolomTerakhir.($headerRow + 1));
        }

        foreach (range('A', $kolomTerakhir) as $kolom) {
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = $this->namaBerkas('xlsx');

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportPdf(): StreamedResponse
    {
        $dosen = Dosen::findOrFail($this->dosenId);
        $rows = $this->exportRows();

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; }
        .title { text-align: center; font-size: 13pt; font-weight: bold; margin-bottom: 4px; }
        .subtitle { text-align: center; margin-bottom: 2px; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background-color: #4472C4; color: white; padding: 5px; border: 1px solid #000; text-align: center; }
        td { padding: 4px; border: 1px solid #000; }
        td.num { text-align: center; }
        .footer { margin-top: 16px; text-align: right; font-size: 8pt; }
        </style></head><body>';

        $html .= '<div class="title">KELAS MATA KULIAH YANG DIAMPU</div>';
        $html .= '<div class="subtitle">'.htmlspecialchars($this->namaDosenLengkap($dosen)).'</div>';
        $html .= '<div class="subtitle">Semester: '.htmlspecialchars($this->semesterLabel()).'</div>';

        if ($rows === []) {
            $html .= '<p>Tidak ada kelas.</p>';
        } else {
            $lebar = ['4%', '10%', '10%', '22%', '5%', '16%', '10%', '8%', '6%', '13%'];

            $html .= '<table><thead><tr>';
            foreach ($this->exportHeaders() as $i => $header) {
                $html .= '<th style="width:'.$lebar[$i].'">'.htmlspecialchars($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $i => $sel) {
                    // No., SKS, dan PIC lebih enak dibaca rata tengah; sisanya rata kiri.
                    $kelasSel = in_array($i, [0, 4, 8], true) ? ' class="num"' : '';
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
        // Landscape: sepuluh kolom tidak muat rapi di potret.
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = $this->namaBerkas('pdf');

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.dosen.kelas.index')->extends('layouts.dosen');
    }
}
