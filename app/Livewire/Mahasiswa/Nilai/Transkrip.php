<?php

namespace App\Livewire\Mahasiswa\Nilai;

use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Transkrip extends Component
{
    #[Locked]
    public int $mahasiswaId;

    public function mount(): void
    {
        $mahasiswa = Mahasiswa::where('id_user', Auth::id())->firstOrFail();
        $this->mahasiswaId = $mahasiswa->id;
    }

    /**
     * Sama persis dengan NilaiController::buildTranskripLengkapPayload — hanya mata kuliah yang
     * sudah punya nilai final (huruf_mutu) yang ikut tampil, berbeda dengan Nilai Semester yang
     * menampilkan seluruh KRS tersetujui termasuk yang belum dinilai.
     */
    #[Computed]
    public function data(): array
    {
        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.semester',
        ])
            ->where('id_mahasiswa', $this->mahasiswaId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get();

        $krsIds = $krsList->pluck('id')->all();
        $nilaiMap = $krsIds === []
            ? collect()
            : Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->where('is_final', true)
                ->get()
                ->keyBy('id_krs');

        $mataKuliahList = [];
        $totalSks = 0;
        $totalAngkaMutu = 0;
        $totalSksDenganNilai = 0;

        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = $nilaiMap->get($krs->id);

            if (! $matkul || ! $semester) {
                continue;
            }
            if (! $nilai || ! $nilai->huruf_mutu) {
                continue;
            }

            $sks = $matkul->sks ?? 0;
            $totalSks += $sks;

            $angkaMutu = $nilai->angka_mutu;
            $isFinal = $nilai->is_final;

            if ($isFinal && $angkaMutu !== null && $sks > 0) {
                $totalAngkaMutu += $angkaMutu * $sks;
                $totalSksDenganNilai += $sks;
            }

            $mataKuliahList[] = [
                'id_krs' => $krs->id,
                'matkul' => $matkul,
                'semester' => $semester,
                'nilai' => $nilai,
            ];
        }

        usort($mataKuliahList, function (array $a, array $b) {
            $cmp = $a['semester']->id <=> $b['semester']->id;

            return $cmp !== 0 ? $cmp : strcmp((string) $a['matkul']->kode, (string) $b['matkul']->kode);
        });

        return [
            'mata_kuliah' => $mataKuliahList,
            'statistik' => [
                'total_sks' => $totalSks,
                'total_sks_dengan_nilai' => $totalSksDenganNilai,
                'ipk' => $totalSksDenganNilai > 0 ? round($totalAngkaMutu / $totalSksDenganNilai, 2) : null,
            ],
        ];
    }

    /**
     * Ekspor transkrip ke PDF, mengikuti pola Semester::exportPdf: memakai hasil hitung
     * computed data() supaya isi PDF tidak pernah berbeda dari yang tampil di layar.
     *
     * Ini cetakan mandiri milik mahasiswa, bukan transkrip resmi — transkrip resmi yang
     * dwibahasa, berkop, bernomor ijazah, dan bertanda tangan pejabat dicetak petugas lewat
     * App\Services\TranskripPdfGenerator.
     */
    public function exportPdf()
    {
        $mahasiswa = Mahasiswa::with('prodi')->findOrFail($this->mahasiswaId);
        $data = $this->data;

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; }
        .title { text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 4px; }
        .subtitle { text-align: center; margin-bottom: 4px; }
        .summary { text-align: center; margin-bottom: 16px; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { background-color: #4472C4; color: white; padding: 6px; border: 1px solid #000; text-align: center; }
        td { padding: 5px; border: 1px solid #000; }
        td.num { text-align: center; }
        .footer { margin-top: 20px; text-align: right; font-size: 9pt; }
        .note { margin-top: 6px; text-align: left; font-size: 8pt; color: #444; }
        </style></head><body>';

        $html .= '<div class="title">TRANSKRIP NILAI</div>';
        $html .= '<div class="subtitle">'.htmlspecialchars($mahasiswa->nim.' - '.$mahasiswa->nama).'</div>';
        if ($mahasiswa->prodi) {
            $html .= '<div class="subtitle">Program Studi: '.htmlspecialchars($mahasiswa->prodi->nama).'</div>';
        }
        $html .= '<div class="summary">Total SKS: '.(int) $data['statistik']['total_sks']
            .' &nbsp;|&nbsp; SKS dengan Nilai: '.(int) $data['statistik']['total_sks_dengan_nilai']
            .' &nbsp;|&nbsp; IPK: '
            .($data['statistik']['ipk'] !== null ? number_format($data['statistik']['ipk'], 2) : '-')
            .'</div>';

        if ($data['mata_kuliah'] === []) {
            $html .= '<p>Tidak ada data transkrip.</p>';
        } else {
            $html .= '<table><thead><tr>';
            $html .= '<th style="width:5%">No</th><th style="width:13%">Kode</th><th style="width:32%">Mata Kuliah</th>';
            $html .= '<th style="width:22%">Semester</th><th style="width:8%">SKS</th>';
            $html .= '<th style="width:10%">Huruf Mutu</th><th style="width:10%">Angka Mutu</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($data['mata_kuliah'] as $idx => $mk) {
                $angkaMutu = $mk['nilai']->angka_mutu;
                $html .= '<tr>';
                $html .= '<td class="num">'.($idx + 1).'</td>';
                $html .= '<td>'.htmlspecialchars($mk['matkul']->kode ?? '-').'</td>';
                $html .= '<td>'.htmlspecialchars($mk['matkul']->nama ?? '-').'</td>';
                $html .= '<td>'.htmlspecialchars($mk['semester']->nama.' ('.$mk['semester']->kode.')').'</td>';
                $html .= '<td class="num">'.(int) ($mk['matkul']->sks ?? 0).'</td>';
                $html .= '<td class="num">'.htmlspecialchars($mk['nilai']->huruf_mutu ?? '-').'</td>';
                $html .= '<td class="num">'.($angkaMutu !== null ? number_format((float) $angkaMutu, 2) : '-').'</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="footer">Dicetak: '.now()->format('d/m/Y H:i').'</div>';
        $html .= '<div class="note">Dokumen ini dicetak mandiri dari portal mahasiswa dan bukan transkrip resmi.</div>';
        $html .= '</body></html>';

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Transkrip_'.preg_replace('/\s+/', '_', $mahasiswa->nim).'_'.now()->format('Y-m-d').'.pdf';

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.mahasiswa.nilai.transkrip')->extends('layouts.mahasiswa');
    }
}
