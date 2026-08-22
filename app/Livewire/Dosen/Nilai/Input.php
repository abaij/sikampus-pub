<?php

namespace App\Livewire\Dosen\Nilai;

use App\Models\Dosen;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Semester;
use App\Services\NilaiKelasDataService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Input extends Component
{
    #[Locked]
    public int $kelasId;

    #[Locked]
    public int $dosenId;

    public string $selectedJenisPenilaianId = '';

    /** @var array<int, string> keyed by id_krs */
    public array $nilaiInputs = [];

    public function mount(int $kelasId): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $kelas = Kelas::find($kelasId);
        abort_unless($kelas, 404, 'Kelas tidak ditemukan.');
        abort_unless($this->dosenHasAccess($kelas), 403, 'Anda tidak memiliki akses ke kelas ini.');

        // Input nilai hanya untuk semester yang sedang berjalan. Tombolnya memang sudah
        // disembunyikan di daftar kelas, tapi penguncian sebenarnya harus di sini — tanpa ini
        // nilai semester lampau masih bisa diubah dengan mengetik URL-nya langsung.
        $semesterAktif = Semester::where('is_active', true)->whereNull('deleted_at')->first();
        abort_unless(
            $semesterAktif && (int) $kelas->id_semester === (int) $semesterAktif->id,
            403,
            'Input nilai hanya tersedia untuk kelas pada semester aktif.'
        );

        $this->kelasId = $kelasId;

        $jenisPenilaianPertama = $this->data['jenis_penilaian'][0]['id'] ?? null;
        if ($jenisPenilaianPertama !== null) {
            $this->selectedJenisPenilaianId = (string) $jenisPenilaianPertama;
            $this->fillNilaiInputs();
        }
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
        return Kelas::with(['kurikulumMatkul.matkul', 'prodi'])->findOrFail($this->kelasId);
    }

    #[Computed]
    public function data(): array
    {
        return NilaiKelasDataService::build($this->kelas);
    }

    public function updatedSelectedJenisPenilaianId(): void
    {
        $this->fillNilaiInputs();
    }

    private function fillNilaiInputs(): void
    {
        $inputs = [];
        foreach ($this->data['mahasiswa'] as $mhs) {
            $komponen = $this->selectedJenisPenilaianId !== ''
                ? $mhs['nilai_komponen']->get((int) $this->selectedJenisPenilaianId)
                : null;
            $inputs[$mhs['id_krs']] = $komponen ? (string) $komponen->nilai : '';
        }
        $this->nilaiInputs = $inputs;
    }

    /**
     * Sama persis dengan NilaiController::storeNilaiKomponen, dipanggil per baris mahasiswa yang
     * nilainya diisi (bukan kosong).
     */
    public function save(): void
    {
        if ($this->selectedJenisPenilaianId === '') {
            $this->addError('selectedJenisPenilaianId', 'Pilih jenis penilaian terlebih dahulu.');

            return;
        }

        $idJenisPenilaian = (int) $this->selectedJenisPenilaianId;

        // nilaiInputs adalah properti publik keyed by id_krs — batasi ke id_krs yang benar-benar
        // ada di kelas ini (dari $this->data), supaya request yang dimanipulasi tidak bisa
        // menyisipkan id_krs dari kelas lain yang tidak diampu dosen ini.
        $validKrsIds = collect($this->data['mahasiswa'])->pluck('id_krs')->flip();

        foreach ($this->nilaiInputs as $idKrs => $nilaiRaw) {
            if (! $validKrsIds->has((int) $idKrs)) {
                continue;
            }
            if ($nilaiRaw === '' || $nilaiRaw === null) {
                continue;
            }
            $nilai = (float) $nilaiRaw;
            if ($nilai < 0 || $nilai > 100) {
                continue;
            }

            $existing = DB::table('nilai_komponen')
                ->where('id_krs', $idKrs)
                ->where('id_jenis_penilaian', $idJenisPenilaian)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                DB::table('nilai_komponen')
                    ->where('id', $existing->id)
                    ->update(['nilai' => $nilai, 'id_dosen' => $this->dosenId, 'updated_at' => now()]);
            } else {
                DB::table('nilai_komponen')->insert([
                    'id_krs' => $idKrs,
                    'id_jenis_penilaian' => $idJenisPenilaian,
                    'nilai' => $nilai,
                    'id_dosen' => $this->dosenId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        unset($this->data);
        $this->fillNilaiInputs();

        session()->flash('status', 'Nilai berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.dosen.nilai.input')->extends('layouts.dosen');
    }
}
