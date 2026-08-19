<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Jadwal;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\Semester;
use App\Models\Setting;
use App\Services\SemesterService;
use App\Services\UrutanMatkulService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cetak KRS resmi (PDF, dibuka di tab baru — bukan diunduh) untuk satu mahasiswa pada satu
 * semester. Dipanggil dari tombol "Cetak" di App\Livewire\Admin\Krs\Show.
 *
 * Perhitungan IP di sini SENGAJA menyalin ulang pola yang sudah ada di
 * NilaiController::getTranskripMahasiswa (bukan diekstrak jadi shared service) — repo ini memang
 * menganut "salin, jangan share" antar pintu masuk yang beda (lihat skill siak-livewire-module).
 */
class KrsCetakController extends Controller
{
    public function show(Request $request, int $id): Response
    {
        $mahasiswa = Mahasiswa::with(['prodi.kaprodi', 'semester_masuk', 'status_akademik'])
            ->findOrFail($id);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data KRS mahasiswa ini.');
            }
        }

        $requestedSemesterId = $request->get('id_semester') ? (int) $request->get('id_semester') : null;
        $semester = $requestedSemesterId
            ? Semester::find($requestedSemesterId)
            : Semester::where('is_active', true)->first();

        if (! $semester) {
            abort(404, 'Semester aktif tidak ditemukan. Pilih semester terlebih dahulu sebelum mencetak.');
        }

        $dosenWaliRow = DB::table('dosen_wali')
            ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
            ->where('dosen_wali.id_mahasiswa', $mahasiswa->id)
            ->where('dosen_wali.status', 'active')
            ->whereNull('dosen_wali.deleted_at')
            ->select('dosen.nama', 'dosen.gelar_depan', 'dosen.gelar_belakang')
            ->first();
        $dosenWaliNama = $this->namaLengkapDosen($dosenWaliRow);

        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kelompokKelas',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $mahasiswa->id)
            ->whereNull('deleted_at')
            ->whereHas('kelas', fn ($q) => $q->where('id_semester', $semester->id))
            ->get();

        $krsList = UrutanMatkulService::urutkanKrs($krsList);

        $jadwalByKelas = Jadwal::with('ruangan')
            ->whereIn('id_kelas', $krsList->pluck('id_kelas'))
            ->where('is_active', true)
            ->orderBy('urutan_pertemuan')
            ->get()
            ->groupBy('id_kelas')
            ->map(fn ($rows) => $rows->first());

        $rows = $krsList->map(function ($krs) use ($jadwalByKelas) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $jadwal = $jadwalByKelas->get($krs->id_kelas);

            return [
                'kode' => $matkul->kode ?? '-',
                'nama' => $matkul->nama ?? '-',
                'kelas' => $krs->kelas->kelompokKelas->nama ?? ($krs->kelas->kode ?? '-'),
                'sks' => (int) ($matkul->sks ?? 0),
                'hari' => $jadwal ? ucfirst((string) $jadwal->hari) : '-',
                'jam' => $jadwal && $jadwal->jam_mulai && $jadwal->jam_selesai
                    ? substr((string) $jadwal->jam_mulai, 0, 5).'-'.substr((string) $jadwal->jam_selesai, 0, 5)
                    : '-',
                'ruang' => $jadwal?->ruangan?->nama ?? '-',
                'dosen' => $this->namaLengkapDosen($krs->kelas->dosenPic),
                'disetujui' => $krs->approved_at !== null,
            ];
        })->values();

        $totalMk = $rows->count();
        $totalSks = (int) $rows->sum('sks');
        $semuaDisetujui = $totalMk > 0 && $rows->every(fn ($r) => $r['disetujui']);

        [$ipk, $ipSemesterSebelumnya] = $this->hitungIp($mahasiswa->id, $semester);

        $semesterKe = SemesterService::hitungSemesterDitempuh(
            $mahasiswa->semester_masuk->kode ?? null,
            $semester->kode
        );

        $kop = Setting::whereIn('key', [
            'app_univ_name', 'app_univ_yayasan', 'app_univ_address', 'app_univ_phone', 'app_univ_email', 'app_univ_website',
        ])->pluck('value', 'key');

        $namaPerguruanTinggi = trim((string) $kop->get('app_univ_name')) ?: 'Sikampus';
        $logoSrc = $this->resolveLogoBase64();

        $dokumenNomor = 'KRS/'.$mahasiswa->nim.'/'.$semester->kode.'/'.now()->format('YmdHis');

        $html = $this->buildHtml([
            'nama_pt' => $namaPerguruanTinggi,
            'yayasan' => trim((string) $kop->get('app_univ_yayasan')),
            'alamat' => trim((string) $kop->get('app_univ_address')),
            'telp' => trim((string) $kop->get('app_univ_phone')),
            'email' => trim((string) $kop->get('app_univ_email')),
            'website' => trim((string) $kop->get('app_univ_website')),
            'logo_src' => $logoSrc,
            'prodi' => $mahasiswa->prodi->nama ?? '-',
            'status_akademik' => $mahasiswa->status_akademik->nama ?? '-',
            'semester_label' => $semester->nama.' ('.$semester->kode.')',
            'nama' => $mahasiswa->nama,
            'nim' => $mahasiswa->nim,
            'angkatan' => $mahasiswa->semester_masuk->nama ?? '-',
            'semester_ke' => $semesterKe ?? '-',
            'dosen_wali' => $dosenWaliNama,
            'ip_sebelumnya' => $ipSemesterSebelumnya !== null ? number_format($ipSemesterSebelumnya, 2) : '-',
            'ipk' => $ipk !== null ? number_format($ipk, 2) : '-',
            'total_sks' => $totalSks,
            'rows' => $rows,
            'total_mk' => $totalMk,
            'status_ringkasan' => $totalMk === 0 ? '-' : ($semuaDisetujui ? 'Disetujui' : 'Menunggu Persetujuan'),
            'ketua_prodi' => $mahasiswa->prodi->kaprodi ? $this->namaLengkapDosen($mahasiswa->prodi->kaprodi) : null,
            'dokumen_nomor' => $dokumenNomor,
        ]);

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'KRS_'.preg_replace('/\s+/', '_', $mahasiswa->nim).'_'.$semester->kode.'.pdf';

        // "inline" (bukan streamDownload/attachment) supaya browser membuka PDF-nya langsung di
        // tab baru, bukan memaksa dialog unduh — sesuai permintaan tampil di tab baru.
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array{0: ?float, 1: ?float} [ipk, ip_semester_sebelumnya]
     */
    private function hitungIp(int $mahasiswaId, Semester $semesterTarget): array
    {
        $krsList = Krs::with(['kelas.kurikulumMatkul.matkul', 'kelas.semester'])
            ->where('id_mahasiswa', $mahasiswaId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->get();

        if ($krsList->isEmpty()) {
            return [null, null];
        }

        $nilaiMap = Nilai::whereIn('id_krs', $krsList->pluck('id'))
            ->whereNull('deleted_at')
            ->where('is_final', true)
            ->get()
            ->keyBy('id_krs');

        $totalAngkaMutu = 0.0;
        $totalSks = 0;
        $perSemester = [];

        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = $nilaiMap->get($krs->id);

            if (! $matkul || ! $semester || ! $nilai || $nilai->angka_mutu === null) {
                continue;
            }

            $sks = (int) ($matkul->sks ?? 0);
            if ($sks <= 0) {
                continue;
            }

            $angkaMutu = (float) $nilai->angka_mutu;
            $totalAngkaMutu += $angkaMutu * $sks;
            $totalSks += $sks;

            $kode = (int) $semester->kode;
            $perSemester[$kode] ??= ['angka' => 0.0, 'sks' => 0];
            $perSemester[$kode]['angka'] += $angkaMutu * $sks;
            $perSemester[$kode]['sks'] += $sks;
        }

        $ipk = $totalSks > 0 ? round($totalAngkaMutu / $totalSks, 2) : null;

        $targetKode = (int) $semesterTarget->kode;
        $prevKode = null;
        foreach (array_keys($perSemester) as $kode) {
            if ($kode < $targetKode && ($prevKode === null || $kode > $prevKode)) {
                $prevKode = $kode;
            }
        }

        $ipSemesterSebelumnya = $prevKode !== null && $perSemester[$prevKode]['sks'] > 0
            ? round($perSemester[$prevKode]['angka'] / $perSemester[$prevKode]['sks'], 2)
            : null;

        return [$ipk, $ipSemesterSebelumnya];
    }

    /**
     * Format "{gelar depan} Nama, gelar belakang" — sama persis dengan pola yang sudah dipakai di
     * resources/views/livewire/admin/dosen/index.blade.php dan show.blade.php. Menerima object apa
     * saja yang punya properti nama/gelar_depan/gelar_belakang (model Eloquent Dosen dari relasi
     * dosenPic, atau baris stdClass dari raw query dosen_wali) — bukan cuma model Dosen.
     */
    private function namaLengkapDosen(?object $dosen): string
    {
        if (! $dosen) {
            return '-';
        }

        return trim(
            ($dosen->gelar_depan ? $dosen->gelar_depan.' ' : '').
            $dosen->nama.
            ($dosen->gelar_belakang ? ', '.$dosen->gelar_belakang : '')
        );
    }

    /**
     * Sama pola dengan JadwalDosenController::rpsPdfResolveLogoSrc — Dompdf tidak diizinkan fetch
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

        // Setting bisa berisi path relatif ("storage/logos/x.png") atau URL penuh dengan host
        // ("http://host/storage/logos/x.png") tergantung dari mana ia terakhir disimpan — cari
        // segmen "storage/..."-nya di mana pun posisinya, jangan asumsikan seluruh value path lokal
        // (kalau ada host di depannya, public_path()/storage_path() akan membentuk path yang tidak
        // pernah ada di disk dan logo diam-diam gagal tampil).
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
     * @param  array<string, mixed>  $d
     */
    private function buildHtml(array $d): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #171717; }
        .kop-table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .kop-table td { border: none; padding: 0; vertical-align: middle; }
        .kop-logo { width: 90px; text-align: center; }
        .kop-logo img { width: 80px; height: 80px; }
        .kop-text { text-align: center; font-family: "Times New Roman", Times, serif; }
        .kop-yayasan { font-size: 11pt; }
        .kop-univ { font-size: 16pt; font-weight: bold; margin: 2px 0; }
        .kop-prodi { font-size: 12pt; }
        .kop-address, .kop-contact { font-size: 9pt; }
        hr.rule { border: none; border-top: 2px solid #171717; margin: 6px 0 14px 0; }
        .title { text-align: center; font-size: 15pt; font-weight: bold; font-family: "Times New Roman", Times, serif; }
        .subtitle { text-align: center; font-size: 10.5pt; margin-top: 2px; margin-bottom: 10px; }
        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .info-grid td { border: none; padding: 2px 4px; font-size: 9pt; }
        .info-label { color: #525252; width: 32%; }
        .section-title { font-size: 10.5pt; font-weight: bold; margin: 10px 0 5px 0; border-bottom: 1px solid #a3a3a3; padding-bottom: 3px; }
        table.matkul { width: 100%; border-collapse: collapse; font-size: 8pt; }
        table.matkul th { background-color: #171717; color: #fff; padding: 4px; border: 1px solid #171717; text-align: center; }
        table.matkul td { padding: 4px; border: 1px solid #a3a3a3; }
        td.num { text-align: center; }
        .ringkasan { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .ringkasan td { border: 1px solid #a3a3a3; padding: 6px; font-size: 9pt; }
        .ttd-table { width: 100%; border-collapse: collapse; margin-top: 24px; }
        .ttd-table td { border: none; text-align: center; font-size: 9pt; padding: 0 8px; width: 33%; }
        .ttd-space { height: 50px; }
        .ttd-line { border-top: 1px solid #171717; padding-top: 3px; }
        .footer { margin-top: 24px; font-size: 7.5pt; color: #525252; border-top: 1px solid #d4d4d4; padding-top: 6px; }
        </style></head><body>';

        $html .= '<table class="kop-table"><tr>';
        $html .= '<td class="kop-logo">'.($d['logo_src'] ? '<img src="'.$d['logo_src'].'">' : '').'</td>';
        $html .= '<td class="kop-text">';
        if ($d['yayasan'] !== '') {
            $html .= '<div class="kop-yayasan">'.$esc(mb_strtoupper($d['yayasan'])).'</div>';
        }
        $html .= '<div class="kop-univ">'.$esc(mb_strtoupper($d['nama_pt'])).'</div>';
        $html .= '<div class="kop-prodi">PROGRAM STUDI '.$esc(mb_strtoupper($d['prodi'])).'</div>';
        if ($d['alamat'] !== '') {
            $html .= '<div class="kop-address">'.$esc($d['alamat']).'</div>';
        }
        if ($d['email'] !== '' || $d['telp'] !== '') {
            $html .= '<div class="kop-contact">'
                .($d['email'] !== '' ? 'Email: '.$esc($d['email']) : '')
                .($d['email'] !== '' && $d['telp'] !== '' ? ' &nbsp; ' : '')
                .($d['telp'] !== '' ? 'Telp: '.$esc($d['telp']) : '')
                .'</div>';
        }
        if ($d['website'] !== '') {
            $html .= '<div class="kop-contact">Website: '.$esc($d['website']).'</div>';
        }
        $html .= '</td>';
        $html .= '<td style="width:90px"></td>'; // penyeimbang visual supaya blok teks tetap tampak di tengah, mengimbangi lebar kolom logo
        $html .= '</tr></table>';
        $html .= '<hr class="rule">';

        $html .= '<div class="title">Kartu Rencana Studi</div>';
        $html .= '<div class="subtitle">Semester '.$esc($d['semester_label']).'</div>';

        $html .= '<table class="info-grid"><tr>';
        $html .= '<td style="width:50%"><table class="info-grid">';
        $html .= '<tr><td class="info-label">Nama</td><td>: '.$esc($d['nama']).'</td></tr>';
        $html .= '<tr><td class="info-label">NIM</td><td>: '.$esc($d['nim']).'</td></tr>';
        $html .= '<tr><td class="info-label">Angkatan</td><td>: '.$esc($d['angkatan']).'</td></tr>';
        $html .= '<tr><td class="info-label">Semester</td><td>: '.$esc($d['semester_ke']).'</td></tr>';
        $html .= '<tr><td class="info-label">Status Akademik</td><td>: '.$esc($d['status_akademik']).'</td></tr>';
        $html .= '</table></td>';
        $html .= '<td style="width:50%"><table class="info-grid">';
        $html .= '<tr><td class="info-label">Dosen Wali</td><td>: '.$esc($d['dosen_wali']).'</td></tr>';
        $html .= '<tr><td class="info-label">IP Semester Sebelumnya</td><td>: '.$esc($d['ip_sebelumnya']).'</td></tr>';
        $html .= '<tr><td class="info-label">IPK</td><td>: '.$esc($d['ipk']).'</td></tr>';
        $html .= '<tr><td class="info-label">Total SKS Diambil</td><td>: '.$esc($d['total_sks']).'</td></tr>';
        $html .= '</table></td>';
        $html .= '</tr></table>';

        $html .= '<div class="section-title">Mata Kuliah yang Diambil</div>';
        $html .= '<table class="matkul"><thead><tr>';
        foreach (['No' => '4%', 'Kode' => '8%', 'Mata Kuliah' => '27%', 'Kelas' => '9%', 'SKS' => '5%', 'Hari' => '8%', 'Jam' => '12%', 'Ruang' => '9%', 'Dosen' => '18%'] as $label => $width) {
            $html .= '<th style="width:'.$width.'">'.$label.'</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (empty($d['rows'])) {
            $html .= '<tr><td colspan="9" style="text-align:center;padding:10px;">Tidak ada data KRS pada semester ini.</td></tr>';
        } else {
            $no = 1;
            foreach ($d['rows'] as $row) {
                $html .= '<tr>';
                $html .= '<td class="num">'.$no.'</td>';
                $html .= '<td>'.$esc($row['kode']).'</td>';
                $html .= '<td>'.$esc($row['nama']).'</td>';
                $html .= '<td>'.$esc($row['kelas']).'</td>';
                $html .= '<td class="num">'.$esc($row['sks']).'</td>';
                $html .= '<td>'.$esc($row['hari']).'</td>';
                $html .= '<td>'.$esc($row['jam']).'</td>';
                $html .= '<td>'.$esc($row['ruang']).'</td>';
                $html .= '<td>'.$esc($row['dosen']).'</td>';
                $html .= '</tr>';
                $no++;
            }
        }
        $html .= '</tbody></table>';

        $html .= '<div class="section-title">Ringkasan Beban Studi</div>';
        $html .= '<table class="ringkasan"><tr>';
        $html .= '<td>Jumlah Mata Kuliah<br><strong>'.$esc($d['total_mk']).'</strong></td>';
        $html .= '<td>Jumlah SKS<br><strong>'.$esc($d['total_sks']).'</strong></td>';
        $html .= '<td>Status<br><strong>'.$esc($d['status_ringkasan']).'</strong></td>';
        $html .= '</tr></table>';

        $html .= '<table class="ttd-table"><tr>';
        $html .= '<td><div class="ttd-space"></div><div class="ttd-line">Mahasiswa<br>'.$esc($d['nama']).'<br>Tanggal: ____________</div></td>';
        $html .= '<td><div class="ttd-space"></div><div class="ttd-line">Dosen Wali<br>'.$esc($d['dosen_wali']).'<br>Tanggal: ____________</div></td>';
        $html .= '<td><div class="ttd-space"></div><div class="ttd-line">Ketua Program Studi<br>'.$esc($d['ketua_prodi'] ?: '____________').'<br>Tanggal: ____________</div></td>';
        $html .= '</tr></table>';

        $html .= '<div class="footer">';
        $html .= 'Dokumen ini diterbitkan oleh Sistem Informasi Akademik.<br>';
        $html .= 'Dicetak pada: '.now()->format('d/m/Y H:i').'<br>';
        $html .= 'Nomor Dokumen: '.$esc($d['dokumen_nomor']).'<br>';
        $html .= 'Versi: 1.0';
        $html .= '</div>';

        $html .= '</body></html>';

        return $html;
    }
}
