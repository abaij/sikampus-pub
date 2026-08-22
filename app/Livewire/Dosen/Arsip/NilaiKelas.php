<?php

namespace App\Livewire\Dosen\Arsip;

use App\Models\Dosen;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Nilai;
use App\Models\NilaiRevisi;
use App\Models\RentangNilai;
use App\Services\NilaiKelasDataService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NilaiKelas extends Component
{
    // Locked: saveRevisi() memakai kelasId/dosenId langsung untuk cek akses dosen ke kelas —
    // properti publik biasa bisa di-override client lewat request Livewire.
    #[Locked]
    public int $kelasId;

    #[Locked]
    public int $dosenId;

    public bool $showRevisiModal = false;

    public ?int $revisiKrsId = null;

    public string $revisiHurufMutu = '';

    public string $revisiAngkaMutu = '';

    public string $revisiKeterangan = '';

    public function mount(int $id): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $kelas = Kelas::find($id);
        abort_unless($kelas, 404, 'Kelas tidak ditemukan.');
        abort_unless($this->dosenHasAccess($kelas), 403, 'Anda tidak memiliki akses ke kelas ini.');

        $this->kelasId = $id;
    }

    /**
     * Sama persis dengan NilaiController::getMahasiswaByKelas / storeRevisiNilai (cek akses).
     */
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

    public function jumlahTotalNilai($nilaiKomponen): ?float
    {
        return NilaiKelasDataService::jumlahTotalNilai($nilaiKomponen, $this->data['id_jenis_penilaian_kelas'], $this->data['jenis_penilaian']);
    }

    public function openRevisiModal(int $idKrs): void
    {
        $this->resetValidation();

        $mhs = collect($this->data['mahasiswa'])->firstWhere('id_krs', $idKrs);
        abort_unless($mhs, 404, 'Data mahasiswa tidak ditemukan.');

        $this->revisiKrsId = $idKrs;
        $huruf = $mhs['nilai']?->huruf_mutu ?? '';
        $this->revisiHurufMutu = $huruf;
        $this->revisiAngkaMutu = $this->angkaMutuFromHuruf($huruf) ?? ($mhs['nilai']?->angka_mutu !== null ? (string) $mhs['nilai']->angka_mutu : '');
        $this->revisiKeterangan = '';
        $this->showRevisiModal = true;
    }

    public function closeRevisiModal(): void
    {
        $this->showRevisiModal = false;
        $this->revisiKrsId = null;
        $this->revisiHurufMutu = '';
        $this->revisiAngkaMutu = '';
        $this->revisiKeterangan = '';
    }

    public function updatedRevisiHurufMutu(): void
    {
        $angka = $this->angkaMutuFromHuruf($this->revisiHurufMutu);
        if ($angka !== null) {
            $this->revisiAngkaMutu = $angka;
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
     * Sama persis dengan NilaiController::storeRevisiNilai.
     */
    public function saveRevisi(): void
    {
        $this->validate([
            'revisiHurufMutu' => ['required', 'string', 'max:10'],
            'revisiAngkaMutu' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'revisiKeterangan' => ['nullable', 'string', 'max:500'],
        ]);

        // revisiKrsId adalah properti publik (bisa "disentuh" langsung lewat request Livewire yang
        // dimanipulasi, bukan cuma lewat openRevisiModal) — scope ulang ke kelas ini dan cek akses
        // dosen di sini juga, jangan andalkan validasi yang sudah lewat saat modal dibuka.
        $krs = Krs::where('id', $this->revisiKrsId)->where('id_kelas', $this->kelasId)->first();
        abort_unless($krs, 404, 'KRS tidak ditemukan.');

        $kelas = $this->kelas;
        abort_unless($this->dosenHasAccess($kelas), 403, 'Anda tidak memiliki akses ke kelas ini.');
        $sks = $kelas->kurikulumMatkul?->sksLabel() ?? 0;
        $angkaMutu = $this->revisiAngkaMutu !== '' ? (float) $this->revisiAngkaMutu : null;

        DB::transaction(function () use ($krs, $sks, $angkaMutu) {
            NilaiRevisi::create([
                'id_krs' => $krs->id,
                'angka_mutu' => $angkaMutu,
                'huruf_mutu' => $this->revisiHurufMutu,
                'keterangan' => $this->revisiKeterangan !== '' ? $this->revisiKeterangan : null,
                'created_by' => Auth::user()->name ?? (string) Auth::id(),
            ]);

            $revisiCount = NilaiRevisi::where('id_krs', $krs->id)->whereNull('deleted_at')->count();
            $nilai = Nilai::where('id_krs', $krs->id)->whereNull('deleted_at')->first();
            $angkaMutuFinal = $angkaMutu ?? $nilai?->angka_mutu;

            Nilai::updateOrCreate(
                ['id_krs' => $krs->id],
                ['sks' => $sks ?: null, 'angka_mutu' => $angkaMutuFinal, 'huruf_mutu' => $this->revisiHurufMutu, 'revisi' => $revisiCount]
            );
        });

        unset($this->data);
        $this->closeRevisiModal();
        session()->flash('status', 'Revisi nilai berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.dosen.arsip.nilai-kelas')->extends('layouts.dosen');
    }
}
