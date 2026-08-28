<?php

namespace App\Livewire\Mahasiswa\KeringananBiaya;

use App\Models\JenisKeringananBiaya;
use App\Models\KeringananBiaya;
use App\Models\Mahasiswa;
use App\Models\Semester;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Index extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $mahasiswaId;

    public bool $showFormModal = false;

    public string $idJenis = '';

    public string $idSemester = '';

    public string $keterangan = '';

    /** @var TemporaryUploadedFile|null */
    public $fileLampiran = null;

    public function mount(): void
    {
        $mahasiswa = Mahasiswa::where('id_user', Auth::id())->firstOrFail();
        $this->mahasiswaId = $mahasiswa->id;
    }

    /**
     * Sama persis dengan KeringananBiayaController::indexSaya.
     */
    #[Computed]
    public function list()
    {
        return KeringananBiaya::where('id_mahasiswa', $this->mahasiswaId)
            ->with(['jenisKeringananBiaya:id,nama,is_persentase,nominal', 'semester:id,kode,nama'])
            ->orderByDesc('tanggal_pengajuan')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Sama persis dengan JenisKeringananBiayaController::indexAktifForMahasiswa.
     *
     * Dulu disaring `nominal = 0` karena submit menyalin nominal master apa adanya, sehingga
     * jenis persentase akan tersimpan sebagai rupiah (10% -> Rp 10). Sekarang persentase
     * diselesaikan saat approve oleh KeringananBiayaPersentaseService, jadi seluruh jenis aktif
     * boleh diajukan.
     */
    #[Computed]
    public function jenisOptions()
    {
        return JenisKeringananBiaya::where('is_active', true)
            ->orderBy('nama')
            ->get(['id', 'nama', 'is_persentase', 'nominal', 'keterangan']);
    }

    #[Computed]
    public function selectedJenis(): ?JenisKeringananBiaya
    {
        if ($this->idJenis === '') {
            return null;
        }

        return $this->jenisOptions->firstWhere('id', (int) $this->idJenis);
    }

    #[Computed]
    public function semesterOptionsRaw()
    {
        return Semester::orderByDesc('kode')->get(['id', 'kode', 'nama']);
    }

    public function openFormModal(): void
    {
        $this->idJenis = '';
        $this->idSemester = '';
        $this->keterangan = '';
        $this->fileLampiran = null;
        $this->resetValidation();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetValidation();
    }

    /**
     * Sama persis dengan KeringananBiayaController::storeSaya.
     */
    public function submit(): void
    {
        if ($this->idJenis === '' || $this->idSemester === '' || $this->selectedJenis === null) {
            $this->addError('idJenis', 'Pilih jenis keringanan dan semester.');

            return;
        }

        // Jenis persentase disimpan dengan nominal 0 dan snapshot persennya; rupiahnya baru
        // dihitung saat admin menyetujui. Jenis rupiah tetap memakai nominal master.
        $persentase = $this->selectedJenis->is_persentase ? (float) $this->selectedJenis->nominal : null;
        $nominal = $persentase === null ? (float) $this->selectedJenis->nominal : 0.0;

        $duplicate = KeringananBiaya::where('id_jenis_keringanan_biaya', (int) $this->idJenis)
            ->where('id_mahasiswa', $this->mahasiswaId)
            ->where('id_semester', (int) $this->idSemester)
            ->exists();

        if ($duplicate) {
            $this->addError('idSemester', 'Sudah ada pengajuan keringanan untuk jenis dan semester ini.');

            return;
        }

        $this->validate([
            'fileLampiran' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $path = $this->fileLampiran ? $this->fileLampiran->store('keringanan-biaya', 'public') : null;

        $user = Auth::user();

        KeringananBiaya::create([
            'id_jenis_keringanan_biaya' => (int) $this->idJenis,
            'id_mahasiswa' => $this->mahasiswaId,
            'id_semester' => (int) $this->idSemester,
            'nominal' => $nominal,
            'persentase' => $persentase,
            'keterangan' => trim($this->keterangan) !== '' ? $this->keterangan : null,
            'file_lampiran' => $path,
            'status' => 'pending',
            'tanggal_pengajuan' => now(),
            'tanggal_approved' => null,
            'approved_by' => null,
            'created_by' => $user->name ?? (string) $user->id,
        ]);

        $this->showFormModal = false;
        $this->resetValidation();
        unset($this->list);
        session()->flash('status', 'Pengajuan keringanan biaya berhasil dikirim.');
    }

    public function render()
    {
        return view('livewire.mahasiswa.keringanan-biaya.index')->extends('layouts.mahasiswa');
    }
}
