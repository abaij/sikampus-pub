<?php

namespace App\Livewire\Dosen\Kehadiran;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Perkuliahan;
use App\Models\Semester;
use App\Services\KehadiranRekapService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Index extends Component
{
    #[Locked]
    public int $dosenId;

    /**
     * Terikat ke query string `id_semester` supaya tautan "Kembali" dari halaman rincian bisa
     * mengembalikan pengguna ke semester yang sedang dilihatnya, bukan selalu ke semester aktif.
     */
    #[Url(as: 'id_semester')]
    public string $filterSemester = '';

    public bool $showRekapModal = false;

    public ?int $rekapKelasId = null;

    public function mount(): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $activeSemester = Semester::where('is_active', true)->whereNull('deleted_at')->first();
        // Query string menang: kalau halaman dibuka lewat tautan "Kembali" yang membawa
        // id_semester, Livewire sudah mengisi properti ini sebelum mount() jalan — jangan ditimpa
        // dengan default semester aktif.
        if ($this->filterSemester === '') {
            $this->filterSemester = $activeSemester ? (string) $activeSemester->id : '';
        }
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
     * Sama persis dengan PerkuliahanController::getMyPerkuliahan — kelas dimana dosen ini PIC atau
     * memiliki jadwal_dosen aktif, dikelompokkan dengan daftar pertemuan (pertemuan_ke dihitung
     * dari urutan waktu_mulai, bukan kolom tersimpan).
     *
     * @return array<int, array{kelas: Kelas, jumlah_mahasiswa: int, perkuliahan: array<int, array<string, mixed>>}>
     */
    #[Computed]
    public function rows(): array
    {
        $idSemester = $this->filterSemester !== '' ? (int) $this->filterSemester : null;

        $kelasAsPic = Kelas::where('id_dosen_pic', $this->dosenId)
            ->when($idSemester, fn ($q) => $q->where('id_semester', $idSemester))
            ->pluck('id')
            ->all();

        $jadwalDosenQuery = JadwalDosen::where('id_dosen', $this->dosenId)->where('status', 'active');
        if ($idSemester) {
            $jadwalDosenQuery->whereHas('jadwal.kelas', fn ($q) => $q->where('id_semester', $idSemester));
        }
        $kelasWithJadwal = $jadwalDosenQuery->with('jadwal:id,id_kelas')->get()
            ->pluck('jadwal.id_kelas')->filter()->unique()->values()->all();

        $kelasIds = array_values(array_unique(array_merge($kelasAsPic, $kelasWithJadwal)));
        if ($kelasIds === []) {
            return [];
        }

        $jadwalQuery = Jadwal::whereIn('id_kelas', $kelasIds)->whereNull('deleted_at');
        if ($idSemester) {
            $jadwalQuery->whereHas('kelas', fn ($q) => $q->where('id_semester', $idSemester));
        }
        $jadwalIds = $jadwalQuery->pluck('id')->all();

        $perkuliahan = Perkuliahan::with(['jadwal.kelas.kurikulumMatkul.matkul', 'jadwal.kelas.prodi.jenjang'])
            ->whereIn('id_jadwal', $jadwalIds)
            ->whereNull('deleted_at')
            ->orderByRaw('waktu_mulai IS NULL')
            ->orderBy('waktu_mulai')
            ->orderBy('id')
            ->get();

        return $perkuliahan->groupBy(fn (Perkuliahan $p) => $p->jadwal->id_kelas)
            ->map(function ($group) {
                $kelas = $group->first()->jadwal->kelas;
                $kelasId = $kelas->id;

                $jumlahMahasiswa = Krs::where('id_kelas', $kelasId)->whereNull('deleted_at')
                    ->distinct('id_mahasiswa')->count('id_mahasiswa');

                // Collection::sortBy([$closure, $closure]) memanggil tiap closure sebagai
                // comparator dua-argumen ($a, $b), bukan sebagai pengambil nilai — closure
                // satu-argumen di sini akan diam-diam menghasilkan urutan yang salah. Satu
                // closure yang mengembalikan array kunci majemuk aman untuk perbandingan array
                // bawaan PHP.
                $sorted = $group->sortBy(fn (Perkuliahan $p) => [$p->waktu_mulai?->getTimestamp() ?? \PHP_INT_MAX, $p->id])->values();

                $perkuliahanIds = $sorted->pluck('id')->all();
                $jumlahHadirPerPerkuliahan = Kehadiran::whereIn('id_perkuliahan', $perkuliahanIds)
                    ->whereNull('deleted_at')
                    ->where('status', 'hadir')
                    ->selectRaw('id_perkuliahan, COUNT(DISTINCT id_mhs) as jumlah_hadir')
                    ->groupBy('id_perkuliahan')
                    ->pluck('jumlah_hadir', 'id_perkuliahan');

                return [
                    'kelas' => $kelas,
                    'jumlah_mahasiswa' => $jumlahMahasiswa,
                    'perkuliahan' => $sorted->map(fn (Perkuliahan $p, int $idx) => [
                        'id' => $p->id,
                        'pertemuan_ke' => $idx + 1,
                        'tanggal' => $p->waktu_mulai?->format('Y-m-d'),
                        'materi' => $p->materi,
                        'jumlah_hadir' => (int) ($jumlahHadirPerPerkuliahan[$p->id] ?? 0),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function openRekapModal(int $kelasId): void
    {
        $this->rekapKelasId = $kelasId;
        $this->showRekapModal = true;
    }

    public function closeRekapModal(): void
    {
        $this->showRekapModal = false;
        $this->rekapKelasId = null;
    }

    #[Computed]
    public function rekap(): ?array
    {
        if ($this->rekapKelasId === null) {
            return null;
        }

        $kelas = Kelas::find($this->rekapKelasId);
        if (! $kelas || ! $this->dosenHasAccessToKelas($kelas)) {
            return null;
        }

        return KehadiranRekapService::build($kelas);
    }

    /**
     * Sama persis dengan KehadiranController::dosenBisaAksesKelas — rekapKelasId adalah properti
     * publik yang bisa "disentuh" langsung lewat request Livewire yang dimanipulasi (bukan cuma
     * lewat openRekapModal), jadi akses dicek ulang di sini, bukan hanya lewat rows() yang sudah
     * discope ke kelas milik dosen ini.
     */
    private function dosenHasAccessToKelas(Kelas $kelas): bool
    {
        if ((int) $kelas->id_dosen_pic === $this->dosenId) {
            return true;
        }

        if (KelasDosen::where('id_dosen', $this->dosenId)->where('id_kelas', $kelas->id)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return JadwalDosen::where('id_dosen', $this->dosenId)
            ->where('status', 'active')
            ->whereHas('jadwal', fn ($q) => $q->where('id_kelas', $kelas->id)->whereNull('deleted_at'))
            ->exists();
    }

    public function render()
    {
        return view('livewire.dosen.kehadiran.index')->extends('layouts.dosen');
    }
}
