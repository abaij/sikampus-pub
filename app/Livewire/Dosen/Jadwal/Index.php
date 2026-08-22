<?php

namespace App\Livewire\Dosen\Jadwal;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Semester;
use Illuminate\Support\Collection;
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

    private const HARI_ORDER = [
        'senin' => 1, 'selasa' => 2, 'rabu' => 3, 'kamis' => 4, 'jumat' => 5, 'sabtu' => 6, 'minggu' => 7,
    ];

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
     * Kelas yang diampu dosen ini pada semester terpilih, masing-masing beserta slot jadwalnya.
     *
     * Berangkat dari KELAS, bukan dari jadwal_dosen seperti JadwalDosenController::getMyJadwal:
     * kelas yang sudah diampu tapi belum dijadwalkan sama sekali tetap harus tampil (dengan
     * keterangan bahwa jadwalnya belum ada), bukan hilang diam-diam dari halaman ini. Sumber
     * kelasnya tiga, sama dengan halaman Kelas Mata Kuliah dan Nilai:
     *   - kelas_dosen        → pengampu kelas (PIC maupun tim);
     *   - kelas.id_dosen_pic → PIC yang tercatat langsung di baris kelas;
     *   - jadwal_dosen aktif → dosen yang ditugaskan pada slot jadwal.
     *
     * Slot di dalam satu kelas diurutkan hari lalu jam_mulai sebagai sort dua-kunci — BUKAN
     * `$hariNum * 10000 + $jamMulai` seperti di controller, karena formula angka tunggal itu salah:
     * jam_mulai bisa sampai "23:59:00" (235900), jauh lebih besar dari kelipatan hari (10000),
     * sehingga Senin sore bisa ikut terurut setelah Selasa pagi.
     *
     * @return Collection<int, array{kelas: Kelas, rows: Collection<int, Jadwal>}>
     */
    #[Computed]
    public function kelasGroups()
    {
        $kelasIds = KelasDosen::where('id_dosen', $this->dosenId)
            ->whereNull('deleted_at')
            ->pluck('id_kelas')
            ->merge(
                Kelas::where('id_dosen_pic', $this->dosenId)->whereNull('deleted_at')->pluck('id')
            )
            ->merge(
                JadwalDosen::where('id_dosen', $this->dosenId)
                    ->where('status', 'active')
                    ->with('jadwal:id,id_kelas')
                    ->get()
                    ->pluck('jadwal.id_kelas')
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($kelasIds === []) {
            return collect();
        }

        $kelasList = Kelas::with([
            'kurikulumMatkul.matkul',
            'prodi.jenjang',
            'kelompokKelas',
            'semester',
        ])
            ->whereIn('id', $kelasIds)
            ->whereNull('deleted_at')
            ->when($this->filterSemester !== '', fn ($q) => $q->where('id_semester', (int) $this->filterSemester))
            ->get();

        $jadwalPerKelas = Jadwal::with(['ruangan', 'jenisKuliah'])
            ->whereIn('id_kelas', $kelasList->pluck('id'))
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('id_kelas');

        return $kelasList
            ->map(function (Kelas $kelas) use ($jadwalPerKelas) {
                $rows = collect($jadwalPerKelas->get($kelas->id, collect()))
                    ->sortBy(fn (Jadwal $j) => [$this->urutanHari($j), $j->jam_mulai ?? '00:00:00'])
                    ->values();

                return ['kelas' => $kelas, 'rows' => $rows];
            })
            // Kelas dengan jadwal paling awal tampil lebih dulu; kelas yang belum dijadwalkan
            // jatuh ke bawah (kunci hari 9) dan diurutkan menurut kode mata kuliahnya.
            ->sortBy(fn (array $group) => [
                $group['rows']->isEmpty() ? 9 : $this->urutanHari($group['rows']->first()),
                $group['rows']->first()?->jam_mulai ?? '00:00:00',
                $group['kelas']->kurikulumMatkul?->kodeMatkulLabel() ?? '',
            ])
            ->values();
    }

    private function urutanHari(?Jadwal $jadwal): int
    {
        return self::HARI_ORDER[strtolower((string) $jadwal?->hari)] ?? 8;
    }

    public function render()
    {
        return view('livewire.dosen.jadwal.index')->extends('layouts.dosen');
    }
}
