<?php

namespace App\Livewire\Dosen\Jadwal;

use App\Models\Dosen;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\Semester;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Index extends Component
{
    #[Locked]
    public int $dosenId;

    public string $filterSemester = '';

    private const HARI_ORDER = [
        'senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, 'sabtu' => 6, 'minggu' => 7,
    ];

    public function mount(): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $activeSemester = Semester::where('is_active', true)->whereNull('deleted_at')->first();
        $this->filterSemester = $activeSemester ? (string) $activeSemester->id : '';
    }

    #[Computed]
    public function semesterOptions(): array
    {
        return Semester::whereNull('deleted_at')
            ->orderByDesc('kode')
            ->get(['id', 'nama', 'kode'])
            ->mapWithKeys(fn (Semester $s) => [$s->id => $s->kode ? "{$s->nama} ({$s->kode})" : $s->nama])
            ->all();
    }

    /**
     * Sama persis dengan JadwalDosenController::getMyJadwal (jadwal_dosen status active, join
     * jadwal.kelas untuk filter semester), ditampilkan sebagai tabel datar satu baris per slot
     * jadwal — bukan diekspansi ke kalender bulanan seperti versi sebelumnya.
     *
     * Urutan diambil dari logika sort yang sama dengan controller (hari lalu jam_mulai), tapi
     * ditulis sebagai sort dua-kunci (bukan `$hariNum * 10000 + $jamMulai` seperti di controller)
     * karena formula angka tunggal itu salah: jam_mulai bisa sampai "23:59:00" (235900), jauh lebih
     * besar dari kelipatan hari (10000), jadi Senin sore bisa ikut terurut setelah Selasa pagi. Baris
     * di tabel ini perlu urut hari yang benar supaya tidak membingungkan pengguna.
     */
    #[Computed]
    public function jadwalRows()
    {
        $query = JadwalDosen::with([
            'jadwal.kelas.kurikulumMatkul.matkul',
            'jadwal.kelas.prodi.jenjang',
            'jadwal.kelas.kelompokKelas',
            'jadwal.kelas.semester',
            'jadwal.ruangan',
            'jadwal.jenisKuliah',
        ])
            ->where('id_dosen', $this->dosenId)
            ->where('status', 'active');

        if ($this->filterSemester !== '') {
            $semesterId = (int) $this->filterSemester;
            $query->whereHas('jadwal.kelas', fn ($q) => $q->where('id_semester', $semesterId));
        }

        return $query->get()
            ->filter(fn (JadwalDosen $jd) => $jd->jadwal !== null && $jd->jadwal->kelas !== null)
            ->sortBy(function (JadwalDosen $jd) {
                $hariNum = self::HARI_ORDER[strtolower((string) $jd->jadwal->hari)] ?? 8;

                return [$hariNum, $jd->jadwal->jam_mulai ?? '00:00:00'];
            })
            ->values();
    }

    /**
     * jadwalRows() dikelompokkan per kelas kuliah untuk tampilan accordion — setiap grup berisi
     * kelas beserta seluruh slot jadwalnya (masih urut hari lalu jam dari jadwalRows()).
     * groupBy() mempertahankan urutan kemunculan pertama tiap kelas, jadi kelas dengan slot
     * paling awal (hari/jam) otomatis muncul lebih dulu.
     *
     * @return Collection<int, array{kelas: Kelas, rows: Collection<int, JadwalDosen>}>
     */
    #[Computed]
    public function kelasGroups()
    {
        return $this->jadwalRows()
            ->groupBy(fn (JadwalDosen $jd) => $jd->jadwal->kelas->id)
            ->map(fn ($rows) => [
                'kelas' => $rows->first()->jadwal->kelas,
                'rows' => $rows->values(),
            ])
            ->values();
    }

    public function render()
    {
        return view('livewire.dosen.jadwal.index')->extends('layouts.dosen');
    }
}
