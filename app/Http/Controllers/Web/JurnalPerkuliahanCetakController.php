<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Perkuliahan;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cetak Jurnal Perkuliahan (PDF, diunduh) untuk satu kelas ampu. Dipanggil dari tombol "Cetak
 * Jurnal Perkuliahan" di App\Livewire\Dosen\Jadwal\Show (halaman detail kelas).
 *
 * Logika di sini SENGAJA menyalin ulang JadwalDosenController::downloadJurnalPerkuliahanPdf (API)
 * — bukan diekstrak jadi shared service. Repo ini memang menganut "salin, jangan share" antar
 * pintu masuk yang beda (lihat skill siak-livewire-module, dan pola yang sama di
 * KrsCetakController). Satu perbedaan sengaja dari sumbernya: kolom STATUS (Terjadwal/Dimulai/
 * Selesai) dihapus dari tabel PDF ini atas permintaan pengguna — tabel "Slot Jadwal Pertemuan" di
 * Jadwal\Show (layar, bukan PDF ini) TETAP punya kolom "Status sesi" yang setara, jadi jangan
 * disamakan kembali kalau nanti ada permintaan lain soal kolom status. API dan helper
 * sesiStatusForPerkuliahan-nya di JadwalDosenController juga tidak ikut diubah karena itu tetap
 * dikonsumsi siak-frontend.
 */
class JurnalPerkuliahanCetakController extends Controller
{
    public function show(Request $request, int $kelasId): StreamedResponse
    {
        $user = Auth::user();
        $dosen = $user ? Dosen::where('id_user', $user->id)->first() : null;
        if (! $dosen) {
            abort(404, 'Data dosen tidak ditemukan');
        }

        $allowed = KelasDosen::where('id_dosen', $dosen->id)
            ->where('id_kelas', $kelasId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $allowed) {
            abort(403, 'Anda tidak mengampu kelas ini');
        }

        $idSemester = $request->query('id_semester');
        $idSemester = $idSemester !== null && $idSemester !== '' ? (int) $idSemester : null;

        $kelas = Kelas::with([
            'kurikulumMatkul.matkul',
            'prodi',
            'semester',
            'kelompokKelas',
            'dosenPic',
            'jadwal' => function ($q): void {
                $q->whereNull('deleted_at')
                    ->with(['ruangan', 'jenisKuliah', 'dosen.dosen'])
                    ->orderBy('hari')
                    ->orderBy('jam_mulai');
            },
        ])
            ->whereNull('deleted_at')
            ->find($kelasId);

        if (! $kelas) {
            abort(404, 'Kelas tidak ditemukan');
        }

        if ($idSemester !== null && (int) $kelas->id_semester !== $idSemester) {
            abort(422, 'Kelas tidak termasuk semester yang dipilih');
        }

        $jadwalIds = $kelas->jadwal->pluck('id')->filter()->values()->all();
        $perkuliahanRows = $jadwalIds === []
            ? collect()
            : Perkuliahan::whereIn('id_jadwal', $jadwalIds)->whereNull('deleted_at')->get();

        $jumlahMahasiswa = Krs::where('id_kelas', $kelasId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->count();

        $hadirByPerkuliahanId = $perkuliahanRows->isEmpty()
            ? collect()
            : Kehadiran::query()
                ->whereIn('id_perkuliahan', $perkuliahanRows->pluck('id'))
                ->where('status', 'hadir')
                ->whereNull('deleted_at')
                ->select('id_perkuliahan', DB::raw('count(*) as cnt'))
                ->groupBy('id_perkuliahan')
                ->pluck('cnt', 'id_perkuliahan');

        $hariOrder = [
            'senin' => 1,
            'selasa' => 2,
            'rabu' => 3,
            'kamis' => 4,
            'jumat' => 5,
            'sabtu' => 6,
            'minggu' => 7,
        ];

        $jadwalSorted = $kelas->jadwal->sortBy(function (Jadwal $j) use ($hariOrder) {
            $jamKey = (int) preg_replace('/\D/', '', substr((string) ($j->jam_mulai ?? '00:00'), 0, 5));
            $h = $hariOrder[strtolower((string) ($j->hari ?? ''))] ?? 8;
            if ($j->tanggal) {
                return sprintf('%s-%06d-%d', $j->tanggal->format('Y-m-d'), $jamKey, $j->id);
            }
            $u = (int) ($j->urutan_pertemuan ?? 9999);

            return sprintf('9999-99-99-%05d-%d-%06d-%d', $u, $h, $jamKey, $j->id);
        })->values();

        $km = $kelas->kurikulumMatkul;
        $m = $km?->matkul;
        $namaMatkulDisplay = trim(($km?->nama_matkul ?: $m?->nama) ?? '-');
        $kodeMatkulDisplay = ($km?->kode_matkul ?: $m?->kode) ?? '';
        $mataKuliahJudul = $kodeMatkulDisplay !== '' ? $kodeMatkulDisplay.' — '.$namaMatkulDisplay : $namaMatkulDisplay;
        $sksVal = (float) ($km?->sks ?? $m?->sks ?? 0);
        $sksStr = number_format($sksVal, 2, '.', '').' SKS';
        $namaDosenPic = $kelas->dosenPic?->nama ?? '-';
        $kodeKelas = $kelas->kode ?? '-';
        $prodiNama = strtoupper((string) ($kelas->prodi?->nama ?? ''));
        $sem = $kelas->semester;
        $semesterLine = $sem ? trim((string) (($sem->nama ?? '').' '.($sem->kode ?? ''))) : '';
        $subtitle = trim($prodiNama.($semesterLine !== '' ? ' '.$semesterLine : ''));

        Carbon::setLocale('id');

        $formatJamJadwal = static function (?string $jam): string {
            if ($jam === null || $jam === '') {
                return '-';
            }

            return substr($jam, 0, 5);
        };

        $rowsHtml = '';
        foreach ($jadwalSorted as $j) {
            $p = $this->findPerkuliahanForJadwalSlot($j, $perkuliahanRows);

            $tatapMuka = $j->urutan_pertemuan !== null ? (string) $j->urutan_pertemuan : '-';

            if ($j->tanggal) {
                try {
                    $hariTanggal = Carbon::parse($j->tanggal->format('Y-m-d'))->translatedFormat('l, d F Y');
                } catch (\Throwable) {
                    $hariTanggal = $j->tanggal->format('d/m/Y');
                }
            } else {
                $hariLabel = [
                    'senin' => 'Senin', 'selasa' => 'Selasa', 'rabu' => 'Rabu', 'kamis' => 'Kamis',
                    'jumat' => 'Jumat', 'sabtu' => 'Sabtu', 'minggu' => 'Minggu',
                ][strtolower((string) ($j->hari ?? ''))] ?? ($j->hari ?? '-');
                $hariTanggal = $hariLabel.' (tanggal belum diisi)';
            }

            if ($p && $p->waktu_mulai) {
                $mulai = Carbon::parse($p->waktu_mulai)->format('H:i');
            } else {
                $mulai = $formatJamJadwal($j->jam_mulai);
            }

            if ($p && $p->waktu_selesai) {
                $selesai = Carbon::parse($p->waktu_selesai)->format('H:i');
            } elseif ($p && $p->waktu_mulai) {
                $selesai = $formatJamJadwal($j->jam_selesai);
            } else {
                $selesai = $formatJamJadwal($j->jam_selesai);
            }

            $ruang = $j->ruangan->nama ?? '-';
            $rencana = $j->bahasan ?? '';
            $realisasi = '';
            if ($p) {
                $realisasi = (string) ($p->realisasi_materi ?? '');
                if ($realisasi === '' && $p->waktu_selesai) {
                    $realisasi = (string) ($p->materi ?? '');
                }
            }

            $hadir = 0;
            if ($p) {
                $hadir = (int) ($hadirByPerkuliahanId[$p->id] ?? 0);
            }
            $kehadiranStr = '('.$hadir.'/'.$jumlahMahasiswa.')';

            $pengajar = $j->dosen->map(fn ($jd) => $jd->dosen->nama ?? null)->filter()->unique()->implode(', ');
            if ($pengajar === '') {
                $pengajar = '-';
            }

            $rowsHtml .= '<tr>'
                .'<td class="c">'.e($tatapMuka).'</td>'
                .'<td class="l">'.e($hariTanggal).'</td>'
                .'<td class="c">'.e($mulai).'</td>'
                .'<td class="c">'.e($selesai).'</td>'
                .'<td class="c">'.e($ruang).'</td>'
                .'<td class="l small">'.nl2br(e($rencana)).'</td>'
                .'<td class="l small">'.nl2br(e($realisasi)).'</td>'
                .'<td class="c">'.e($kehadiranStr).'</td>'
                .'<td class="l small">'.e($pengajar).'</td>'
                .'<td class="ttd"></td>'
                .'</tr>';
        }

        if ($rowsHtml === '') {
            $rowsHtml = '<tr><td colspan="10" class="c">Belum ada jadwal pertemuan.</td></tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #111; }
.title { text-align: center; font-size: 13pt; font-weight: bold; margin: 0 0 6px 0; }
.subtitle { text-align: center; font-size: 10pt; font-weight: bold; margin: 0 0 14px 0; }
.meta { margin-bottom: 12px; line-height: 1.5; }
.meta-row { margin: 2px 0; }
.meta b { display: inline-block; min-width: 120px; }
table.jurnal { width: 100%; border-collapse: collapse; table-layout: fixed; }
table.jurnal th, table.jurnal td { border: 1px solid #000; padding: 4px 3px; vertical-align: top; }
table.jurnal th { font-size: 7pt; font-weight: bold; text-align: center; background: #f0f0f0; }
td.c { text-align: center; }
td.l { text-align: left; }
td.small { font-size: 7pt; word-wrap: break-word; }
td.ttd { min-height: 28px; height: 32px; }
</style></head><body>';

        $html .= '<div class="title">JURNAL PERKULIAHAN</div>';
        $html .= '<div class="subtitle">'.e($subtitle !== '' ? $subtitle : '—').'</div>';
        $html .= '<div class="meta">';
        $html .= '<div class="meta-row"><b>MATA KULIAH:</b> '.e($mataKuliahJudul).'</div>';
        $html .= '<div class="meta-row"><b>NAMA DOSEN:</b> '.e($namaDosenPic).'</div>';
        $html .= '<div class="meta-row"><b>KREDIT/SKS:</b> '.e($sksStr).'</div>';
        $html .= '<div class="meta-row"><b>KELAS:</b> '.e($kodeKelas).'</div>';
        $html .= '</div>';

        $html .= '<table class="jurnal"><thead><tr>';
        $html .= '<th style="width:4%">TATAP MUKA KE</th>';
        $html .= '<th style="width:12%">HARI/TANGGAL</th>';
        $html .= '<th style="width:5%">MULAI</th>';
        $html .= '<th style="width:5%">SELESAI</th>';
        $html .= '<th style="width:6%">RUANG</th>';
        $html .= '<th style="width:16%">RENCANA MATERI</th>';
        $html .= '<th style="width:16%">REALISASI MATERI</th>';
        $html .= '<th style="width:8%">KEHADIRAN MHS</th>';
        $html .= '<th style="width:14%">PENGAJAR</th>';
        $html .= '<th style="width:14%">TANDA TANGAN</th>';
        $html .= '</tr></thead><tbody>';
        $html .= $rowsHtml;
        $html .= '</tbody></table></body></html>';

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $safeKode = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $kodeKelas);
        $filename = 'Jurnal_Perkuliahan_'.$safeKode.'_'.date('Y-m-d').'.pdf';

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Sama persis dengan JadwalDosenController::findPerkuliahanForJadwalSlot.
     */
    private function findPerkuliahanForJadwalSlot(Jadwal $j, Collection $perkuliahanRows): ?Perkuliahan
    {
        $slotId = (int) $j->id;
        $candidates = $perkuliahanRows->filter(fn ($p) => (int) $p->id_jadwal === $slotId);

        $ts = static function (?Perkuliahan $p): int {
            if ($p === null || ! $p->waktu_mulai) {
                return 0;
            }

            return Carbon::parse($p->waktu_mulai)->getTimestamp();
        };

        // Utamakan sesi yang sedang berlangsung (sudah mulai, belum selesai)
        $ongoing = $candidates
            ->filter(function (Perkuliahan $p) {
                return $p->waktu_mulai && ! $p->waktu_selesai;
            })
            ->sortByDesc(fn (Perkuliahan $p) => $ts($p))
            ->first();

        if ($ongoing) {
            return $ongoing;
        }

        // Riwayat: baris perkuliahan terbaru untuk slot ini (untuk status "selesai" di ringkasan)
        return $candidates->sortByDesc(fn (Perkuliahan $p) => $ts($p))->first();
    }
}
