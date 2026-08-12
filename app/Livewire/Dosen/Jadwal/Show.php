<?php

namespace App\Livewire\Dosen\Jadwal;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Perkuliahan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public int $kelasId;

    #[Locked]
    public int $dosenId;

    public bool $isPic = false;

    #[Url(as: 'id_semester')]
    public string $idSemester = '';

    public function mount(int $kelasId): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $kelasDosen = KelasDosen::where('id_dosen', $dosen->id)
            ->where('id_kelas', $kelasId)
            ->whereNull('deleted_at')
            ->first();

        abort_unless($kelasDosen, 403, 'Anda tidak mengampu kelas ini.');

        $this->kelasId = $kelasId;
        $this->isPic = (bool) $kelasDosen->is_pic;
    }

    /**
     * Sama persis dengan JadwalDosenController::getRincianJadwalKelasAmpu.
     */
    #[Computed]
    public function kelas(): Kelas
    {
        $kelas = Kelas::with([
            'kurikulumMatkul.matkul',
            'prodi.jenjang',
            'semester',
            'kelompokKelas',
            'jadwal' => fn ($q) => $q->whereNull('deleted_at')
                ->with(['ruangan', 'jenisKuliah', 'dosen.dosen'])
                ->orderBy('hari')
                ->orderBy('jam_mulai'),
        ])
            ->whereNull('deleted_at')
            ->findOrFail($this->kelasId);

        abort_if(
            $this->idSemester !== '' && (int) $kelas->id_semester !== (int) $this->idSemester,
            422,
            'Kelas tidak termasuk semester yang dipilih.'
        );

        return $kelas;
    }

    #[Computed]
    public function jumlahMahasiswa(): int
    {
        return Krs::where('id_kelas', $this->kelasId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Sama persis dengan formatJadwalSlotsWithSesi — tiap slot ditandai status sesi perkuliahan
     * (belum_mulai/sedang_berlangsung/selesai) berdasarkan baris `perkuliahan` terkait.
     *
     * sesi_status/sesi_status_label ditampilkan sebagai kolom "Status sesi" di tabel Slot Jadwal
     * Pertemuan (show.blade.php). Sempat dihapus lalu dikembalikan lagi atas permintaan pengguna —
     * kolom STATUS pada PDF Jurnal Perkuliahan (JurnalPerkuliahanCetakController) sengaja tetap
     * tidak ada, jadi jangan disamakan kembali.
     *
     * @return array<int, array{jadwal: Jadwal, sesi_status: string, sesi_status_label: string}>
     */
    #[Computed]
    public function jadwalRows(): array
    {
        $kelas = $this->kelas;
        $jadwalIds = $kelas->jadwal->pluck('id')->all();

        $perkuliahanByJadwal = $jadwalIds === []
            ? collect()
            : Perkuliahan::whereIn('id_jadwal', $jadwalIds)->whereNull('deleted_at')->get()->groupBy('id_jadwal');

        return $kelas->jadwal->map(function ($jadwal) use ($perkuliahanByJadwal) {
            $candidates = $perkuliahanByJadwal->get($jadwal->id, collect());

            $ongoing = $candidates
                ->filter(fn (Perkuliahan $p) => $p->waktu_mulai && ! $p->waktu_selesai)
                ->sortByDesc(fn (Perkuliahan $p) => $p->waktu_mulai?->getTimestamp() ?? 0)
                ->first();

            $latest = $ongoing ?? $candidates->sortByDesc(fn (Perkuliahan $p) => $p->waktu_mulai?->getTimestamp() ?? 0)->first();

            [$status, $label] = match (true) {
                $latest === null || ! $latest->waktu_mulai => ['belum_mulai', 'Belum dimulai'],
                ! $latest->waktu_selesai => ['sedang_berlangsung', 'Sedang berlangsung'],
                default => ['selesai', 'Selesai'],
            };

            return [
                'jadwal' => $jadwal,
                'sesi_status' => $status,
                'sesi_status_label' => $label,
            ];
        })->all();
    }

    public function render()
    {
        return view('livewire.dosen.jadwal.show')->extends('layouts.dosen');
    }
}
