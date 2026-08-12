<?php

namespace App\Livewire\Dosen\Jadwal;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\JenisKuliah;
use App\Models\Kehadiran;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\MateriPerkuliahan;
use App\Models\Perkuliahan;
use App\Models\Ruangan;
use App\Models\Tugas;
use App\Models\TugasMahasiswa;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Detail extends Component
{
    use WithFileUploads;

    // Locked: saveJadwal()/saveBahasan() memakai jadwalId langsung tanpa mengecek ulang akses
    // dosen (hanya dicek sekali di mount()) — tanpa ini, jadwalId bisa "disentuh" lewat request
    // Livewire yang dimanipulasi untuk mengedit jadwal milik kelas lain.
    #[Locked]
    public int $kelasId;

    #[Locked]
    public int $jadwalId;

    #[Locked]
    public int $dosenId;

    #[Url(as: 'id_semester')]
    public string $idSemester = '';

    public bool $editing = false;

    public string $hari = '';

    public string $tanggal = '';

    public string $id_ruangan = '';

    public string $id_jenis_kuliah = '';

    public string $bahasan = '';

    public string $tab = 'informasi';

    public string $materiNama = '';

    /** @var TemporaryUploadedFile|null */
    public $materiFile = null;

    public string $tugasNama = '';

    public string $tugasDeskripsi = '';

    public string $tugasTanggalMulai = '';

    public string $tugasTanggalSelesai = '';

    /** @var TemporaryUploadedFile|null */
    public $tugasFile = null;

    // 'none' | 'konfirmasi_mulai_materi' | 'luar_jadwal' — sama dengan MulaiDialog di
    // dosen/jadwal/[kelasId]/[jadwalId]/page.tsx (siak-frontend).
    public string $mulaiDialog = 'none';

    public string $modalMateriSesi = '';

    public bool $selesaiDialogOpen = false;

    public string $formRealisasiMateriSelesai = '';

    public string $submitError = '';

    public function mount(int $kelasId, int $jadwalId): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $jadwal = Jadwal::with('kelas')->find($jadwalId);
        abort_if($jadwal === null || (int) $jadwal->id_kelas !== $kelasId, 404);
        abort_unless($this->dosenCanAccess($dosen, $jadwal), 403, 'Anda tidak memiliki akses ke jadwal ini.');

        $this->kelasId = $kelasId;
        $this->jadwalId = $jadwalId;

        $this->fillFormFrom($jadwal);
    }

    /**
     * Sama persis dengan JadwalDosenController::dosenCanAccessJadwal.
     */
    private function dosenCanAccess(Dosen $dosen, Jadwal $jadwal): bool
    {
        $kelas = $jadwal->kelas;
        if (! $kelas) {
            return false;
        }
        if ((int) $kelas->id_dosen_pic === (int) $dosen->id) {
            return true;
        }
        if (KelasDosen::where('id_dosen', $dosen->id)->where('id_kelas', $kelas->id)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return JadwalDosen::where('id_jadwal', $jadwal->id)
            ->where('id_dosen', $dosen->id)
            ->where('status', 'active')
            ->exists();
    }

    private function fillFormFrom(Jadwal $jadwal): void
    {
        $this->hari = (string) $jadwal->hari;
        $this->tanggal = $jadwal->tanggal?->format('Y-m-d') ?? '';
        $this->id_ruangan = (string) $jadwal->id_ruangan;
        $this->id_jenis_kuliah = (string) $jadwal->id_jenis_kuliah;
        $this->bahasan = (string) $jadwal->bahasan;
    }

    #[Computed]
    public function jadwal(): Jadwal
    {
        return Jadwal::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.prodi.jenjang',
            'kelas.kelompokKelas',
            'kelas.semester',
            'ruangan',
            'jenisKuliah',
            'dosen.dosen',
        ])->findOrFail($this->jadwalId);
    }

    /**
     * Semua baris perkuliahan untuk slot jadwal ini — dasar bagi sesiAktif/perkuliahanTerakhirSlot/
     * perkuliahanUntukKehadiran di bawah, supaya tidak query berkali-kali.
     */
    #[Computed]
    public function perkuliahanCandidates()
    {
        return Perkuliahan::where('id_jadwal', $this->jadwalId)->whereNull('deleted_at')->get();
    }

    /**
     * Sesi yang sedang berlangsung (sudah mulai, belum selesai) untuk slot jadwal ini. Sama persis
     * dengan `sesiAktif` di dosen/jadwal/[kelasId]/[jadwalId]/page.tsx (siak-frontend).
     */
    #[Computed]
    public function sesiAktif(): ?Perkuliahan
    {
        return $this->perkuliahanCandidates
            ->filter(fn (Perkuliahan $p) => $p->waktu_mulai && ! $p->waktu_selesai)
            ->sortByDesc(fn (Perkuliahan $p) => $p->waktu_mulai?->getTimestamp() ?? 0)
            ->first();
    }

    /**
     * Baris perkuliahan terbaru untuk slot ini (berlangsung atau sudah selesai) — dipakai untuk
     * menandai bahwa sesi terakhir slot ini sudah pernah diakhiri. Sama persis dengan
     * `perkuliahanTerakhir` di FE (tie-break waktu_mulai lalu id, sama-sama descending).
     */
    #[Computed]
    public function perkuliahanTerakhirSlot(): ?Perkuliahan
    {
        return $this->perkuliahanCandidates
            ->sortByDesc(fn (Perkuliahan $p) => [$p->waktu_mulai?->getTimestamp() ?? 0, $p->id])
            ->first();
    }

    /**
     * Sesi yang dipakai untuk tab Kehadiran — sesi yang sedang berlangsung, atau sesi terakhir
     * kalau tidak ada yang berlangsung. Sama persis dengan `perkuliahanUntukKehadiran` di FE.
     */
    #[Computed]
    public function perkuliahanUntukKehadiran(): ?Perkuliahan
    {
        return $this->sesiAktif ?? $this->perkuliahanTerakhirSlot;
    }

    /**
     * Sama persis dengan `sesiTerakhirSudahSelesai` di FE — dipakai bisaTampilMulaiSesi() di bawah.
     */
    #[Computed]
    public function sesiTerakhirSudahSelesai(): bool
    {
        $p = $this->perkuliahanTerakhirSlot;

        return $p !== null && $p->waktu_mulai !== null && $p->waktu_selesai !== null;
    }

    /**
     * Tombol "Mulai sesi" hanya tampil kalau tidak ada sesi berlangsung DAN sesi terakhir untuk
     * slot ini belum pernah diakhiri — pembatasan ini FE-only, bukan aturan dari
     * PerkuliahanController::store (API sendiri sebenarnya tetap mengizinkan mulai sesi baru walau
     * sesi sebelumnya di slot yang sama sudah selesai). Sama persis dengan `bisaTampilMulaiSesi`.
     */
    #[Computed]
    public function bisaTampilMulaiSesi(): bool
    {
        return $this->sesiAktif === null && ! $this->sesiTerakhirSudahSelesai;
    }

    public function klikMulaiSesi(): void
    {
        $this->submitError = '';
        $this->modalMateriSesi = $this->bahasan !== '' ? $this->bahasan : '';
        $this->mulaiDialog = 'konfirmasi_mulai_materi';
    }

    public function cancelMulaiDialog(): void
    {
        if ($this->mulaiDialog !== 'none') {
            $this->mulaiDialog = 'none';
        }
    }

    /**
     * Sama persis dengan onKonfirmasiMulaiDariModal di FE — cek jendela jadwal dulu sebelum
     * benar-benar submit; kalau di luar jendela, tampilkan modal peringatan 'luar_jadwal'.
     */
    public function konfirmasiMulaiDariModal(): void
    {
        if ($this->waktuCocokDenganJadwal($this->jadwal)) {
            $this->submitMulaiSesi();
        } else {
            $this->mulaiDialog = 'luar_jadwal';
        }
    }

    public function konfirmasiMulaiLuarJadwal(): void
    {
        $this->mulaiDialog = 'none';
        $this->submitMulaiSesi();
    }

    /**
     * Sama persis dengan waktuCocokDenganJadwal di FE — jendela mulai paling cepat 30 menit
     * sebelum jam_mulai, sampai jam_selesai, berdasarkan tanggal eksplisit jadwal kalau ada,
     * kalau tidak hari ini.
     */
    private function waktuCocokDenganJadwal(Jadwal $jadwal): bool
    {
        $tanggal = $jadwal->tanggal ? $jadwal->tanggal->format('Y-m-d') : now()->format('Y-m-d');
        $mulai = Carbon::parse($tanggal.' '.($jadwal->jam_mulai ?? '00:00:00'));
        $selesai = Carbon::parse($tanggal.' '.($jadwal->jam_selesai ?? '00:00:00'));
        $now = now();

        return $now->gte($mulai->copy()->subMinutes(30)) && $now->lte($selesai);
    }

    /**
     * Sama persis dengan PerkuliahanController::store, disederhanakan karena kelasId/jadwalId/
     * dosenId sudah divalidasi sekali di mount() (bukan divalidasi ulang per-request seperti API).
     */
    private function submitMulaiSesi(): void
    {
        $ongoing = Perkuliahan::where('id_jadwal', $this->jadwalId)
            ->whereNotNull('waktu_mulai')
            ->whereNull('waktu_selesai')
            ->whereNull('deleted_at')
            ->exists();

        if ($ongoing) {
            $this->submitError = 'Masih ada sesi yang berlangsung untuk slot jadwal ini. Akhiri sesi terlebih dahulu.';

            return;
        }

        $materi = trim($this->modalMateriSesi) !== '' ? trim($this->modalMateriSesi) : null;

        DB::transaction(function () use ($materi): void {
            $this->ensureJadwalDosenAktif();

            Perkuliahan::create([
                'id_jadwal' => $this->jadwalId,
                'tanggal' => now()->toDateString(),
                'waktu_mulai' => now(),
                'waktu_selesai' => null,
                'materi' => $materi,
                'created_by' => $this->namaLengkapDosen(),
            ]);
        });

        $this->mulaiDialog = 'none';
        $this->modalMateriSesi = '';
        $this->submitError = '';
        unset(
            $this->perkuliahanCandidates,
            $this->sesiAktif,
            $this->perkuliahanTerakhirSlot,
            $this->perkuliahanUntukKehadiran,
            $this->sesiTerakhirSudahSelesai,
            $this->bisaTampilMulaiSesi,
            $this->kehadiranMahasiswa,
        );
        session()->flash('status_sesi', 'Sesi perkuliahan dimulai.');
    }

    public function klikSelesaikanSesi(): void
    {
        $sesi = $this->sesiAktif;
        if ($sesi === null) {
            return;
        }

        $this->submitError = '';
        $this->formRealisasiMateriSelesai = $sesi->materi !== null && trim((string) $sesi->materi) !== '' ? $sesi->materi : '';
        $this->selesaiDialogOpen = true;
    }

    public function cancelSelesaiDialog(): void
    {
        $this->selesaiDialogOpen = false;
    }

    /**
     * Sama persis dengan PerkuliahanController::selesaiSesi.
     */
    public function submitSelesaiSesi(): void
    {
        $sesi = $this->sesiAktif;
        abort_if($sesi === null, 404, 'Sesi tidak ditemukan.');
        abort_if($sesi->waktu_mulai === null, 422, 'Perkuliahan belum dimulai.');
        abort_if($sesi->waktu_selesai !== null, 422, 'Sesi sudah diakhiri.');

        $realisasi = trim($this->formRealisasiMateriSelesai) !== '' ? trim($this->formRealisasiMateriSelesai) : null;
        $sesi->waktu_selesai = now();
        $sesi->realisasi_materi = $realisasi;
        $sesi->save();

        $this->selesaiDialogOpen = false;
        unset(
            $this->perkuliahanCandidates,
            $this->sesiAktif,
            $this->perkuliahanTerakhirSlot,
            $this->perkuliahanUntukKehadiran,
            $this->sesiTerakhirSudahSelesai,
            $this->bisaTampilMulaiSesi,
            $this->kehadiranMahasiswa,
        );
        session()->flash('status_sesi', 'Sesi perkuliahan diakhiri.');
    }

    /**
     * Sama persis dengan PerkuliahanController::ensureJadwalDosenAktif.
     */
    private function ensureJadwalDosenAktif(): void
    {
        $existing = JadwalDosen::withTrashed()
            ->where('id_jadwal', $this->jadwalId)
            ->where('id_dosen', $this->dosenId)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if ($existing->status !== 'active') {
                $existing->update(['status' => 'active']);
            }

            return;
        }

        JadwalDosen::create([
            'id_jadwal' => $this->jadwalId,
            'id_dosen' => $this->dosenId,
            'status' => 'active',
        ]);
    }

    /**
     * Sama persis dengan PerkuliahanController::namaLengkapDosen.
     */
    private function namaLengkapDosen(): string
    {
        $dosen = Dosen::find($this->dosenId);
        $nama = trim(
            ($dosen?->gelar_depan ? $dosen->gelar_depan.' ' : '').
            (string) ($dosen?->nama ?? '').
            ($dosen?->gelar_belakang ? ', '.$dosen->gelar_belakang : '')
        );

        if ($nama !== '') {
            return $nama;
        }

        $userName = Auth::user()?->name;
        if ($userName && trim((string) $userName) !== '') {
            return trim((string) $userName);
        }

        return (string) (Auth::id() ?? $this->dosenId);
    }

    /**
     * Sama persis dengan KehadiranController::getByPerkuliahan (lihat juga
     * App\Livewire\Dosen\Kehadiran\Detail::mahasiswa, sumber aslinya), tapi id perkuliahannya
     * diambil dari perkuliahanUntukKehadiran() di atas, bukan dari parameter route — tab ini tidak
     * ganti URL, cukup menunjukkan status kehadiran mahasiswa untuk sesi yang relevan pada slot
     * jadwal yang sedang dibuka.
     *
     * @return array<int, array{id_krs: int, mahasiswa: array<string, mixed>, kehadiran: array<string, mixed>|null}>
     */
    #[Computed]
    public function kehadiranMahasiswa(): array
    {
        $perkuliahan = $this->perkuliahanUntukKehadiran;
        if ($perkuliahan === null) {
            return [];
        }

        return Krs::with(['mahasiswa:id,nim,nama', 'mahasiswa.prodi:id,nama'])
            ->where('id_kelas', $this->kelasId)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->get()
            ->map(function (Krs $krs) use ($perkuliahan) {
                $kehadiran = Kehadiran::where('id_perkuliahan', $perkuliahan->id)
                    ->where('id_mhs', $krs->id_mahasiswa)
                    ->first();

                return [
                    'id_krs' => $krs->id,
                    'mahasiswa' => [
                        'id' => $krs->mahasiswa->id,
                        'nim' => $krs->mahasiswa->nim,
                        'nama' => $krs->mahasiswa->nama,
                        'prodi' => $krs->mahasiswa->prodi ? ['id' => $krs->mahasiswa->prodi->id, 'nama' => $krs->mahasiswa->prodi->nama] : null,
                    ],
                    'kehadiran' => $kehadiran ? ['id' => $kehadiran->id, 'status' => $kehadiran->status, 'keterangan' => $kehadiran->keterangan] : null,
                ];
            })
            ->values()
            ->all();
    }

    #[Computed]
    public function ruanganOptions()
    {
        return Ruangan::whereNull('deleted_at')->orderBy('nama')->get(['id', 'nama']);
    }

    #[Computed]
    public function jenisKuliahOptions()
    {
        return JenisKuliah::whereNull('deleted_at')->orderBy('nama')->get(['id', 'nama']);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['informasi', 'kehadiran', 'materi', 'tugas'], true) ? $tab : 'informasi';
    }

    /**
     * Sama persis dengan MateriPerkuliahanController::getByJadwal.
     */
    #[Computed]
    public function materiRows()
    {
        return MateriPerkuliahan::where('id_jadwal', $this->jadwalId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Sama persis dengan MateriPerkuliahanController::store.
     */
    public function uploadMateri(): void
    {
        $validated = $this->validate([
            'materiNama' => ['nullable', 'string', 'max:255'],
            'materiFile' => ['required', 'file', 'max:10240'],
        ]);

        $path = $this->materiFile->store('materi_perkuliahan', 'public');

        MateriPerkuliahan::create([
            'id_jadwal' => $this->jadwalId,
            'nama' => $validated['materiNama'] !== '' && $validated['materiNama'] !== null
                ? $validated['materiNama']
                : $this->materiFile->getClientOriginalName(),
            'file' => $path,
        ]);

        $this->reset(['materiNama', 'materiFile']);
        $this->resetValidation();
        unset($this->materiRows);
        session()->flash('status_materi', 'Materi berhasil diunggah.');
    }

    /**
     * Sama persis dengan TugasController::getByJadwal.
     */
    #[Computed]
    public function tugasRows()
    {
        $tugasList = Tugas::where('id_jadwal', $this->jadwalId)
            ->whereNull('deleted_at')
            ->with('dosen:id,nama')
            ->orderByDesc('created_at')
            ->get();

        $tugasIds = $tugasList->pluck('id')->toArray();
        $jumlahSubmit = [];
        if ($tugasIds !== []) {
            $jumlahSubmit = TugasMahasiswa::whereIn('id_tugas', $tugasIds)
                ->whereNull('deleted_at')
                ->selectRaw('id_tugas, COUNT(*) as jumlah')
                ->groupBy('id_tugas')
                ->pluck('jumlah', 'id_tugas')
                ->toArray();
        }

        return $tugasList->map(function (Tugas $tugas) use ($jumlahSubmit) {
            $tugas->jumlah_submit = (int) ($jumlahSubmit[$tugas->id] ?? 0);

            return $tugas;
        });
    }

    /**
     * Sama persis dengan TugasController::getPengumpulanByJadwalForDosen.
     */
    #[Computed]
    public function tugasPengumpulanRows()
    {
        $tugasIds = Tugas::where('id_jadwal', $this->jadwalId)
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($tugasIds->isEmpty()) {
            return collect();
        }

        return TugasMahasiswa::whereIn('id_tugas', $tugasIds)
            ->whereNull('deleted_at')
            ->with(['mahasiswa:id,nim,nama', 'tugas:id,nama'])
            ->orderByDesc('tanggal_submit')
            ->orderBy('id')
            ->get()
            ->sortBy(function (TugasMahasiswa $tm) {
                return [(int) $tm->id_tugas, (string) ($tm->mahasiswa->nim ?? '')];
            })
            ->values();
    }

    /**
     * Sama persis dengan TugasController::store.
     */
    public function submitTugas(): void
    {
        $validated = $this->validate([
            'tugasNama' => ['required', 'string', 'max:255'],
            'tugasDeskripsi' => ['nullable', 'string'],
            'tugasFile' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'max:10240'],
            'tugasTanggalMulai' => ['nullable', 'date'],
            'tugasTanggalSelesai' => ['nullable', 'date', 'after_or_equal:tugasTanggalMulai'],
        ]);

        $filePath = $this->tugasFile ? $this->tugasFile->store('tugas', 'public') : null;

        Tugas::create([
            'id_jadwal' => $this->jadwalId,
            'id_dosen' => $this->dosenId,
            'nama' => $validated['tugasNama'],
            'deskripsi' => $validated['tugasDeskripsi'] ?? null,
            'file' => $filePath,
            'tanggal_mulai' => $validated['tugasTanggalMulai'] !== '' ? $validated['tugasTanggalMulai'] : null,
            'tanggal_selesai' => $validated['tugasTanggalSelesai'] !== '' ? $validated['tugasTanggalSelesai'] : null,
        ]);

        $this->reset(['tugasNama', 'tugasDeskripsi', 'tugasTanggalMulai', 'tugasTanggalSelesai', 'tugasFile']);
        $this->resetValidation();
        unset($this->tugasRows);
        session()->flash('status_tugas', 'Tugas berhasil ditambahkan.');
    }

    /**
     * Sama persis dengan TugasController::updatePengumpulanStatusForDosen, ditambah pengecekan
     * bahwa pengumpulan ini milik tugas pada jadwalId yang sedang dibuka — idTugasMahasiswa
     * datang langsung dari parameter aksi wire:click, jadi bisa "disentuh" lewat request Livewire
     * yang dimanipulasi untuk menunjuk pengumpulan tugas pada jadwal lain.
     */
    public function terimaPengumpulan(int $idTugasMahasiswa): void
    {
        $tm = TugasMahasiswa::whereNull('deleted_at')
            ->with(['tugas' => function ($q): void {
                $q->whereNull('deleted_at');
            }])
            ->find($idTugasMahasiswa);

        abort_if($tm === null || $tm->tugas === null, 404);
        abort_unless((int) $tm->tugas->id_jadwal === $this->jadwalId, 403, 'Pengumpulan ini bukan untuk jadwal ini.');

        $tm->status = 'accepted';
        $tm->updated_by = Dosen::find($this->dosenId)?->nama;
        $tm->save();

        unset($this->tugasPengumpulanRows);
    }

    public function startEdit(): void
    {
        $this->fillFormFrom($this->jadwal);
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->fillFormFrom($this->jadwal);
        $this->resetValidation();
        $this->editing = false;
    }

    /**
     * Sama persis dengan JadwalDosenController::updateJadwalAmpu.
     */
    public function saveJadwal(): void
    {
        $validated = $this->validate([
            'hari' => ['nullable', 'string', Rule::in(Jadwal::HARI)],
            'tanggal' => ['nullable', 'date'],
            'id_ruangan' => ['nullable', 'integer', 'exists:ruangan,id'],
            'id_jenis_kuliah' => ['nullable', 'integer', 'exists:jenis_kuliah,id'],
        ]);

        $jadwal = Jadwal::findOrFail($this->jadwalId);
        $jadwal->hari = $validated['hari'] !== '' && $validated['hari'] !== null ? strtolower((string) $validated['hari']) : null;
        $jadwal->tanggal = $validated['tanggal'] !== '' ? $validated['tanggal'] : null;
        $jadwal->id_ruangan = $validated['id_ruangan'] !== '' && $validated['id_ruangan'] !== null ? (int) $validated['id_ruangan'] : null;
        $jadwal->id_jenis_kuliah = $validated['id_jenis_kuliah'] !== '' && $validated['id_jenis_kuliah'] !== null ? (int) $validated['id_jenis_kuliah'] : null;
        $jadwal->save();

        unset($this->jadwal);
        $this->editing = false;
        session()->flash('status', 'Jadwal berhasil diperbarui.');
    }

    /**
     * Sama persis dengan JadwalDosenController::updateBahasanJadwalAmpu.
     */
    public function saveBahasan(): void
    {
        $this->validate([
            'bahasan' => ['nullable', 'string', 'max:65535'],
        ]);

        $jadwal = Jadwal::findOrFail($this->jadwalId);
        $jadwal->bahasan = $this->bahasan !== '' ? $this->bahasan : null;
        $jadwal->save();

        unset($this->jadwal);
        session()->flash('status_bahasan', 'Bahasan berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.dosen.jadwal.detail')->extends('layouts.dosen');
    }
}
