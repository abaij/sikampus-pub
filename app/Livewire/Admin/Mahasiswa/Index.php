<?php

namespace App\Livewire\Admin\Mahasiswa;

use App\Models\KelompokKelas;
use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\Semester;
use App\Models\StatusAkademik;
use App\Support\PanelAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    // Tabel-tabel yang constrained('mahasiswa')->restrictOnDelete() di migration masing-masing —
    // restrict itu berlaku di level baris DB apa adanya, termasuk baris yang di tabel itu sendiri
    // sudah soft-deleted, jadi dicek lewat DB::table mentah di forceDeleteMahasiswa(). Tidak
    // termasuk survey_response karena migration-nya sudah cascadeOnDelete().
    private const FORCE_DELETE_BLOCKERS = [
        'krs' => ['column' => 'id_mahasiswa', 'label' => 'KRS'],
        'dosen_wali' => ['column' => 'id_mahasiswa', 'label' => 'bimbingan dosen wali'],
        'kehadiran' => ['column' => 'id_mhs', 'label' => 'data kehadiran'],
        'status_mahasiswa' => ['column' => 'id_mahasiswa', 'label' => 'riwayat status mahasiswa'],
        'tagihan' => ['column' => 'id_mahasiswa', 'label' => 'tagihan'],
        'tugas_mahasiswa' => ['column' => 'id_mahasiswa', 'label' => 'tugas mahasiswa'],
        'yudisium' => ['column' => 'id_mahasiswa', 'label' => 'yudisium'],
        'kategori_biaya_mahasiswa' => ['column' => 'id_mahasiswa', 'label' => 'kategori biaya mahasiswa'],
        'tugas_akhir' => ['column' => 'id_mahasiswa', 'label' => 'tugas akhir'],
        'keringanan_biaya' => ['column' => 'id_mahasiswa', 'label' => 'keringanan biaya'],
        'konversi_nilai' => ['column' => 'id_mahasiswa', 'label' => 'konversi nilai'],
        'ktm' => ['column' => 'id_mahasiswa', 'label' => 'KTM'],
        'wisuda_mahasiswa' => ['column' => 'id_mahasiswa', 'label' => 'data wisuda'],
    ];

    public string $search = '';

    public string $filterProdi = '';

    public string $filterKelompokKelas = '';

    public string $filterSemesterMasuk = '';

    public string $filterStatusAkademik = '';

    // Baris yang sudah soft-deleted disembunyikan secara default — dinyalakan lewat toggle supaya
    // admin bisa menemukan lalu memulihkan mahasiswa yang nim/email/akunnya "terkunci" oleh baris
    // terhapus (unique index nim/email/id_user tidak mengecualikan baris soft-deleted). Sama seperti
    // pola yang dipakai di App\Livewire\Admin\Matkul\Index.
    public bool $showTrashed = false;

    public ?int $confirmingForceDeleteId = null;

    public int $perPage = 10;

    public function mount(): void
    {
        // Default filter status akademik "Aktif" — sama dengan perilaku MahasiswaPage di frontend.
        $aktif = StatusAkademik::query()
            ->whereRaw('LOWER(TRIM(nama)) = ?', ['aktif'])
            ->first();

        if ($aktif) {
            $this->filterStatusAkademik = (string) $aktif->id;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterProdi(): void
    {
        // Kelas mahasiswa terikat ke satu prodi — buang pilihan lama supaya tidak
        // menyisakan filter kelas yang sudah tidak relevan dengan prodi yang baru dipilih.
        $this->filterKelompokKelas = '';
        $this->resetPage();
    }

    public function updatingFilterKelompokKelas(): void
    {
        $this->resetPage();
    }

    public function updatingFilterSemesterMasuk(): void
    {
        $this->resetPage();
    }

    public function updatingFilterStatusAkademik(): void
    {
        $this->resetPage();
    }

    /**
     * mount() men-default-kan filterStatusAkademik ke "Aktif" — kalau tidak dibuang saat toggle
     * dinyalakan, baris soft-deleted (yang id_status_akademik-nya tetap apa adanya, tidak berubah
     * cuma karena dihapus) akan tersaring habis oleh filter status default dan toggle ini terlihat
     * seperti tidak berfungsi.
     */
    public function updatingShowTrashed($value): void
    {
        if ($value) {
            $this->filterStatusAkademik = '';
        }
        $this->resetPage();
    }

    /**
     * Tidak ada padanan di MahasiswaController — API belum punya endpoint restore, murni fitur
     * panel. Tidak ada pengecekan konflik nim/email/id_user seperti kode+id_prodi di
     * App\Livewire\Admin\Matkul\Index::restore(): ketiganya unique() satu kolom (bukan komposit)
     * di migration, dan MySQL menegakkan itu tanpa pengecualian untuk nilai non-null — termasuk
     * lewat query mentah sekalipun — jadi baris aktif lain dengan nilai yang sama tidak akan pernah
     * bisa ada sejak awal, beda dari kasus id_prodi NULL milik Matkul yang punya celah nyata.
     */
    public function restore(int $id): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'mahasiswa', 'delete'), 403, 'Anda tidak memiliki hak untuk memulihkan mahasiswa.');

        $mahasiswa = Mahasiswa::onlyTrashed()->findOrFail($id);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data mahasiswa ini.');
            }
        }

        $mahasiswa->restore();

        session()->flash('status', 'Mahasiswa berhasil dipulihkan.');
        $this->resetPage();
    }

    public function confirmForceDelete(int $id): void
    {
        abort_unless(PanelAccess::can(Auth::user(), 'mahasiswa', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus mahasiswa.');

        $this->confirmingForceDeleteId = $id;
    }

    public function cancelForceDelete(): void
    {
        $this->confirmingForceDeleteId = null;
    }

    /**
     * Tidak ada padanan di MahasiswaController — API belum punya endpoint hapus permanen, murni
     * fitur panel. Lihat FORCE_DELETE_BLOCKERS untuk daftar tabel yang restrictOnDelete().
     */
    public function forceDeleteMahasiswa(): void
    {
        if (! $this->confirmingForceDeleteId) {
            return;
        }

        abort_unless(PanelAccess::can(Auth::user(), 'mahasiswa', 'delete'), 403, 'Anda tidak memiliki hak untuk menghapus mahasiswa.');

        $mahasiswa = Mahasiswa::onlyTrashed()->findOrFail($this->confirmingForceDeleteId);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data mahasiswa ini.');
            }
        }

        $blockers = [];
        foreach (self::FORCE_DELETE_BLOCKERS as $table => $meta) {
            if (DB::table($table)->where($meta['column'], $mahasiswa->id)->exists()) {
                $blockers[] = $meta['label'];
            }
        }

        if ($blockers !== []) {
            session()->flash('error', "Tidak bisa menghapus permanen \"{$mahasiswa->nama}\": masih tercatat di data ".implode(', ', $blockers).'. Hapus atau pindahkan data itu terlebih dahulu.');
            $this->confirmingForceDeleteId = null;

            return;
        }

        $mahasiswa->forceDelete();

        $this->confirmingForceDeleteId = null;
        session()->flash('status', 'Mahasiswa berhasil dihapus permanen.');
        $this->resetPage();
    }

    #[Computed]
    public function prodiOptions()
    {
        $query = Prodi::query()->with('jenjang')->orderBy('nama');

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id', $allowedProdiIds);
            }
        }

        return $query->get(['id', 'nama', 'kode', 'id_jenjang']);
    }

    /**
     * Disaring ke prodi yang sedang dipilih di filter Prodi — kelas mahasiswa dari prodi lain
     * tidak relevan untuk ditampilkan sebagai opsi.
     */
    #[Computed]
    public function kelompokKelasOptions()
    {
        $query = KelompokKelas::orderBy('nama');

        if ($this->filterProdi !== '') {
            $query->where('id_prodi', (int) $this->filterProdi);
        }

        return $query->get(['id', 'nama']);
    }

    #[Computed]
    public function semesterOptions()
    {
        return Semester::orderByDesc('kode')->get(['id', 'kode', 'nama']);
    }

    #[Computed]
    public function statusAkademikOptions()
    {
        return StatusAkademik::orderBy('nama')->get(['id', 'nama']);
    }

    /**
     * Sama persis dengan MahasiswaController::index — query + scope-filter disalin,
     * bukan diekstrak jadi shared service (mengikuti pola modul Fakultas/Dosen lainnya) —
     * plus withTrashed() opsional lewat $showTrashed (tidak ada padanan di API, lihat restore()).
     */
    public function render()
    {
        $query = Mahasiswa::with(['prodi.jenjang', 'kelompok_kelas', 'semester_masuk', 'status_akademik']);

        if ($this->showTrashed) {
            $query->withTrashed();
        }

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id_prodi', $allowedProdiIds);
            }
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('nama', 'like', "%{$this->search}%")
                    ->orWhere('nim', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            });
        }

        if ($this->filterProdi !== '') {
            $query->where('id_prodi', (int) $this->filterProdi);
        }

        if ($this->filterKelompokKelas !== '') {
            $query->where('id_kelompok_kelas', (int) $this->filterKelompokKelas);
        }

        if ($this->filterSemesterMasuk !== '') {
            $query->where('id_semester_masuk', (int) $this->filterSemesterMasuk);
        }

        if ($this->filterStatusAkademik !== '') {
            $query->where('id_status_akademik', (int) $this->filterStatusAkademik);
        }

        $mahasiswaList = $query->orderBy('nama')->paginate($this->perPage);

        // Diselipkan ke link "Lihat Detail" supaya tombol Kembali di halaman detail bisa
        // mendarat di halaman/filter yang sama persis — lihat Mahasiswa\Concerns\ForwardsIndexState.
        $returnParams = array_filter([
            'search' => $this->search !== '' ? $this->search : null,
            'id_prodi' => $this->filterProdi !== '' ? $this->filterProdi : null,
            'id_kelompok_kelas' => $this->filterKelompokKelas !== '' ? $this->filterKelompokKelas : null,
            'id_semester_masuk' => $this->filterSemesterMasuk !== '' ? $this->filterSemesterMasuk : null,
            'id_status_akademik' => $this->filterStatusAkademik !== '' ? $this->filterStatusAkademik : null,
            'page' => $mahasiswaList->currentPage() > 1 ? $mahasiswaList->currentPage() : null,
        ], fn ($value) => $value !== null);

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.mahasiswa.index', [
            'mahasiswaList' => $mahasiswaList,
            'returnQuery' => http_build_query($returnParams),
        ])->extends('layouts.web');
    }
}
