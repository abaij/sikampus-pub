<?php

namespace App\Livewire\Dosen\Nilai;

use App\Models\BobotPenilaian;
use App\Models\Dosen;
use App\Models\JadwalDosen;
use App\Models\JenisPenilaian;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\NilaiRevisi;
use App\Models\Notifikasi;
use App\Models\RentangNilai;
use App\Models\Semester;
use App\Services\NilaiKelasDataService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Rekap extends Component
{
    // Locked: kalkulasiDenganRentangDefault(), finalisasi(), dan saveEditNilai() memakai kelasId
    // langsung tanpa mengecek ulang akses setiap kali — tanpa ini, kelasId bisa "disentuh" lewat
    // request Livewire yang dimanipulasi untuk mengubah nilai kelas yang tidak diampu dosen ini.
    #[Locked]
    public int $kelasId;

    #[Locked]
    public int $dosenId;

    public bool $showRentangModal = false;

    /** @var array<int, array{nilai_huruf: string, nilai_angka: float, nilai_rendah: float, nilai_tinggi: float}> */
    public array $rentangForm = [];

    public bool $showEditModal = false;

    public ?int $editKrsId = null;

    public string $editHurufMutu = '';

    public string $editAngkaMutu = '';

    public string $editKeterangan = '';

    public bool $editRevisiChecked = false;

    public function mount(int $kelasId): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $kelas = Kelas::find($kelasId);
        abort_unless($kelas, 404, 'Kelas tidak ditemukan.');
        abort_unless($this->dosenHasAccess($kelas), 403, 'Anda tidak memiliki akses ke kelas ini.');

        $this->kelasId = $kelasId;
    }

    /**
     * Akses dosen ke satu kelas — sama persis dengan Kehadiran\RekapKelas::dosenHasAccess:
     * PIC kelas, tercatat sebagai pengampu di kelas_dosen, atau punya jadwal_dosen aktif.
     * kelas_dosen wajib ikut, karena daftar kelas di Nilai/Arsip juga bersumber dari sana.
     */
    private function dosenHasAccess(Kelas $kelas): bool
    {
        if ((int) $kelas->id_dosen_pic === $this->dosenId) {
            return true;
        }

        if (KelasDosen::where('id_dosen', $this->dosenId)->where('id_kelas', $kelas->id)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return JadwalDosen::whereHas('jadwal', fn ($q) => $q->where('id_kelas', $kelas->id))
            ->where('id_dosen', $this->dosenId)
            ->where('status', 'active')
            ->exists();
    }

    #[Computed]
    public function kelas(): Kelas
    {
        return Kelas::with(['kurikulumMatkul.matkul', 'prodi', 'semester'])->findOrFail($this->kelasId);
    }

    #[Computed]
    public function data(): array
    {
        return NilaiKelasDataService::build($this->kelas);
    }

    /**
     * Rekap kelas di luar semester aktif bersifat baca saja: nilai semester lampau tidak boleh
     * dikalkulasi ulang, direvisi, atau difinalisasi dari sini. Aturannya sama dengan tombol
     * Input Nilai di daftar kelas (Dosen\Nilai\Index) dan penguncian di Dosen\Nilai\Input.
     */
    #[Computed]
    public function bolehUbah(): bool
    {
        $semesterAktif = Semester::where('is_active', true)->whereNull('deleted_at')->first();

        return $semesterAktif !== null
            && (int) $this->kelas->id_semester === (int) $semesterAktif->id;
    }

    /**
     * Dipanggil di awal setiap aksi yang mengubah data — menyembunyikan tombolnya saja tidak
     * cukup, karena aksi Livewire bisa dipanggil langsung dari sisi klien.
     */
    private function pastikanBolehUbah(): void
    {
        abort_unless($this->bolehUbah, 403, 'Nilai semester ini sudah tidak bisa diubah karena bukan semester aktif.');
    }

    public function jumlahTotalNilai(Collection $nilaiKomponen): ?float
    {
        return NilaiKelasDataService::jumlahTotalNilai($nilaiKomponen, $this->data['id_jenis_penilaian_kelas'], $this->data['jenis_penilaian']);
    }

    public function openRentangModal(): void
    {
        $this->pastikanBolehUbah();

        $this->rentangForm = collect($this->data['rentang_nilai'])->map(fn (RentangNilai $r) => [
            'nilai_huruf' => $r->nilai_huruf,
            'nilai_angka' => (float) $r->nilai_angka,
            'nilai_rendah' => (float) $r->nilai_rendah,
            'nilai_tinggi' => (float) $r->nilai_tinggi,
        ])->values()->all();
        $this->showRentangModal = true;
    }

    public function closeRentangModal(): void
    {
        $this->showRentangModal = false;
    }

    /**
     * Sama persis dengan NilaiController::kalkulasiNilaiAkhir — kalkulasi dengan rentang nilai
     * default jenjang, langsung disimpan ke tabel nilai (is_final tetap null/belum final).
     */
    public function kalkulasiDenganRentangDefault(): void
    {
        $this->pastikanBolehUbah();

        $kelas = $this->kelas;
        $jenjang = $kelas->prodi?->jenjang;
        abort_if(! $jenjang, 400, 'Jenjang tidak ditemukan untuk kelas ini.');

        $rentangNilaiList = RentangNilai::where('id_jenjang', $jenjang->id)->whereNull('deleted_at')->orderByDesc('nilai_tinggi')->get();
        abort_if($rentangNilaiList->isEmpty(), 400, 'Rentang nilai tidak ditemukan untuk jenjang '.$jenjang->nama);

        $result = $this->kalkulasiDanSimpan($kelas, $rentangNilaiList->map(fn (RentangNilai $r) => [
            'nilai_huruf' => $r->nilai_huruf,
            'nilai_angka' => (float) $r->nilai_angka,
            'nilai_rendah' => (float) $r->nilai_rendah,
            'nilai_tinggi' => (float) $r->nilai_tinggi,
        ])->all());

        unset($this->data);

        $message = "Kalkulasi nilai akhir berhasil. Berhasil: {$result['success_count']} mahasiswa";
        if ($result['error_count'] > 0) {
            $message .= ", gagal: {$result['error_count']} mahasiswa";
        }
        session()->flash('status', $message);
    }

    /**
     * Sama persis dengan NilaiController::kalkulasiPreview — kalkulasi dengan rentang nilai custom
     * dari form modal, langsung disimpan ke tabel nilai.
     */
    public function terapkanRentangCustom(): void
    {
        $this->pastikanBolehUbah();

        if ($this->rentangForm === []) {
            $this->addError('rentangForm', 'Rentang nilai kosong. Isi dari rentang default terlebih dahulu.');

            return;
        }

        $kelas = $this->kelas;
        $result = $this->kalkulasiDanSimpan($kelas, $this->rentangForm);

        unset($this->data);
        $this->showRentangModal = false;

        $message = "Kalkulasi dengan rentang custom berhasil disimpan. Berhasil: {$result['success_count']} mahasiswa";
        if ($result['error_count'] > 0) {
            $message .= ", gagal: {$result['error_count']} mahasiswa";
        }
        session()->flash('status', $message);
    }

    /**
     * @param  array<int, array{nilai_huruf: string, nilai_angka: float, nilai_rendah: float, nilai_tinggi: float}>  $rentangNilaiList
     * @return array{success_count: int, error_count: int}
     */
    private function kalkulasiDanSimpan(Kelas $kelas, array $rentangNilaiList): array
    {
        $rentangSorted = collect($rentangNilaiList)->sortByDesc('nilai_tinggi')->values();

        $jenisPenilaianList = JenisPenilaian::whereNull('deleted_at')->get()->keyBy('id');

        $bobotPenilaianMap = $kelas->id_kurikulum_matkul
            ? BobotPenilaian::where('id_kurikulum_matkul', $kelas->id_kurikulum_matkul)->whereNull('deleted_at')->get()->keyBy('id_jenis_penilaian')
            : collect();

        $krsList = Krs::where('id_kelas', $kelas->id)->whereNull('deleted_at')->get();
        abort_if($krsList->isEmpty(), 400, 'Tidak ada mahasiswa yang mengambil kelas ini.');

        $sks = $kelas->kurikulumMatkul?->sksLabel() ?? 0;

        $krsIds = $krsList->pluck('id')->all();
        $nilaiKomponenList = DB::table('nilai_komponen')->whereIn('id_krs', $krsIds)->whereNull('deleted_at')->get()->groupBy('id_krs');

        $successCount = 0;
        $errorCount = 0;

        DB::transaction(function () use ($krsList, $nilaiKomponenList, $jenisPenilaianList, $bobotPenilaianMap, $rentangSorted, $sks, &$successCount, &$errorCount) {
            foreach ($krsList as $krs) {
                $nilaiKomponenKrs = $nilaiKomponenList->get($krs->id, collect());
                if ($nilaiKomponenKrs->isEmpty()) {
                    $errorCount++;

                    continue;
                }

                $totalNilai = 0.0;
                $totalBobot = 0.0;
                foreach ($nilaiKomponenKrs as $nk) {
                    $jp = $jenisPenilaianList->get($nk->id_jenis_penilaian);
                    if (! $jp) {
                        continue;
                    }
                    $bobotPenilaian = $bobotPenilaianMap->get($nk->id_jenis_penilaian);
                    $bobot = $bobotPenilaian !== null ? (float) $bobotPenilaian->bobot : (float) $jp->bobot;
                    $totalNilai += (float) $nk->nilai * $bobot;
                    $totalBobot += $bobot;
                }

                $allFilled = $jenisPenilaianList->every(fn (JenisPenilaian $jp) => $nilaiKomponenKrs->contains('id_jenis_penilaian', $jp->id));
                if (! $allFilled || $totalBobot <= 0) {
                    $errorCount++;

                    continue;
                }

                $nilaiAkhir = $totalNilai / $totalBobot;
                $rentangNilai = $rentangSorted->first(fn (array $rn) => $nilaiAkhir >= $rn['nilai_rendah'] && $nilaiAkhir <= $rn['nilai_tinggi']);

                if (! $rentangNilai) {
                    $errorCount++;

                    continue;
                }

                Nilai::updateOrCreate(
                    ['id_krs' => $krs->id],
                    ['sks' => $sks, 'angka_mutu' => $rentangNilai['nilai_angka'], 'huruf_mutu' => $rentangNilai['nilai_huruf'], 'is_final' => null]
                );

                $successCount++;
            }
        });

        return ['success_count' => $successCount, 'error_count' => $errorCount];
    }

    /**
     * Sama persis dengan NilaiController::finalizeNilai.
     */
    public function finalisasi(): void
    {
        $this->pastikanBolehUbah();

        $krsIds = Krs::where('id_kelas', $this->kelasId)->whereNull('deleted_at')->pluck('id')->all();
        abort_if($krsIds === [], 400, 'Tidak ada mahasiswa di kelas ini.');

        $updated = Nilai::whereIn('id_krs', $krsIds)->whereNull('deleted_at')->update(['is_final' => true]);

        $kelas = $this->kelas;
        $namaMatkul = $kelas->kurikulumMatkul?->namaMatkulLabel() ?? 'kelas ini';
        $idMahasiswaTerdampak = Krs::whereIn('id', $krsIds)->pluck('id_mahasiswa')->unique();
        $idUserPerMahasiswa = Mahasiswa::whereIn('id', $idMahasiswaTerdampak)->whereNotNull('id_user')->pluck('id_user');
        foreach ($idUserPerMahasiswa as $idUser) {
            Notifikasi::kirim(
                idUser: $idUser,
                tipe: 'nilai_final',
                judul: 'Nilai sudah keluar',
                pesan: "Nilai {$namaMatkul} sudah difinalisasi dan bisa dilihat.",
                url: '/mahasiswa/nilai',
            );
        }

        unset($this->data);
        session()->flash('status', "Nilai berhasil difinalisasi ({$updated} mahasiswa). Nilai akan tampil di akun mahasiswa.");
    }

    public function openEditModal(int $idKrs): void
    {
        $this->pastikanBolehUbah();

        $this->resetValidation();

        $mhs = collect($this->data['mahasiswa'])->firstWhere('id_krs', $idKrs);
        abort_unless($mhs, 404, 'Data mahasiswa tidak ditemukan.');

        $this->editKrsId = $idKrs;
        $huruf = $mhs['nilai']?->huruf_mutu ?? '';
        $this->editHurufMutu = $huruf;
        $this->editAngkaMutu = $this->angkaMutuFromHuruf($huruf) ?? ($mhs['nilai']?->angka_mutu !== null ? (string) $mhs['nilai']->angka_mutu : '');
        $this->editKeterangan = '';
        $this->editRevisiChecked = false;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editKrsId = null;
        $this->editHurufMutu = '';
        $this->editAngkaMutu = '';
        $this->editKeterangan = '';
        $this->editRevisiChecked = false;
    }

    public function updatedEditHurufMutu(): void
    {
        $angka = $this->angkaMutuFromHuruf($this->editHurufMutu);
        if ($angka !== null) {
            $this->editAngkaMutu = $angka;
        }
    }

    private function angkaMutuFromHuruf(string $huruf): ?string
    {
        if (empty($this->data['rentang_nilai']) || trim($huruf) === '') {
            return null;
        }
        $rentang = collect($this->data['rentang_nilai'])->first(fn (RentangNilai $r) => strtoupper($r->nilai_huruf) === strtoupper(trim($huruf)));

        return $rentang ? (string) $rentang->nilai_angka : null;
    }

    /**
     * Sama persis dengan NilaiController::storeRevisiNilai / updateNilaiByKrs — dipilih via
     * $editRevisiChecked, sama seperti toggle "Revisi" di modal frontend.
     */
    public function saveEditNilai(): void
    {
        $this->pastikanBolehUbah();

        $this->validate([
            'editHurufMutu' => ['required', 'string', 'max:10'],
            'editAngkaMutu' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'editKeterangan' => ['nullable', 'string', 'max:500'],
        ]);

        // editKrsId adalah properti publik (bisa "disentuh" langsung lewat request Livewire yang
        // dimanipulasi, bukan cuma lewat openEditModal) — scope ulang ke kelas ini dan cek akses
        // dosen di sini juga, jangan andalkan validasi yang sudah lewat saat modal dibuka.
        $krs = Krs::where('id', $this->editKrsId)->where('id_kelas', $this->kelasId)->first();
        abort_unless($krs, 404, 'KRS tidak ditemukan.');

        $kelas = $this->kelas;
        abort_unless($this->dosenHasAccess($kelas), 403, 'Anda tidak memiliki akses ke kelas ini.');
        $sks = $kelas->kurikulumMatkul?->sksLabel() ?? 0;
        $angkaMutu = $this->editAngkaMutu !== '' ? (float) $this->editAngkaMutu : null;

        if ($this->editRevisiChecked) {
            DB::transaction(function () use ($krs, $sks, $angkaMutu) {
                NilaiRevisi::create([
                    'id_krs' => $krs->id,
                    'angka_mutu' => $angkaMutu,
                    'huruf_mutu' => $this->editHurufMutu,
                    'keterangan' => $this->editKeterangan !== '' ? $this->editKeterangan : null,
                    'created_by' => Auth::user()->name ?? (string) Auth::id(),
                ]);

                $revisiCount = NilaiRevisi::where('id_krs', $krs->id)->whereNull('deleted_at')->count();
                $nilai = Nilai::where('id_krs', $krs->id)->whereNull('deleted_at')->first();
                $angkaMutuFinal = $angkaMutu ?? $nilai?->angka_mutu;

                Nilai::updateOrCreate(
                    ['id_krs' => $krs->id],
                    ['sks' => $sks ?: null, 'angka_mutu' => $angkaMutuFinal, 'huruf_mutu' => $this->editHurufMutu, 'revisi' => $revisiCount]
                );
            });

            session()->flash('status', 'Revisi nilai berhasil disimpan.');
        } else {
            $nilai = Nilai::where('id_krs', $krs->id)->whereNull('deleted_at')->first();
            $angkaMutuFinal = $angkaMutu ?? $nilai?->angka_mutu;

            if ($nilai) {
                $nilai->update(['huruf_mutu' => $this->editHurufMutu, 'angka_mutu' => $angkaMutuFinal]);
            } else {
                Nilai::create([
                    'id_krs' => $krs->id,
                    'sks' => $sks ?: null,
                    'huruf_mutu' => $this->editHurufMutu,
                    'angka_mutu' => $angkaMutuFinal,
                    'is_final' => false,
                ]);
            }

            session()->flash('status', 'Nilai berhasil diperbarui.');
        }

        unset($this->data);
        $this->closeEditModal();
    }

    /**
     * Judul kolom rincian nilai — kolom komponen penilaiannya dinamis, mengikuti jenis penilaian
     * yang berlaku untuk kelas ini (beserta bobotnya), persis seperti tabel di layar.
     *
     * @return array<int, string>
     */
    private function exportHeaders(): array
    {
        $headers = ['No.', 'NIM', 'Nama'];

        foreach ($this->data['jenis_penilaian'] as $jp) {
            $headers[] = $jp['kode'].' ('.rtrim(rtrim(number_format((float) $jp['bobot'], 2, '.', ''), '0'), '.').'%)';
        }

        return array_merge($headers, ['Jumlah Total Nilai', 'Nilai Akhir', 'Huruf Mutu', 'Status', 'Revisi']);
    }

    /**
     * Baris siap cetak untuk kedua format ekspor. Ekspor sengaja TIDAK dibatasi semester aktif —
     * mencetak rincian nilai semester lampau justru kebutuhan utamanya (lihat bolehUbah(), yang
     * hanya mengatur aksi yang mengubah data).
     *
     * @return array<int, array<int, string|int|float>>
     */
    private function exportRows(): array
    {
        $data = $this->data;
        $hasil = [];

        foreach ($data['mahasiswa'] as $idx => $mhs) {
            $baris = [$idx + 1, (string) $mhs['nim'], (string) $mhs['nama']];

            foreach ($data['jenis_penilaian'] as $jp) {
                $komponen = $mhs['nilai_komponen']->get($jp['id']);
                $baris[] = $komponen
                    ? $komponen->nilai.($jp['status'] === 'otomatis' ? '%' : '')
                    : '-';
            }

            $jumlahTotal = $this->jumlahTotalNilai($mhs['nilai_komponen']);
            $nilai = $mhs['nilai'];

            // Dicast ke float supaya masuk Excel sebagai angka, bukan teks (NIM sengaja tetap
            // string agar nol di depannya tidak hilang).
            $baris[] = $jumlahTotal !== null ? (float) $jumlahTotal : '-';
            $baris[] = $nilai?->angka_mutu !== null ? (float) $nilai->angka_mutu : '-';
            $baris[] = $nilai?->huruf_mutu ?? '-';
            $baris[] = $nilai ? ($nilai->is_final ? 'Final' : 'Belum Final') : '-';
            $baris[] = (int) ($nilai?->revisi ?? 0);

            $hasil[] = $baris;
        }

        return $hasil;
    }

    /**
     * Baris identitas di atas tabel, dipakai kedua format.
     *
     * @return array<int, string>
     */
    private function exportInfo(): array
    {
        $kelas = $this->kelas;
        $km = $kelas->kurikulumMatkul;
        $dosen = Dosen::find($this->dosenId);

        $namaDosen = $dosen ? trim(
            ($dosen->gelar_depan ? $dosen->gelar_depan.' ' : '').
            ($dosen->nama ?? '').
            ($dosen->gelar_belakang ? ', '.$dosen->gelar_belakang : '')
        ) : '';

        return [
            'Mata kuliah: '.($km?->kodeMatkulLabel() ?? '-').' - '.($km?->namaMatkulLabel() ?? '-'),
            'Kelas: '.($kelas->kode ?: '-').' | SKS: '.($km?->sksLabel() ?? 0).' | Prodi: '.($kelas->prodi?->nama ?? '-'),
            'Semester: '.($kelas->semester
                ? trim($kelas->semester->nama.($kelas->semester->kode ? ' ('.$kelas->semester->kode.')' : ''))
                : '-'),
            'Dosen: '.($namaDosen !== '' ? $namaDosen : '-'),
            'Diekspor: '.now()->format('Y-m-d H:i:s'),
        ];
    }

    private function namaBerkas(string $ekstensi): string
    {
        $km = $this->kelas->kurikulumMatkul;
        $bagian = array_filter([
            'Rincian_Nilai',
            $km?->kodeMatkulLabel(),
            $this->kelas->kode ?: null,
            $this->kelas->semester?->kode,
            now()->format('Y-m-d'),
        ]);

        return preg_replace('/\s+/', '_', implode('_', $bagian)).'.'.$ekstensi;
    }

    public function exportExcel(): StreamedResponse
    {
        $headers = $this->exportHeaders();
        $rows = $this->exportRows();
        $kolomTerakhir = Coordinate::stringFromColumnIndex(count($headers));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rincian Nilai');

        $sheet->setCellValue('A1', 'Rincian nilai kelas');
        $sheet->mergeCells('A1:'.$kolomTerakhir.'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $barisInfo = 2;
        foreach ($this->exportInfo() as $info) {
            $sheet->setCellValue('A'.$barisInfo, $info);
            $sheet->mergeCells('A'.$barisInfo.':'.$kolomTerakhir.$barisInfo);
            $barisInfo++;
        }

        $headerRow = $barisInfo + 1;
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
            $sheet->setCellValue('A'.($headerRow + 1), 'Tidak ada mahasiswa di kelas ini.');
            $sheet->mergeCells('A'.($headerRow + 1).':'.$kolomTerakhir.($headerRow + 1));
        }

        foreach (range(1, count($headers)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
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
        $headers = $this->exportHeaders();
        $rows = $this->exportRows();

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; }
        .title { text-align: center; font-size: 13pt; font-weight: bold; margin-bottom: 6px; }
        .info { text-align: center; margin-bottom: 2px; font-size: 8.5pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { background-color: #4472C4; color: white; padding: 4px; border: 1px solid #000; text-align: center; font-size: 8pt; }
        td { padding: 4px; border: 1px solid #000; }
        td.num { text-align: center; }
        .footer { margin-top: 16px; text-align: right; font-size: 8pt; }
        </style></head><body>';

        $html .= '<div class="title">RINCIAN NILAI KELAS</div>';
        foreach ($this->exportInfo() as $info) {
            $html .= '<div class="info">'.htmlspecialchars($info).'</div>';
        }

        if ($rows === []) {
            $html .= '<p>Tidak ada mahasiswa di kelas ini.</p>';
        } else {
            $html .= '<table><thead><tr>';
            foreach ($headers as $header) {
                $html .= '<th>'.htmlspecialchars($header).'</th>';
            }
            $html .= '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $i => $sel) {
                    // Hanya NIM dan Nama yang rata kiri; kolom nilai lebih mudah dibaca rata tengah.
                    $kelasSel = in_array($i, [1, 2], true) ? '' : ' class="num"';
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
        // Landscape: jumlah kolomnya ikut jenis penilaian, gampang lebih dari sepuluh.
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $this->namaBerkas('pdf'), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function render()
    {
        return view('livewire.dosen.nilai.rekap')->extends('layouts.dosen');
    }
}
