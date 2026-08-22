<?php

namespace App\Livewire\Dosen\Jadwal;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JenisKuliah;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Perkuliahan;
use App\Models\Ruangan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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

    public bool $showEditModal = false;

    public ?int $editJadwalId = null;

    public string $editHari = '';

    public string $editTanggal = '';

    public string $editJamMulai = '';

    public string $editJamSelesai = '';

    public string $editIdRuangan = '';

    public string $editIdJenisKuliah = '';

    public bool $showTambahSlotModal = false;

    public string $tambahHari = '';

    public string $tambahTanggal = '';

    public string $tambahJamMulai = '';

    public string $tambahJamSelesai = '';

    public string $tambahIdRuangan = '';

    public string $tambahIdJenisKuliah = '';

    public string $tambahUrutanPertemuan = '';

    public string $tambahBahasan = '';

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
            // Urut menurut pertemuan ke-, bukan menurut nama hari: kolom `hari` berisi teks
            // ("senin", "selasa", …) sehingga orderBy('hari') mengurutkannya secara alfabetis —
            // urutan yang salah, dan barisnya ikut melompat setiap kali dosen mengubah harinya.
            'jadwal' => fn ($q) => $q->whereNull('deleted_at')
                ->with(['ruangan', 'jenisKuliah', 'dosen.dosen'])
                ->orderByRaw('urutan_pertemuan IS NULL, urutan_pertemuan')
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

    public function openTambahSlotModal(): void
    {
        $this->reset([
            'tambahHari', 'tambahTanggal', 'tambahJamMulai', 'tambahJamSelesai',
            'tambahIdRuangan', 'tambahIdJenisKuliah', 'tambahUrutanPertemuan', 'tambahBahasan',
        ]);
        $this->resetValidation();
        $this->showTambahSlotModal = true;
    }

    public function closeTambahSlotModal(): void
    {
        $this->showTambahSlotModal = false;
    }

    /**
     * Dosen belum punya endpoint API sendiri untuk menambah slot jadwal — hanya admin, lewat
     * JadwalController::store yang sekaligus bulk-generate banyak pertemuan dari jumlah_pertemuan
     * (dengan opsi tanggal/hari otomatis mingguan). Form ini sengaja dibuat untuk menambah SATU
     * slot saja per submit (bukan bulk generator seperti punya admin) — sesuai kebutuhan halaman
     * ini — tapi aturan validasi per-field dan pengecekan slot ganda (kelas + urutan_pertemuan +
     * ruangan yang sama) disalin dari rule yang sama dengan JadwalController::store supaya tetap
     * konsisten. is_active default false, sama seperti Admin\Jadwal\Form dan
     * JadwalController::store (di panel ini is_active tidak menggerbang tampil/tidaknya jadwal di
     * sisi dosen mana pun, murni informasi status).
     */
    public function saveTambahSlot(): void
    {
        $validated = $this->validate([
            'tambahHari' => ['nullable', 'string', Rule::in(Jadwal::HARI)],
            'tambahTanggal' => ['nullable', 'date'],
            'tambahJamMulai' => ['required', 'date_format:H:i'],
            'tambahJamSelesai' => ['required', 'date_format:H:i', 'after:tambahJamMulai'],
            'tambahIdRuangan' => ['nullable', 'integer', 'exists:ruangan,id'],
            'tambahIdJenisKuliah' => ['nullable', 'integer', 'exists:jenis_kuliah,id'],
            'tambahUrutanPertemuan' => ['nullable', 'integer', 'min:1', 'max:99'],
            'tambahBahasan' => ['nullable', 'string', 'max:65535'],
        ], [
            'tambahJamSelesai.after' => 'Jam selesai harus lebih besar dari jam mulai.',
        ]);

        $idRuangan = $validated['tambahIdRuangan'] !== '' && $validated['tambahIdRuangan'] !== null ? (int) $validated['tambahIdRuangan'] : null;
        $urutanPertemuan = $validated['tambahUrutanPertemuan'] !== '' && $validated['tambahUrutanPertemuan'] !== null ? (int) $validated['tambahUrutanPertemuan'] : null;

        if ($urutanPertemuan !== null) {
            $slotQuery = Jadwal::where('id_kelas', $this->kelasId)
                ->where('urutan_pertemuan', $urutanPertemuan)
                ->whereNull('deleted_at');
            $slotQuery = $idRuangan ? $slotQuery->where('id_ruangan', $idRuangan) : $slotQuery->whereNull('id_ruangan');

            if ($slotQuery->exists()) {
                $this->addError('tambahUrutanPertemuan', "Slot pertemuan ke-{$urutanPertemuan} untuk kelas dan ruangan ini sudah terisi.");

                return;
            }
        }

        Jadwal::create([
            'id_kelas' => $this->kelasId,
            'hari' => $validated['tambahHari'] !== '' && $validated['tambahHari'] !== null ? strtolower((string) $validated['tambahHari']) : null,
            'tanggal' => $validated['tambahTanggal'] !== '' ? $validated['tambahTanggal'] : null,
            'jam_mulai' => $validated['tambahJamMulai'],
            'jam_selesai' => $validated['tambahJamSelesai'],
            'id_ruangan' => $idRuangan,
            'id_jenis_kuliah' => $validated['tambahIdJenisKuliah'] !== '' && $validated['tambahIdJenisKuliah'] !== null ? (int) $validated['tambahIdJenisKuliah'] : null,
            'urutan_pertemuan' => $urutanPertemuan,
            'bahasan' => $validated['tambahBahasan'] !== '' ? $validated['tambahBahasan'] : null,
            'is_active' => false,
        ]);

        unset($this->kelas, $this->jadwalRows);
        $this->showTambahSlotModal = false;
        session()->flash('status', 'Slot jadwal pertemuan berhasil ditambahkan.');
    }

    /**
     * Ubah satu slot jadwal langsung dari daftar, tanpa harus masuk ke halaman detailnya.
     *
     * Dosen tidak boleh MEMBUAT jadwal (tidak ada tambah slot/generate di halaman ini), tapi boleh
     * MENGUBAH slot yang sudah ada — berlaku untuk semua dosen yang mengampu kelas ini, sama
     * dengan siapa yang boleh membuka halaman ini (mount() sudah memastikan lewat kelas_dosen).
     * Field dan aturan validasinya sama persis dengan form ubah di Dosen\Jadwal\Detail::saveJadwal,
     * supaya dua pintu masuk ini tidak berbeda perilaku.
     */
    public function openEditModal(int $jadwalId): void
    {
        $jadwal = Jadwal::whereNull('deleted_at')->find($jadwalId);

        abort_unless($jadwal !== null, 404, 'Slot jadwal tidak ditemukan.');
        abort_unless((int) $jadwal->id_kelas === $this->kelasId, 403, 'Slot jadwal ini bukan milik kelas yang sedang dibuka.');

        $this->resetValidation();
        $this->editJadwalId = $jadwal->id;
        $this->editHari = (string) $jadwal->hari;
        $this->editTanggal = $jadwal->tanggal?->format('Y-m-d') ?? '';
        // Kolomnya bertipe TIME ("08:00:00"), sedangkan <input type="time"> memakai H:i.
        $this->editJamMulai = $jadwal->jam_mulai ? substr((string) $jadwal->jam_mulai, 0, 5) : '';
        $this->editJamSelesai = $jadwal->jam_selesai ? substr((string) $jadwal->jam_selesai, 0, 5) : '';
        $this->editIdRuangan = (string) $jadwal->id_ruangan;
        $this->editIdJenisKuliah = (string) $jadwal->id_jenis_kuliah;
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editJadwalId = null;
    }

    public function saveEditJadwal(): void
    {
        abort_unless($this->editJadwalId !== null, 404, 'Slot jadwal tidak ditemukan.');

        $jadwal = Jadwal::whereNull('deleted_at')->find($this->editJadwalId);

        abort_unless($jadwal !== null, 404, 'Slot jadwal tidak ditemukan.');
        // Dicek ulang di sini, bukan hanya saat modal dibuka: editJadwalId datang dari sisi klien.
        abort_unless((int) $jadwal->id_kelas === $this->kelasId, 403, 'Slot jadwal ini bukan milik kelas yang sedang dibuka.');

        $validated = $this->validate([
            'editHari' => ['nullable', 'string', Rule::in(Jadwal::HARI)],
            'editTanggal' => ['nullable', 'date'],
            'editJamMulai' => ['nullable', 'date_format:H:i', 'required_with:editJamSelesai'],
            'editJamSelesai' => ['nullable', 'date_format:H:i', 'required_with:editJamMulai', 'after:editJamMulai'],
            'editIdRuangan' => ['nullable', 'integer', 'exists:ruangan,id'],
            'editIdJenisKuliah' => ['nullable', 'integer', 'exists:jenis_kuliah,id'],
        ], [
            'editJamMulai.required_with' => 'Jam mulai wajib diisi bila jam selesai diisi.',
            'editJamSelesai.required_with' => 'Jam selesai wajib diisi bila jam mulai diisi.',
            'editJamSelesai.after' => 'Jam selesai harus lebih besar dari jam mulai.',
        ]);

        $jadwal->hari = $validated['editHari'] !== '' && $validated['editHari'] !== null
            ? strtolower((string) $validated['editHari'])
            : null;
        $jadwal->tanggal = $validated['editTanggal'] !== '' ? $validated['editTanggal'] : null;
        $jadwal->jam_mulai = $validated['editJamMulai'] !== '' ? $validated['editJamMulai'] : null;
        $jadwal->jam_selesai = $validated['editJamSelesai'] !== '' ? $validated['editJamSelesai'] : null;
        $jadwal->id_ruangan = $validated['editIdRuangan'] !== '' && $validated['editIdRuangan'] !== null
            ? (int) $validated['editIdRuangan']
            : null;
        $jadwal->id_jenis_kuliah = $validated['editIdJenisKuliah'] !== '' && $validated['editIdJenisKuliah'] !== null
            ? (int) $validated['editIdJenisKuliah']
            : null;
        $jadwal->save();

        unset($this->kelas, $this->jadwalRows);
        $this->showEditModal = false;
        $this->editJadwalId = null;
        session()->flash('status', 'Slot jadwal berhasil diperbarui.');
    }

    public function render()
    {
        return view('livewire.dosen.jadwal.show')->extends('layouts.dosen');
    }
}
