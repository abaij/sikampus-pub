<?php

namespace App\Livewire\Admin\Jadwal;

use App\Livewire\Admin\Jadwal\Concerns\ForwardsIndexState;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\JenisKuliah;
use App\Models\Kelas;
use App\Models\Prodi;
use App\Models\Ruangan;
use App\Models\Semester;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Form extends Component
{
    use ForwardsIndexState;

    public ?int $jadwalId = null;

    // Dua properti ini murni untuk menyaring opsi id_kelas — tidak dikirim ke server.
    public ?int $filterProdi = null;

    public ?int $filterSemester = null;

    public ?int $id_kelas = null;

    // Create-only: jumlah slot pertemuan yang akan dibuat sekaligus (mis. JadwalController::store).
    public string $jumlah_pertemuan = '16';

    // Edit-only: urutan pertemuan pada baris jadwal yang sedang diubah.
    public string $urutan_pertemuan = '1';

    public string $tanggal = '';

    // Create-only.
    public bool $tanggal_hari_otomatis = false;

    public ?string $hari = null;

    public string $jam_mulai = '';

    public string $jam_selesai = '';

    public ?int $id_ruangan = null;

    public ?int $id_jenis_kuliah = null;

    public bool $is_active = false;

    public string $dosenSearch = '';

    /** @var array<int> */
    public array $dosenIds = [];

    /** @var array<int, string> */
    public array $dosenLabelById = [];

    public function mount(?int $id = null): void
    {
        $this->jadwalId = $id;
        $this->resolveBackUrl();

        if ($id === null) {
            return;
        }

        $jadwal = Jadwal::with(['kelas', 'dosen.dosen'])->findOrFail($id);
        $this->ensureAccess($jadwal);

        $this->id_kelas = $jadwal->id_kelas;
        $this->filterProdi = $jadwal->kelas?->id_prodi;
        $this->filterSemester = $jadwal->kelas?->id_semester;
        $this->urutan_pertemuan = (string) ($jadwal->urutan_pertemuan ?? 1);
        $this->tanggal = $jadwal->tanggal ? $jadwal->tanggal->format('Y-m-d') : '';
        $this->hari = $jadwal->hari;
        // Kolom TIME MySQL kembali sebagai "HH:MM:SS" — potong ke "HH:MM" supaya cocok dengan
        // input type="time" dan rule date_format:H:i.
        $this->jam_mulai = $jadwal->jam_mulai ? substr((string) $jadwal->jam_mulai, 0, 5) : '';
        $this->jam_selesai = $jadwal->jam_selesai ? substr((string) $jadwal->jam_selesai, 0, 5) : '';
        $this->id_ruangan = $jadwal->id_ruangan;
        $this->id_jenis_kuliah = $jadwal->id_jenis_kuliah;
        $this->is_active = (bool) $jadwal->is_active;

        foreach ($jadwal->dosen as $jd) {
            if (! $jd->dosen) {
                continue;
            }
            $this->dosenIds[] = (int) $jd->id_dosen;
            $this->dosenLabelById[$jd->id_dosen] = $this->formatDosenLabel($jd->dosen);
        }
    }

    /**
     * Sama persis dengan JadwalController — pengecekan scope prodi lewat kelas.
     */
    private function ensureAccess(Jadwal $jadwal): void
    {
        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $kelas = $jadwal->kelas ?? Kelas::find($jadwal->id_kelas);
            if (! $kelas) {
                abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
            }
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
            }
        }
    }

    /**
     * Ganti prodi/semester filter = daftar kelas yang tersedia berubah, kelas lama dibuang.
     */
    public function updatedFilterProdi(): void
    {
        $this->id_kelas = null;
    }

    public function updatedFilterSemester(): void
    {
        $this->id_kelas = null;
    }

    /**
     * Sama seperti handleChange('id_kelas', ...) di app/admin/jadwal/new/page.tsx — jumlah
     * pertemuan default mengikuti kelas.jml_pertemuan, dan tim dosen direset (hanya saat create).
     */
    public function updatedIdKelas(): void
    {
        if ($this->id_kelas === null) {
            return;
        }

        $kelas = Kelas::find($this->id_kelas);
        if ($this->jadwalId === null) {
            $jml = $kelas?->jml_pertemuan;
            $this->jumlah_pertemuan = (string) max(1, min(99, $jml ?? 16));
            $this->dosenIds = [];
            $this->dosenLabelById = [];
        }
    }

    private function formatDosenLabel(Dosen $dosen): string
    {
        $label = trim(($dosen->gelar_depan ? $dosen->gelar_depan.' ' : '').$dosen->nama.($dosen->gelar_belakang ? ', '.$dosen->gelar_belakang : ''));

        return $dosen->kode_dosen ? "{$label} ({$dosen->kode_dosen})" : $label;
    }

    /**
     * Tidak dibatasi limit() — sudah disaring lewat scope prodi user plus filterProdi/filterSemester
     * di form, jadi hasilnya bounded oleh filter itu sendiri, bukan angka arbitrer. Lihat catatan
     * serupa di App\Livewire\Admin\Krs\Form::kelasOptions().
     */
    #[Computed]
    public function kelasOptions()
    {
        $user = Auth::user();
        $query = Kelas::with(['kurikulumMatkul.matkul', 'semester'])->whereNull('deleted_at');

        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id_prodi', $allowedProdiIds);
            }
        }

        if ($this->filterProdi) {
            $query->where('id_prodi', $this->filterProdi);
        }
        if ($this->filterSemester) {
            $query->where('id_semester', $this->filterSemester);
        }

        return $query->orderBy('id')->get()->map(fn (Kelas $k) => (object) [
            'id' => $k->id,
            'label' => trim(($k->kurikulumMatkul?->matkul?->kode ? "{$k->kurikulumMatkul->matkul->kode} - " : '').($k->kurikulumMatkul?->matkul?->nama ?? 'Kelas').($k->semester ? " ({$k->semester->nama} {$k->semester->kode})" : '')),
        ]);
    }

    #[Computed]
    public function dosenSearchResults()
    {
        if ($this->dosenSearch === '') {
            return collect();
        }

        return Dosen::query()
            ->where(function ($q) {
                $q->where('nama', 'like', "%{$this->dosenSearch}%")
                    ->orWhere('kode_dosen', 'like', "%{$this->dosenSearch}%");
            })
            ->whereNotIn('id', $this->dosenIds)
            ->orderBy('nama')
            ->limit(20)
            ->get();
    }

    public function addDosen(int $id): void
    {
        if (in_array($id, $this->dosenIds, true)) {
            return;
        }

        $dosen = Dosen::find($id);
        if (! $dosen) {
            return;
        }

        $this->dosenIds[] = $id;
        $this->dosenLabelById[$id] = $this->formatDosenLabel($dosen);
        $this->dosenSearch = '';
    }

    public function removeDosen(int $id): void
    {
        $this->dosenIds = array_values(array_diff($this->dosenIds, [$id]));
    }

    /**
     * Senin–Minggu dari tanggal — sama persis dengan JadwalController::hariDariTanggal.
     */
    private function hariDariTanggal(Carbon $dt): string
    {
        $idx = (int) $dt->format('N') - 1;

        return Jadwal::HARI[$idx] ?? 'senin';
    }

    /**
     * Rule sama persis dengan JadwalController::store/update (dipisah per mode, sama seperti
     * bentuk validasi API-nya yang berbeda antara create — jumlah_pertemuan — dan edit —
     * urutan_pertemuan).
     */
    protected function rules(): array
    {
        $common = [
            'id_kelas' => ['required', 'integer', 'exists:kelas,id'],
            'hari' => ['nullable', 'string', Rule::in(Jadwal::HARI)],
            'jam_mulai' => ['nullable', 'date_format:H:i'],
            'jam_selesai' => ['nullable', 'date_format:H:i'],
            'id_ruangan' => ['nullable', 'integer', 'exists:ruangan,id'],
            'id_jenis_kuliah' => ['nullable', 'integer', 'exists:jenis_kuliah,id'],
            'tanggal' => ['nullable', 'date'],
        ];

        if ($this->jadwalId === null) {
            return $common + [
                'jumlah_pertemuan' => ['required', 'integer', 'min:1', 'max:99'],
            ];
        }

        return $common + [
            'urutan_pertemuan' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    /**
     * Sama persis dengan JadwalController::store (membuat beberapa slot pertemuan sekaligus).
     */
    private function saveCreate()
    {
        $validated = $this->validate();

        if ($this->tanggal_hari_otomatis && ! $this->tanggal) {
            $this->addError('tanggal', 'Tanggal mulai wajib diisi jika opsi tanggal & hari otomatis diaktifkan.');

            return null;
        }

        if ($this->jam_mulai && $this->jam_selesai && strtotime($this->jam_selesai) <= strtotime($this->jam_mulai)) {
            $this->addError('jam_selesai', 'Jam selesai harus lebih besar dari jam mulai.');

            return null;
        }

        $user = Auth::user();
        $kelas = Kelas::find($validated['id_kelas']);
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && (! $kelas || ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true))) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $n = (int) $validated['jumlah_pertemuan'];
        for ($u = 1; $u <= $n; $u++) {
            $slotQ = Jadwal::where('id_kelas', $validated['id_kelas'])->where('urutan_pertemuan', $u);
            if ($this->id_ruangan) {
                $slotQ->where('id_ruangan', $this->id_ruangan);
            } else {
                $slotQ->whereNull('id_ruangan');
            }
            if ($slotQ->exists()) {
                $this->addError('jumlah_pertemuan', "Slot pertemuan ke-{$u} untuk kelas dan ruangan ini sudah terisi.");

                return null;
            }
        }

        $isMingguan = ($kelas && $kelas->is_mingguan === true) || $this->tanggal_hari_otomatis;

        DB::transaction(function () use ($n, $kelas, $isMingguan, $validated): void {
            for ($u = 1; $u <= $n; $u++) {
                $tanggalSlot = null;
                $hariSlot = $this->hari;
                if ($this->tanggal) {
                    if ($isMingguan) {
                        $dt = Carbon::parse($this->tanggal)->startOfDay()->addWeeks($u - 1);
                        $tanggalSlot = $dt->format('Y-m-d');
                        if ($this->tanggal_hari_otomatis || ($kelas && $kelas->is_mingguan === true)) {
                            $hariSlot = $this->hariDariTanggal($dt);
                        }
                    } else {
                        $tanggalSlot = $u === 1 ? $this->tanggal : null;
                    }
                }

                $jadwal = Jadwal::create([
                    'id_kelas' => $validated['id_kelas'],
                    'id_jenis_kuliah' => $this->id_jenis_kuliah,
                    'tanggal' => $tanggalSlot,
                    'hari' => $hariSlot,
                    'jam_mulai' => $this->jam_mulai ?: null,
                    'jam_selesai' => $this->jam_selesai ?: null,
                    'id_ruangan' => $this->id_ruangan,
                    'urutan_pertemuan' => $u,
                    'is_active' => $this->is_active,
                ]);

                foreach ($this->dosenIds as $dosenId) {
                    JadwalDosen::create([
                        'id_jadwal' => $jadwal->id,
                        'id_dosen' => $dosenId,
                        'status' => 'active',
                    ]);
                }
            }
        });

        session()->flash('status', 'Jadwal berhasil disimpan.');

        return redirect($this->backUrl);
    }

    /**
     * Sama persis dengan JadwalController::update.
     */
    private function saveUpdate()
    {
        $validated = $this->validate();

        $jadwal = Jadwal::with('kelas')->findOrFail($this->jadwalId);
        $this->ensureAccess($jadwal);

        if ($this->jam_mulai && $this->jam_selesai && strtotime($this->jam_selesai) <= strtotime($this->jam_mulai)) {
            $this->addError('jam_selesai', 'Jam selesai harus lebih besar dari jam mulai.');

            return null;
        }

        $dupQ = Jadwal::where('id_kelas', $validated['id_kelas'])
            ->where('urutan_pertemuan', (int) $validated['urutan_pertemuan'])
            ->where('id', '!=', $jadwal->id);
        if ($this->id_ruangan) {
            $dupQ->where('id_ruangan', $this->id_ruangan);
        } else {
            $dupQ->whereNull('id_ruangan');
        }
        if ($dupQ->exists()) {
            $this->addError('urutan_pertemuan', 'Sudah ada jadwal untuk kelas, ruangan, dan urutan pertemuan ini.');

            return null;
        }

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction() && $validated['id_kelas'] !== $jadwal->id_kelas) {
            $newKelas = Kelas::find($validated['id_kelas']);
            $allowedProdiIds = $user->getAllowedProdiIds();
            if (! $newKelas || ($allowedProdiIds !== null && ! in_array((int) $newKelas->id_prodi, $allowedProdiIds, true))) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        DB::transaction(function () use ($validated, $jadwal): void {
            $jadwal->update([
                'id_kelas' => $validated['id_kelas'],
                'urutan_pertemuan' => (int) $validated['urutan_pertemuan'],
                'id_jenis_kuliah' => $this->id_jenis_kuliah,
                'hari' => $this->hari,
                'tanggal' => $this->tanggal ?: null,
                'jam_mulai' => $this->jam_mulai ?: null,
                'jam_selesai' => $this->jam_selesai ?: null,
                'id_ruangan' => $this->id_ruangan,
                'is_active' => $this->is_active,
            ]);

            JadwalDosen::withTrashed()
                ->where('id_jadwal', $jadwal->id)
                ->whereNotIn('id_dosen', $this->dosenIds)
                ->forceDelete();

            foreach ($this->dosenIds as $dosenId) {
                $existing = JadwalDosen::withTrashed()
                    ->where('id_jadwal', $jadwal->id)
                    ->where('id_dosen', $dosenId)
                    ->first();

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update(['status' => 'active']);
                } else {
                    JadwalDosen::create([
                        'id_jadwal' => $jadwal->id,
                        'id_dosen' => $dosenId,
                        'status' => 'active',
                    ]);
                }
            }
        });

        session()->flash('status', 'Jadwal berhasil disimpan.');

        return redirect($this->backUrl);
    }

    public function save()
    {
        return $this->jadwalId === null ? $this->saveCreate() : $this->saveUpdate();
    }

    public function render()
    {
        $user = Auth::user();
        $prodiQuery = Prodi::with('jenjang')->whereNull('deleted_at');
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $prodiQuery->whereIn('id', $allowedProdiIds);
            }
        }

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.jadwal.form', [
            'prodiOptions' => $prodiQuery->orderBy('nama')->get()->map(fn (Prodi $p) => (object) [
                'id' => $p->id,
                'label' => $p->jenjang?->kode ? "{$p->nama} ({$p->jenjang->kode})" : $p->nama,
            ]),
            'semesterOptions' => Semester::whereNull('deleted_at')->orderByDesc('kode')->get(['id', 'kode', 'nama'])
                ->map(fn (Semester $s) => (object) ['id' => $s->id, 'label' => "{$s->nama} ({$s->kode})"]),
            'hariOptions' => collect(Jadwal::HARI)->mapWithKeys(fn ($h) => [$h => ucfirst($h)])->all(),
            'jenisKuliahOptions' => JenisKuliah::whereNull('deleted_at')->orderBy('nama')->get(['id', 'nama']),
            'ruanganOptions' => Ruangan::whereNull('deleted_at')->orderBy('nama')->get(['id', 'nama']),
        ])->extends('layouts.web');
    }
}
