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

class Semester extends Component
{
    #[Locked]
    public int $mahasiswaId;

    public function mount(): void
    {
        $mahasiswa = Mahasiswa::where('id_user', Auth::id())->firstOrFail();
        $this->mahasiswaId = $mahasiswa->id;
    }

    /**
     * Sama persis dengan NilaiController::getTranskripMahasiswa.
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

        $transkripData = [];
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

            $sks = $matkul->sks ?? 0;
            $totalSks += $sks;

            $angkaMutu = $nilai?->angka_mutu;
            $isFinal = $nilai?->is_final ?? false;

            if ($isFinal && $angkaMutu !== null && $sks > 0) {
                $totalAngkaMutu += $angkaMutu * $sks;
                $totalSksDenganNilai += $sks;
            }

            $semesterId = $semester->id;
            if (! isset($transkripData[$semesterId])) {
                $transkripData[$semesterId] = [
                    'semester' => $semester,
                    'mata_kuliah' => [],
                    'total_sks' => 0,
                    'total_angka_mutu' => 0,
                    'total_sks_dengan_nilai' => 0,
                ];
            }

            $transkripData[$semesterId]['mata_kuliah'][] = [
                'id_krs' => $krs->id,
                'matkul' => $matkul,
                'nilai' => $nilai,
            ];

            $transkripData[$semesterId]['total_sks'] += $sks;
            if ($isFinal && $angkaMutu !== null) {
                $transkripData[$semesterId]['total_angka_mutu'] += $angkaMutu * $sks;
                $transkripData[$semesterId]['total_sks_dengan_nilai'] += $sks;
            }
        }

        foreach ($transkripData as &$row) {
            $row['ip_semester'] = $row['total_sks_dengan_nilai'] > 0
                ? round($row['total_angka_mutu'] / $row['total_sks_dengan_nilai'], 2)
                : null;
        }
        unset($row);

        usort($transkripData, fn ($a, $b) => $b['semester']->id <=> $a['semester']->id);

        return [
            'transkrip' => array_values($transkripData),
            'statistik' => [
                'total_sks' => $totalSks,
                'total_sks_dengan_nilai' => $totalSksDenganNilai,
                'ip_kumulatif' => $totalSksDenganNilai > 0 ? round($totalAngkaMutu / $totalSksDenganNilai, 2) : null,
            ],
        ];
    }

    /**
     * Sama persis dengan NilaiController::exportNilaiSemesterPdf, memakai hasil hitung
     * computed data() supaya isi PDF tidak pernah berbeda dari yang tampil di layar.
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
        .section { margin-bottom: 14px; }
        .section-title { font-size: 11pt; font-weight: bold; margin-bottom: 6px; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { background-color: #4472C4; color: white; padding: 6px; border: 1px solid #000; text-align: center; }
        td { padding: 5px; border: 1px solid #000; }
        td.num { text-align: center; }
        .footer { margin-top: 20px; text-align: right; font-size: 9pt; }
        </style></head><body>';

        $html .= '<div class="title">KARTU HASIL STUDI (KHS)</div>';
        $html .= '<div class="subtitle">'.htmlspecialchars($mahasiswa->nim.' - '.$mahasiswa->nama).'</div>';
        if ($mahasiswa->prodi) {
            $html .= '<div class="subtitle">Program Studi: '.htmlspecialchars($mahasiswa->prodi->nama).'</div>';
        }
        $html .= '<div class="summary">Total SKS: '.(int) $data['statistik']['total_sks']
            .' &nbsp;|&nbsp; SKS dengan Nilai: '.(int) $data['statistik']['total_sks_dengan_nilai']
            .' &nbsp;|&nbsp; IP Kumulatif: '
            .($data['statistik']['ip_kumulatif'] !== null ? number_format($data['statistik']['ip_kumulatif'], 2) : '-')
            .'</div>';

        foreach ($data['transkrip'] as $group) {
            $ipSemester = $group['ip_semester'] !== null ? number_format($group['ip_semester'], 2) : '-';

            $html .= '<div class="section">';
            $html .= '<div class="section-title">'.htmlspecialchars($group['semester']->nama.' ('.$group['semester']->kode.')')
                .' — Total SKS: '.(int) $group['total_sks']
                .' — IP Semester: '.$ipSemester.'</div>';
            $html .= '<table><thead><tr>';
            $html .= '<th style="width:6%">No</th><th style="width:14%">Kode</th><th style="width:44%">Mata Kuliah</th>';
            $html .= '<th style="width:8%">SKS</th><th style="width:14%">Huruf Mutu</th><th style="width:14%">Angka Mutu</th>';
            $html .= '</tr></thead><tbody>';

            $no = 1;
            foreach ($group['mata_kuliah'] as $mk) {
                $angkaMutu = $mk['nilai']?->angka_mutu;
                $html .= '<tr>';
                $html .= '<td class="num">'.$no.'</td>';
                $html .= '<td>'.htmlspecialchars($mk['matkul']->kode ?? '-').'</td>';
                $html .= '<td>'.htmlspecialchars($mk['matkul']->nama ?? '-').'</td>';
                $html .= '<td class="num">'.(int) ($mk['matkul']->sks ?? 0).'</td>';
                $html .= '<td class="num">'.htmlspecialchars($mk['nilai']?->huruf_mutu ?? '-').'</td>';
                $html .= '<td class="num">'.($angkaMutu !== null ? number_format((float) $angkaMutu, 2) : '-').'</td>';
                $html .= '</tr>';
                $no++;
            }
            $html .= '</tbody></table></div>';
        }

        if ($data['transkrip'] === []) {
            $html .= '<p>Tidak ada data nilai.</p>';
        }

        $html .= '<div class="footer">Dicetak: '.now()->format('d/m/Y H:i').'</div></body></html>';

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Nilai_'.preg_replace('/\s+/', '_', $mahasiswa->nim).'_'.now()->format('Y-m-d').'.pdf';

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.mahasiswa.nilai.semester')->extends('layouts.mahasiswa');
    }
}
