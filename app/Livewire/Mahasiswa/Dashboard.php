<?php

namespace App\Livewire\Mahasiswa;

use App\Models\Krs;
use App\Models\Ktm;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\Pengumuman;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Dashboard extends Component
{
    #[Locked]
    public int $mahasiswaId;

    public ?int $selectedPengumumanId = null;

    public function mount(): void
    {
        $mahasiswa = Mahasiswa::where('id_user', Auth::id())->firstOrFail();
        $this->mahasiswaId = $mahasiswa->id;
    }

    /**
     * Sama persis dengan blok mahasiswa di AuthController::me.
     */
    #[Computed]
    public function mahasiswa(): Mahasiswa
    {
        return Mahasiswa::with([
            'prodi.jenjang',
            'prodi.fakultas',
            'semester_masuk',
            'grup_mahasiswa',
            'dosen_wali' => function ($q) {
                $q->where('status', 'active')->with('dosen');
            },
        ])->findOrFail($this->mahasiswaId);
    }

    #[Computed]
    public function dosenWaliAktif()
    {
        return $this->mahasiswa->dosen_wali->first();
    }

    /**
     * Sama persis dengan PengumumanController::getAktifForMahasiswa (per_page=5, tanpa filter prioritas).
     */
    #[Computed]
    public function pengumumanList()
    {
        $now = now();

        return Pengumuman::query()
            ->where(function ($q) {
                $q->where('audien', 'mahasiswa')
                    ->orWhereNull('audien');
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('tanggal_mulai')
                    ->orWhere('tanggal_mulai', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('tanggal_selesai')
                    ->orWhere('tanggal_selesai', '>=', $now);
            })
            ->orderBy('prioritas', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(5);
    }

    #[Computed]
    public function selectedPengumuman(): ?Pengumuman
    {
        if ($this->selectedPengumumanId === null) {
            return null;
        }

        return $this->pengumumanList->firstWhere('id', $this->selectedPengumumanId);
    }

    public function showPengumuman(int $id): void
    {
        $this->selectedPengumumanId = $id;
    }

    public function closePengumuman(): void
    {
        $this->selectedPengumumanId = null;
    }

    /**
     * Sama persis dengan NilaiController::getIpPerSemester.
     */
    #[Computed]
    public function ipPerSemester(): array
    {
        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.semester',
        ])
            ->where('id_mahasiswa', $this->mahasiswaId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->get();

        $krsIds = $krsList->pluck('id')->all();
        $nilaiMap = $krsIds === []
            ? collect()
            : Nilai::whereIn('id_krs', $krsIds)->whereNull('deleted_at')->get()->keyBy('id_krs');

        $ipBySemester = [];

        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = $nilaiMap->get($krs->id);

            if (! $matkul || ! $semester) {
                continue;
            }

            $sks = $matkul->sks ?? 0;
            $semesterId = $semester->id;

            if (! isset($ipBySemester[$semesterId])) {
                $ipBySemester[$semesterId] = [
                    'semester' => $semester,
                    'total_angka_mutu' => 0,
                    'total_sks_dengan_nilai' => 0,
                ];
            }

            if ($nilai && $nilai->is_final && $nilai->angka_mutu !== null && $sks > 0) {
                $ipBySemester[$semesterId]['total_angka_mutu'] += $nilai->angka_mutu * $sks;
                $ipBySemester[$semesterId]['total_sks_dengan_nilai'] += $sks;
            }
        }

        $result = [];
        foreach ($ipBySemester as $data) {
            $result[] = [
                'semester' => $data['semester'],
                'ip' => $data['total_sks_dengan_nilai'] > 0
                    ? round($data['total_angka_mutu'] / $data['total_sks_dengan_nilai'], 2)
                    : null,
            ];
        }

        usort($result, fn ($a, $b) => $a['semester']->id <=> $b['semester']->id);

        return $result;
    }

    /**
     * Ringkasan KTM mahasiswa (data sama dengan KtmController::myShow). Aksi buat/perbarui KTM
     * sengaja tidak diduplikasi di sini — sudah ada halaman khusus (mahasiswa.ktm, lihat
     * App\Livewire\Mahasiswa\Ktm) yang menjadi satu-satunya tempat untuk aksi tersebut; dashboard
     * hanya menampilkan ringkasan + tautan ke sana.
     */
    #[Computed]
    public function ktm(): ?Ktm
    {
        return Ktm::where('id_mahasiswa', $this->mahasiswaId)->whereNull('deleted_at')->first();
    }

    public function render()
    {
        return view('livewire.mahasiswa.dashboard')->extends('layouts.mahasiswa');
    }
}
