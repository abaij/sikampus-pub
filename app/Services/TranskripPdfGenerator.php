<?php

namespace App\Services;

use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\Setting;
use App\Models\Yudisium;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Menyusun dan merender transkrip nilai resmi dwibahasa (Indonesia/Inggris) jadi PDF lewat Dompdf.
 *
 * Dipakai dari tombol Cetak → "Transkrip Nilai" di halaman detail nilai
 * (App\Livewire\Admin\Nilai\Show, lewat NilaiExportController::transkrip). Berbeda dengan
 * "Laporan Nilai" di controller yang sama: laporan mengikuti filter semester yang sedang aktif di
 * layar dan menampilkan seluruh KRS, sedangkan transkrip SELALU seluruh masa studi dan hanya mata
 * kuliah yang sudah bernilai final.
 *
 * Aturan penyaringan dan perhitungan IPK di sini SENGAJA menyalin ulang pola yang sudah ada di
 * App\Livewire\Mahasiswa\Nilai\Transkrip dan NilaiController (bukan memanggil service bersama) —
 * repo ini memang menganut "salin, jangan share" antar pintu masuk yang berbeda.
 *
 * Nomor Ijazah Nasional dan Nomor Transkrip diambil dari tabel `yudisium`; identitas pejabat
 * penandatangan dari tabel `settings` (key `app_transkrip_*`, diisi lewat
 * Pengaturan → Akademik → Penandatangan Transkrip).
 */
class TranskripPdfGenerator
{
    private const BULAN_ID = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    private const BULAN_EN = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * Key tabel `settings` untuk identitas pejabat penandatangan transkrip, diisi lewat
     * App\Livewire\Admin\Transkrip\Penandatangan (Pengaturan → Akademik → Penandatangan
     * Transkrip). Sengaja satu awalan `app_transkrip_` supaya sejalan dengan `app_univ_*`
     * (identitas perguruan tinggi) dan `app_mail_*` (SMTP) yang sudah ada di tabel yang sama.
     *
     * @var array<string, string> nama field => key settings
     */
    public const KEY_PENANDATANGAN = [
        'jabatan' => 'app_transkrip_jabatan',
        'jabatan_en' => 'app_transkrip_jabatan_en',
        'nama' => 'app_transkrip_nama_pejabat',
        'nip' => 'app_transkrip_nip',
        'kota_terbit' => 'app_transkrip_kota_terbit',
    ];

    /**
     * @return array{jabatan: string, jabatan_en: string, nama: string, nip: string, kota_terbit: string}
     */
    public static function pengaturanPenandatangan(): array
    {
        $rows = Setting::whereIn('key', array_values(self::KEY_PENANDATANGAN))->pluck('value', 'key');

        $hasil = [];
        foreach (self::KEY_PENANDATANGAN as $field => $key) {
            $hasil[$field] = trim((string) $rows->get($key));
        }

        return $hasil;
    }

    /**
     * Kumpulkan seluruh data yang dibutuhkan halaman transkrip untuk satu mahasiswa.
     *
     * @return array<string, mixed>
     */
    public function payload(Mahasiswa $mahasiswa): array
    {
        $mahasiswa->loadMissing(['prodi.jenjang']);

        // Ambil baris yudisium terbaru milik mahasiswa ini — dari sinilah Nomor Ijazah Nasional
        // dan Nomor Transkrip berasal. Mahasiswa yang belum diyudisium tetap bisa dicetak
        // transkripnya; nomor-nomornya cukup tampil sebagai "-".
        $yudisium = Yudisium::where('id_mahasiswa', $mahasiswa->id)
            ->orderByDesc('id')
            ->first();

        [$rows, $totalSks, $ipkHitung] = $this->kumpulkanMataKuliah($mahasiswa->id);

        $kop = Setting::whereIn('key', [
            'app_univ_name', 'app_univ_yayasan', 'app_univ_address',
            'app_univ_phone', 'app_univ_email', 'app_univ_website', 'app_univ_city',
        ])->pluck('value', 'key');

        $pengaturan = self::pengaturanPenandatangan();
        $kotaTerbit = $pengaturan['kota_terbit'] !== ''
            ? $pengaturan['kota_terbit']
            : trim((string) $kop->get('app_univ_city'));

        $jenjang = $mahasiswa->prodi?->jenjang;
        $jenjangLabel = $jenjang
            ? trim($jenjang->nama.($jenjang->kode ? ' ('.$jenjang->kode.')' : ''))
            : '';

        // IPK: pakai angka yang sudah dikunci petugas di baris yudisium kalau ada, karena itulah
        // angka resmi yang dipakai di SK; kalau belum diisi, jatuh ke hasil hitung dari nilai.
        $ipk = $yudisium?->ipk !== null ? (float) $yudisium->ipk : $ipkHitung;

        $tglLulus = $this->parseTanggal($yudisium?->tgl_keluar);
        $tglLahir = $mahasiswa->tanggal_lahir
            ? $this->parseTanggal($mahasiswa->tanggal_lahir->format('Y-m-d'))
            : null;

        $tempatLahir = trim((string) $mahasiswa->id_tempat_lahir);
        $tempatTanggalLahir = trim(implode(', ', array_filter([
            $tempatLahir !== '' ? mb_strtoupper($tempatLahir) : null,
            $tglLahir ? mb_strtoupper($this->tanggalId($tglLahir)) : null,
        ])));

        return [
            'nama_pt' => trim((string) $kop->get('app_univ_name')) ?: config('app.name'),
            'yayasan' => trim((string) $kop->get('app_univ_yayasan')),
            'alamat' => trim((string) $kop->get('app_univ_address')),
            'telp' => trim((string) $kop->get('app_univ_phone')),
            'email' => trim((string) $kop->get('app_univ_email')),
            'website' => trim((string) $kop->get('app_univ_website')),
            'logo' => $this->gambar(Setting::where('key', 'app_univ_logo')->value('value')),
            'foto' => $this->gambar($mahasiswa->foto),

            'no_ijazah' => trim((string) ($yudisium?->no_ijazah ?? '')),
            'no_transkrip' => trim((string) ($yudisium?->no_transkrip ?? '')),
            'nama' => (string) $mahasiswa->nama,
            'tempat_tanggal_lahir' => $tempatTanggalLahir,
            'nim' => (string) $mahasiswa->nim,
            'tanggal_lulus_id' => $tglLulus ? $this->tanggalId($tglLulus) : '',
            'tanggal_lulus_en' => $tglLulus ? $this->tanggalEn($tglLulus) : '',
            'jenjang' => mb_strtoupper($jenjangLabel),
            'prodi' => (string) ($mahasiswa->prodi?->nama ?? ''),
            'prodi_en' => trim((string) ($mahasiswa->prodi?->nama_en ?? '')),

            'rows' => $rows,
            'total_sks' => $totalSks,
            'ipk' => $ipk,
            'predikat' => trim((string) ($yudisium?->keterangan ?? '')),
            'judul_ta' => trim((string) ($yudisium?->judul_skripsi ?? '')),

            'kota_terbit' => $kotaTerbit,
            'tanggal_terbit_id' => $this->tanggalId(now()),
            'tanggal_terbit_en' => $this->tanggalEn(now()),
            'jabatan' => $pengaturan['jabatan'],
            'jabatan_en' => $pengaturan['jabatan_en'],
            'pejabat' => $pengaturan['nama'],
            'nip' => $pengaturan['nip'],
        ];
    }

    public function pdf(Mahasiswa $mahasiswa): string
    {
        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($this->payload($mahasiswa)));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Hanya mata kuliah yang KRS-nya disetujui DAN sudah punya nilai final berhuruf mutu yang
     * masuk transkrip — sama persis dengan aturan di App\Livewire\Mahasiswa\Nilai\Transkrip.
     *
     * @return array{0: list<array<string, mixed>>, 1: int, 2: float|null}
     */
    private function kumpulkanMataKuliah(int $mahasiswaId): array
    {
        $krsList = Krs::with(['kelas.kurikulumMatkul.matkul'])
            ->where('id_mahasiswa', $mahasiswaId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->get();

        if ($krsList->isEmpty()) {
            return [[], 0, null];
        }

        $nilaiMap = Nilai::whereIn('id_krs', $krsList->pluck('id'))
            ->whereNull('deleted_at')
            ->where('is_final', true)
            ->get()
            ->keyBy('id_krs');

        $rows = [];
        $totalSks = 0;
        $totalAngkaMutu = 0.0;
        $totalSksBernilai = 0;

        foreach ($krsList as $krs) {
            $matkul = $krs->kelas?->kurikulumMatkul?->matkul;
            $nilai = $nilaiMap->get($krs->id);

            if (! $matkul || ! $nilai || ! $nilai->huruf_mutu) {
                continue;
            }

            $sks = (int) ($matkul->sks ?? 0);
            $totalSks += $sks;

            $angkaMutu = $nilai->angka_mutu !== null ? (float) $nilai->angka_mutu : null;
            if ($angkaMutu !== null && $sks > 0) {
                $totalAngkaMutu += $angkaMutu * $sks;
                $totalSksBernilai += $sks;
            }

            $rows[] = [
                'kode' => (string) ($matkul->kode ?? ''),
                'nama' => (string) ($matkul->nama ?? ''),
                'nama_en' => trim((string) ($matkul->nama_en ?? '')),
                'sks' => $sks,
                'huruf' => (string) $nilai->huruf_mutu,
                'angka' => $angkaMutu,
            ];
        }

        // Urut alfabetis berdasarkan nama mata kuliah, mengikuti format transkrip acuan
        // (bukan urut semester) — SORT_FLAG_CASE supaya "BID" dan "Bd." tidak terpisah blok.
        usort($rows, fn (array $a, array $b) => strcasecmp($a['nama'], $b['nama']));

        $ipk = $totalSksBernilai > 0 ? round($totalAngkaMutu / $totalSksBernilai, 2) : null;

        return [$rows, $totalSks, $ipk];
    }

    private function parseTanggal(?string $value): ?\DateTimeInterface
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }

        // tgl_keluar di tabel yudisium bertipe string bebas, jadi isinya belum tentu tanggal
        // yang valid — jangan biarkan satu baris berformat aneh menggagalkan seluruh cetakan.
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function tanggalId(\DateTimeInterface $date): string
    {
        return $date->format('j').' '.self::BULAN_ID[(int) $date->format('n')].' '.$date->format('Y');
    }

    private function tanggalEn(\DateTimeInterface $date): string
    {
        return $date->format('j').' '.self::BULAN_EN[(int) $date->format('n')].' '.$date->format('Y');
    }

    /**
     * Ubah nilai gambar dari database jadi data URI base64 berikut dimensi aslinya.
     *
     * Dompdf tidak pernah mengambil resource remote sendiri, jadi setiap gambar harus sudah
     * tertanam di HTML sebelum render. Nilai yang masuk ke sini bentuknya tidak seragam:
     * `mahasiswa.foto` menyimpan path relatif disk public ("mahasiswa/foto/x.png"), sedangkan
     * `app_univ_logo` bisa berisi URL absolut lengkap dengan host
     * ("https://host/storage/logos/x.png") tergantung dari mana ia terakhir disimpan.
     *
     * Selalu mengembalikan null (bukan melempar) kalau gambar tidak ketemu — transkrip tanpa
     * logo atau foto tetap dokumen yang sah, jangan sampai gagal cetak karena satu berkas hilang.
     *
     * @return array{src: string, width: int, height: int}|null
     */
    private function gambar(?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'data:image')) {
            $raw = @base64_decode((string) preg_replace('~^data:image/[^;]+;base64,~', '', $value), true);

            return $raw === false ? null : $this->keDataUri($raw);
        }

        return $this->gambarDariDisk($value) ?? $this->gambarDariUrl($value);
    }

    /**
     * @return array{src: string, width: int, height: int}|null
     */
    private function gambarDariDisk(string $value): ?array
    {
        // Pola pencocokan yang sama dengan App\Services\KtmImageGenerator::resolveMhsFotoPath:
        // ambil segmen setelah "storage/" kalau ada (menangani URL penuh maupun path ber-prefix),
        // selain itu perlakukan value sebagai path relatif disk public apa adanya.
        if (preg_match('~storage/([^?\s#]+)~', $value, $m)) {
            $relative = $m[1];
        } elseif (! str_contains($value, '://')) {
            $relative = ltrim($value, '/');
        } else {
            return null;
        }

        if ($relative === '') {
            return null;
        }

        try {
            if (! Storage::disk('public')->exists($relative)) {
                return null;
            }

            $path = Storage::disk('public')->path($relative);
        } catch (\Throwable) {
            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        return $raw === false ? null : $this->keDataUri($raw);
    }

    /**
     * Fallback untuk value yang berisi URL absolut tapi berkasnya TIDAK ada di disk lokal —
     * kasus nyata pada instalasi yang databasenya disalin dari server lain, sehingga
     * `app_univ_logo` menunjuk ke host produksi dan logo diam-diam hilang dari setiap cetakan.
     *
     * Hasilnya di-cache 24 jam supaya tidak ada HTTP request per lembar transkrip, dan
     * timeout-nya pendek supaya host yang tidak responsif tidak menggantung proses cetak.
     * Kegagalan disimpan sebagai string kosong — Cache::remember() tidak pernah menyimpan null,
     * jadi tanpa penanda ini URL yang mati akan diulang setiap kali cetak.
     *
     * @return array{src: string, width: int, height: int}|null
     */
    private function gambarDariUrl(string $value): ?array
    {
        if (! preg_match('~^https?://~i', $value)) {
            return null;
        }

        $hasil = Cache::remember(
            'plugin_transkrip_pdf_gambar_'.md5($value),
            now()->addDay(),
            function () use ($value) {
                try {
                    $response = Http::connectTimeout(3)->timeout(5)->get($value);
                } catch (\Throwable) {
                    return '';
                }

                if (! $response->successful()) {
                    return '';
                }

                $raw = $response->body();
                if ($raw === '' || strlen($raw) > 3 * 1024 * 1024) {
                    return '';
                }

                return $this->keDataUri($raw) ?? '';
            }
        );

        return is_array($hasil) ? $hasil : null;
    }

    /**
     * @return array{src: string, width: int, height: int}|null
     */
    private function keDataUri(string $raw): ?array
    {
        // Pastikan isinya benar-benar gambar: kalau URL logo ternyata mengembalikan halaman error
        // HTML, menyisipkannya sebagai data URI hanya akan membuat Dompdf melempar exception di
        // tengah render — lebih baik transkrip terbit tanpa logo daripada gagal sama sekali.
        $info = @getimagesizefromstring($raw);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return null;
        }

        return [
            'src' => 'data:'.($info['mime'] ?? 'image/png').';base64,'.base64_encode($raw),
            'width' => (int) $info[0],
            'height' => (int) $info[1],
        ];
    }

    /**
     * Ukuran tampil yang muat di dalam kotak $maxW x $maxH tanpa mengubah rasio aspek.
     *
     * Dompdf tidak mendukung object-fit, jadi kalau lebar DAN tinggi <img> dipatok sekaligus
     * foto akan tergepeng — rasionya harus dihitung di sini, bukan diserahkan ke CSS.
     *
     * @param  array{src: string, width: int, height: int}  $gambar
     * @return array{0: float, 1: float}
     */
    private function muatKotak(array $gambar, float $maxW, float $maxH): array
    {
        $skala = min($maxW / $gambar['width'], $maxH / $gambar['height']);

        return [round($gambar['width'] * $skala, 2), round($gambar['height'] * $skala, 2)];
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public function html(array $d): string
    {
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $atau = fn (string $v) => $v !== '' ? $v : '-';

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 15mm 15mm 12mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #171717; }
        .kop-table { width: 100%; border-collapse: collapse; }
        .kop-table td { border: none; padding: 0; vertical-align: middle; }
        .kop-logo { width: 80px; text-align: center; }
        .kop-text { text-align: center; font-family: "Times New Roman", Times, serif; }
        .kop-yayasan { font-size: 11pt; }
        .kop-univ { font-size: 16pt; font-weight: bold; margin: 2px 0; }
        .kop-prodi { font-size: 12pt; }
        .kop-address, .kop-contact { font-size: 8.5pt; }
        hr.rule { border: none; border-top: 2px solid #171717; margin: 6px 0 14px 0; }
        .title { text-align: center; font-size: 13pt; font-weight: bold; font-family: "Times New Roman", Times, serif; margin-bottom: 10px; }
        table.identitas { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.identitas > tbody > tr > td { border: none; padding: 0; vertical-align: top; }
        .foto-box { width: 28mm; height: 37mm; border: 1px solid #171717; text-align: center; }
        .foto-kosong { font-size: 7pt; color: #a3a3a3; }
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { border: none; padding: 1px 0; vertical-align: top; font-size: 9pt; }
        td.info-label { width: 40%; }
        td.info-sep { width: 4%; }
        .label-en { font-size: 7.5pt; color: #525252; }
        table.matkul { width: 100%; border-collapse: collapse; font-size: 8pt; }
        table.matkul th { border: 1px solid #171717; padding: 4px 3px; text-align: center; font-size: 7.5pt; line-height: 1.25; }
        table.matkul td { border: 1px solid #171717; padding: 2px 4px; vertical-align: top; }
        table.matkul td.c { text-align: center; }
        table.ringkas { width: 100%; border-collapse: collapse; margin-top: 10px; page-break-inside: avoid; }
        table.ringkas td { border: none; padding: 1px 0; font-size: 9pt; vertical-align: top; }
        table.ringkas tr { page-break-inside: avoid; }
        table.ttd-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.ttd-table td { border: none; padding: 0; vertical-align: top; }
        .ttd { font-size: 9pt; line-height: 1.4; }
        .ttd-en { font-style: italic; color: #404040; }
        .ttd-space { height: 52px; }
        .ttd-nama { font-weight: bold; }
        </style></head><body>';

        // ---- Kop surat -------------------------------------------------------------------
        $html .= '<table class="kop-table"><tr>';
        $html .= '<td class="kop-logo">';
        if ($d['logo']) {
            [$logoW, $logoH] = $this->muatKotak($d['logo'], 72, 72);
            $html .= '<img src="'.$d['logo']['src'].'" style="width:'.$logoW.'px;height:'.$logoH.'px">';
        }
        $html .= '</td>';
        $html .= '<td class="kop-text">';
        if ($d['yayasan'] !== '') {
            $html .= '<div class="kop-yayasan">'.$esc(mb_strtoupper($d['yayasan'])).'</div>';
        }
        $html .= '<div class="kop-univ">'.$esc(mb_strtoupper($d['nama_pt'])).'</div>';
        if ($d['prodi'] !== '') {
            $html .= '<div class="kop-prodi">PROGRAM STUDI '.$esc(mb_strtoupper($d['prodi'])).'</div>';
        }
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
        // Kolom kosong selebar kolom logo — penyeimbang visual supaya blok teks benar-benar
        // tampak di tengah halaman, bukan bergeser ke kanan karena logo di kiri.
        $html .= '<td style="width:80px"></td>';
        $html .= '</tr></table>';
        $html .= '<hr class="rule">';

        $html .= '<div class="title">TRANSKRIP NILAI / <i>ACADEMIC TRANSCRIPT</i></div>';

        // ---- Identitas -------------------------------------------------------------------
        $prodiLabel = $d['prodi_en'] !== ''
            ? $d['prodi'].' / '.$d['prodi_en']
            : $d['prodi'];

        $baris = [
            ['Nomor Ijazah Nasional', 'National Certificate Number', $atau($d['no_ijazah'])],
            ['Nomor Transkrip', 'Transcript Number', $atau($d['no_transkrip'])],
            ['Nama', 'Name', $atau($d['nama'])],
            ['Tempat, Tanggal Lahir', 'Place, Date of birth', $atau($d['tempat_tanggal_lahir'])],
            ['NIM', 'Student ID number', $atau($d['nim'])],
            ['Tanggal Kelulusan', 'Date of Graduation', $d['tanggal_lulus_id'] !== ''
                ? $d['tanggal_lulus_id'].' / '.$d['tanggal_lulus_en']
                : '-'],
            ['Jenjang', 'Program', $atau($d['jenjang'])],
            ['Program Studi', 'Study Program', $atau(mb_strtoupper($prodiLabel))],
        ];

        // Kolom kiri identitas, kolom kanan pas foto — mengikuti tata letak transkrip acuan.
        $html .= '<table class="identitas"><tr><td>';
        $html .= '<table class="info">';
        foreach ($baris as [$labelId, $labelEn, $value]) {
            $html .= '<tr>';
            $html .= '<td class="info-label">'.$esc($labelId).' <span class="label-en">/ '.$esc($labelEn).'</span></td>';
            $html .= '<td class="info-sep">:</td>';
            $html .= '<td>'.$esc($value).'</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '</td>';

        // Kotak foto tetap digambar meski fotonya tidak ada, supaya tata letak konsisten dan
        // pas foto masih bisa ditempel manual di transkrip yang sudah tercetak.
        $html .= '<td style="width:32mm"><div class="foto-box">';
        if ($d['foto']) {
            // Sedikit lebih kecil dari kotaknya supaya garis tepi kotak tidak tertutup foto.
            [$fotoW, $fotoH] = $this->muatKotak($d['foto'], 26.5 * 3.7795, 35.5 * 3.7795);
            $html .= '<img src="'.$d['foto']['src'].'" style="width:'.$fotoW.'px;height:'.$fotoH.'px">';
        } else {
            $html .= '<div class="foto-kosong" style="padding-top:17mm">Pas Foto<br>3 x 4</div>';
        }
        $html .= '</div></td>';
        $html .= '</tr></table>';

        // ---- Tabel mata kuliah -----------------------------------------------------------
        $html .= '<table class="matkul"><thead><tr>';
        $html .= '<th style="width:5%">NO</th>';
        $html .= '<th style="width:14%">KODE/<br>CODE</th>';
        $html .= '<th style="width:50%">MATA KULIAH/<br>COURSE NAME</th>';
        $html .= '<th style="width:8%">SKS/<br>CREDIT</th>';
        $html .= '<th style="width:11%">NILAI HURUF/<br>GRADE</th>';
        $html .= '<th style="width:12%">NILAI ANGKA/<br>GRADE POINT</th>';
        $html .= '</tr></thead><tbody>';

        if ($d['rows'] === []) {
            $html .= '<tr><td colspan="6" class="c" style="padding:12px">Belum ada mata kuliah bernilai final untuk mahasiswa ini.</td></tr>';
        } else {
            $no = 1;
            foreach ($d['rows'] as $row) {
                $namaMk = $row['nama_en'] !== ''
                    ? $row['nama'].' / '.$row['nama_en']
                    : $row['nama'];

                $html .= '<tr>';
                $html .= '<td class="c">'.$no.'</td>';
                $html .= '<td>'.$esc($atau($row['kode'])).'</td>';
                $html .= '<td>'.$esc($namaMk).'</td>';
                $html .= '<td class="c">'.$esc($row['sks']).'</td>';
                $html .= '<td class="c">'.$esc($row['huruf']).'</td>';
                $html .= '<td class="c">'.($row['angka'] !== null ? number_format($row['angka'], 2) : '-').'</td>';
                $html .= '</tr>';
                $no++;
            }
        }
        $html .= '</tbody></table>';

        // ---- Ringkasan -------------------------------------------------------------------
        $ipkLabel = ($d['ipk'] !== null ? number_format($d['ipk'], 2) : '-')
            .' / '.$atau($d['predikat']);

        $ringkas = [
            ['Judul Tugas Akhir', 'Title of Thesis', $atau($d['judul_ta'])],
            ['Perolehan SKS', 'Total Credit', (string) $d['total_sks']],
            ['IPK / Yudisium', 'Grade Point', $ipkLabel],
        ];

        $html .= '<table class="ringkas">';
        foreach ($ringkas as [$labelId, $labelEn, $value]) {
            $html .= '<tr>';
            $html .= '<td class="info-label">'.$esc($labelId).' <span class="label-en">/ '.$esc($labelEn).'</span></td>';
            $html .= '<td class="info-sep">:</td>';
            $html .= '<td>'.$esc($value).'</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        // ---- Blok penerbitan & tanda tangan ----------------------------------------------
        $kota = $d['kota_terbit'] !== '' ? $d['kota_terbit'] : '-';

        // Kolom kiri kosong selebar 52% — memposisikan blok penerbitan & tanda tangan di
        // paruh kanan halaman seperti transkrip acuan, sambil teksnya tetap rata kiri di
        // dalam kolomnya (bukan text-align:right, yang akan membuat tiap baris rata kanan).
        $html .= '<table class="ttd-table"><tr><td style="width:52%"></td><td>';
        $html .= '<div class="ttd">';
        $html .= 'Diterbitkan di '.$esc($kota).', '.$esc($d['tanggal_terbit_id']).'<br>';
        $html .= '<span class="ttd-en">Issued in '.$esc($kota).', '.$esc($d['tanggal_terbit_en']).'</span>';

        if ($d['jabatan'] !== '' || $d['jabatan_en'] !== '') {
            $html .= '<div style="margin-top:14px">';
            if ($d['jabatan'] !== '') {
                $html .= $esc($d['jabatan']).'<br>';
            }
            if ($d['jabatan_en'] !== '') {
                $html .= '<span class="ttd-en">'.$esc($d['jabatan_en']).'</span>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="ttd-space"></div>';
        $html .= '<div class="ttd-nama">'.$esc($atau($d['pejabat'])).'</div>';
        $html .= '<div>NIP '.$esc($atau($d['nip'])).'</div>';
        $html .= '</div>';
        $html .= '</td></tr></table>';

        $html .= '</body></html>';

        return $html;
    }
}
