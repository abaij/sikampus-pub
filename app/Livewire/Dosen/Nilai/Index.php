<?php

namespace App\Livewire\Dosen\Nilai;

use App\Models\Dosen;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Semester;
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

    public function mount(): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        // Default ke semester aktif — di situlah nilai sedang diinput. Semester lain tetap bisa
        // dipilih untuk menengok nilai lampau (read-only, lihat isSemesterAktif()).
        // Query string menang: kalau halaman dibuka lewat tautan "Kembali" yang membawa
        // id_semester, Livewire sudah mengisi properti ini sebelum mount() jalan — jangan ditimpa
        // dengan default semester aktif.
        if ($this->filterSemester === '') {
            $this->filterSemester = $this->activeSemester ? (string) $this->activeSemester->id : '';
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
     * Kelas ini berada di semester aktif? Penentu apakah tombol Input Nilai boleh muncul —
     * aturan yang sama dikunci ulang di Dosen\Nilai\Input::mount.
     */
    public function isSemesterAktif(Kelas $kelas): bool
    {
        return $this->activeSemester !== null
            && (int) $kelas->id_semester === (int) $this->activeSemester->id;
    }

    #[Computed]
    public function activeSemester(): ?Semester
    {
        return Semester::where('is_active', true)->whereNull('deleted_at')->first();
    }

    /**
     * Daftar kelas yang diampu dosen ini pada semester terpilih — cakupannya disamakan dengan
     * halaman Kelas Mata Kuliah, yaitu tiga sumber sekaligus:
     *   - kelas_dosen        → pengampu kelas (PIC maupun tim), sumber utama halaman Kelas;
     *   - kelas.id_dosen_pic → PIC yang tercatat langsung di baris kelas;
     *   - jadwal_dosen aktif → dosen yang ditugaskan pada slot jadwal.
     * Ketiganya dipakai karena itu pula yang diterima dosenHasAccess() di halaman Input/Rekap;
     * kalau daftar ini lebih sempit, ada kelas yang tidak bisa dinilai walau jelas diampu, dan
     * kalau lebih longgar, tombolnya akan berujung 403.
     *
     * Beda dengan NilaiController::getMyMataKuliah (API) yang hanya PIC + jadwal_dosen dan selalu
     * mengunci ke semester aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $kelasIdsPengampu = KelasDosen::where('id_dosen', $this->dosenId)
            ->whereNull('deleted_at')
            ->pluck('id_kelas');

        $kelasIdsPic = Kelas::where('id_dosen_pic', $this->dosenId)
            ->whereNull('deleted_at')
            ->pluck('id');

        $kelasIdsJadwal = JadwalDosen::where('id_dosen', $this->dosenId)
            ->where('status', 'active')
            ->with('jadwal:id,id_kelas')
            ->get()
            ->pluck('jadwal.id_kelas');

        $kelasIds = $kelasIdsPengampu
            ->merge($kelasIdsPic)
            ->merge($kelasIdsJadwal)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($kelasIds === []) {
            return [];
        }

        $kelasList = Kelas::with(['kurikulumMatkul.matkul', 'prodi.jenjang', 'semester'])
            ->whereIn('id', $kelasIds)
            ->whereNull('deleted_at')
            ->when($this->filterSemester !== '', fn ($q) => $q->where('id_semester', (int) $this->filterSemester))
            ->get();

        $mahasiswaCounts = Krs::whereIn('id_kelas', $kelasList->pluck('id'))
            ->whereNull('deleted_at')
            ->selectRaw('id_kelas, COUNT(DISTINCT id_mahasiswa) as jumlah_mahasiswa')
            ->groupBy('id_kelas')
            ->pluck('jumlah_mahasiswa', 'id_kelas');

        return $kelasList
            ->map(function (Kelas $kelas) use ($mahasiswaCounts) {
                $km = $kelas->kurikulumMatkul;

                return [
                    'kelas' => $kelas,
                    'kode_matkul' => $km?->kodeMatkulLabel() ?? '-',
                    'nama_matkul' => $km?->namaMatkulLabel() ?? '-',
                    'sks' => $km?->sksLabel() ?? 0,
                    'jumlah_mahasiswa' => (int) ($mahasiswaCounts[$kelas->id] ?? 0),
                    'semester_aktif' => $this->isSemesterAktif($kelas),
                ];
            })
            ->sortBy('nama_matkul')
            // Daftar bisa lintas semester saat filternya dikosongkan; semester terbaru didahulukan.
            ->sortByDesc(fn (array $row) => $row['kelas']->semester?->kode ?? '')
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.dosen.nilai.index')->extends('layouts.dosen');
    }
}
