<?php

namespace App\Livewire\Admin\Kelas;

use App\Models\Kelas;
use App\Models\KelompokKelas;
use App\Models\Prodi;
use App\Models\Semester;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    // Tabel-tabel yang constrained('kelas')->restrictOnDelete() di migration masing-masing —
    // restrict itu berlaku di level baris DB apa adanya, termasuk baris yang di tabel itu sendiri
    // sudah soft-deleted, jadi dicek lewat DB::table mentah di forceDeleteKelas(). Sama seperti
    // pola FORCE_DELETE_BLOCKERS di App\Livewire\Admin\Matkul\Index dan Mahasiswa\Index.
    private const FORCE_DELETE_BLOCKERS = [
        'krs' => ['column' => 'id_kelas', 'label' => 'KRS'],
        'jadwal' => ['column' => 'id_kelas', 'label' => 'jadwal'],
        'kelas_dosen' => ['column' => 'id_kelas', 'label' => 'dosen pengampu'],
        'rps' => ['column' => 'id_kelas', 'label' => 'RPS'],
        'ujian' => ['column' => 'id_kelas', 'label' => 'ujian'],
    ];

    // #[Url] supaya state ini bisa dibaca ulang lewat query string ketika user kembali dari
    // halaman detail/ubah (lihat Kelas\Concerns\ForwardsIndexState) — bukan cuma kosmetik alamat browser.
    #[Url(as: 'search')]
    public string $search = '';

    // Properti filter yang terikat <select> harus string, bukan ?int — lihat catatan di SKILL.md.
    #[Url(as: 'id_prodi')]
    public string $filterProdi = '';

    #[Url(as: 'id_semester')]
    public string $filterSemester = '';

    #[Url(as: 'id_kelompok_kelas')]
    public string $filterKelompokKelas = '';

    // Baris yang sudah soft-deleted disembunyikan secara default — dinyalakan lewat toggle supaya
    // admin bisa menemukan lalu memulihkan kelas yang kombinasi kelompok+kurikulum_matkul+
    // semester+angkatan-nya "terkunci" oleh baris terhapus (unique index kelas_unique tidak
    // mengecualikan baris soft-deleted). Sama seperti pola di App\Livewire\Admin\Matkul\Index.
    #[Url(as: 'trashed')]
    public bool $showTrashed = false;

    public int $perPage = 10;

    public ?int $confirmingDeleteId = null;

    public ?int $confirmingForceDeleteId = null;

    public function mount(): void
    {
        // Sama seperti default filter semester di app/admin/kelas/page.tsx: semester aktif ter-pilih
        // otomatis — tapi hanya kalau belum ada filter dari query string (mis. saat kembali dari
        // halaman detail/ubah dengan filter semester tertentu yang sudah dipilih sebelumnya).
        if ($this->filterSemester !== '') {
            return;
        }

        $semesterAktif = Semester::where('is_active', true)->first();
        if ($semesterAktif) {
            $this->filterSemester = (string) $semesterAktif->id;
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterProdi(): void
    {
        // Opsi "Kelas Mahasiswa" ikut disaring oleh prodi (lihat render()) — nilai lama yang
        // mungkin sudah tidak relevan untuk prodi baru harus dibuang.
        $this->filterKelompokKelas = '';
        $this->resetPage();
    }

    public function updatingFilterSemester(): void
    {
        $this->resetPage();
    }

    public function updatingFilterKelompokKelas(): void
    {
        $this->resetPage();
    }

    public function updatingShowTrashed(): void
    {
        $this->resetPage();
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    /**
     * Sama persis dengan KelasController::destroy — scope-filter dicek ulang di sini.
     */
    public function delete(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        $kelas = Kelas::findOrFail($this->confirmingDeleteId);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $kelas->delete();

        $this->confirmingDeleteId = null;
        $this->resetPage();
    }

    /**
     * Tidak ada padanan di KelasController — API belum punya endpoint restore, murni fitur panel.
     * Sama seperti App\Livewire\Admin\Matkul\Index::restore(): kelas_unique (id_kelompok_kelas +
     * id_kurikulum_matkul + id_semester + id_angkatan) tidak mengecualikan baris soft-deleted, jadi
     * dicek dulu supaya tidak menabrak unique constraint dengan error mentah.
     */
    public function restore(int $id): void
    {
        $kelas = Kelas::onlyTrashed()->findOrFail($id);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $conflictQuery = Kelas::where('id_kurikulum_matkul', $kelas->id_kurikulum_matkul)
            ->where('id_semester', $kelas->id_semester)
            ->where('id_angkatan', $kelas->id_angkatan)
            ->whereNull('deleted_at');

        if ($kelas->id_kelompok_kelas !== null) {
            $conflictQuery->where('id_kelompok_kelas', $kelas->id_kelompok_kelas);
        } else {
            $conflictQuery->whereNull('id_kelompok_kelas');
        }

        if ($conflictQuery->exists()) {
            session()->flash('error', 'Tidak bisa memulihkan kelas ini: sudah ada kelas aktif lain dengan kombinasi kelompok kelas, mata kuliah, semester, dan angkatan yang sama.');

            return;
        }

        $kelas->restore();

        session()->flash('status', 'Kelas berhasil dipulihkan.');
        $this->resetPage();
    }

    public function confirmForceDelete(int $id): void
    {
        $this->confirmingForceDeleteId = $id;
    }

    public function cancelForceDelete(): void
    {
        $this->confirmingForceDeleteId = null;
    }

    /**
     * Tidak ada padanan di KelasController — API belum punya endpoint hapus permanen, murni fitur
     * panel. Lihat FORCE_DELETE_BLOCKERS untuk daftar tabel yang restrictOnDelete().
     */
    public function forceDeleteKelas(): void
    {
        if (! $this->confirmingForceDeleteId) {
            return;
        }

        $kelas = Kelas::onlyTrashed()->findOrFail($this->confirmingForceDeleteId);

        $user = Auth::user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $blockers = [];
        foreach (self::FORCE_DELETE_BLOCKERS as $table => $meta) {
            if (DB::table($table)->where($meta['column'], $kelas->id)->exists()) {
                $blockers[] = $meta['label'];
            }
        }

        if ($blockers !== []) {
            session()->flash('error', 'Tidak bisa menghapus permanen kelas ini: masih tercatat di data '.implode(', ', $blockers).'. Hapus atau pindahkan data itu terlebih dahulu.');
            $this->confirmingForceDeleteId = null;

            return;
        }

        $kelas->forceDelete();

        $this->confirmingForceDeleteId = null;
        session()->flash('status', 'Kelas berhasil dihapus permanen.');
        $this->resetPage();
    }

    /**
     * Urutan master semester (kode ASC), dipakai untuk menghitung "semester kuliah ke-N".
     * Sama persis dengan KelasController::semesterIdToIndexMap.
     *
     * @return array<int, int>
     */
    private function semesterIdToIndexMap(): array
    {
        $ordered = Semester::withTrashed()->orderBy('kode')->pluck('id')->values();
        $map = [];
        foreach ($ordered as $i => $semId) {
            $map[(int) $semId] = $i;
        }

        return $map;
    }

    /**
     * Sama persis dengan KelasController::applySemesterKuliahKe.
     *
     * @param  Collection<int, Kelas>  $collection
     */
    private function applySemesterKuliahKeToCollection($collection, array $map): void
    {
        foreach ($collection as $kelas) {
            $idxS = $map[(int) $kelas->id_semester] ?? null;
            $idxA = $map[(int) $kelas->id_angkatan] ?? null;
            if ($idxS === null || $idxA === null || $idxS < $idxA) {
                $kelas->setAttribute('semester_kuliah_ke', null);

                continue;
            }
            $kelas->setAttribute('semester_kuliah_ke', $idxS - $idxA + 1);
        }
    }

    /**
     * Sama persis dengan KelasController::index, plus withTrashed() opsional lewat $showTrashed
     * (tidak ada padanan di API — lihat catatan pada restore()).
     */
    public function render()
    {
        $query = Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi.jenjang',
            'semester',
            'angkatan',
            'dosenPic',
            'kelompokKelas',
        ]);

        if ($this->showTrashed) {
            $query->withTrashed();
        }

        $user = Auth::user();
        $prodiId = $this->filterProdi !== '' ? (int) $this->filterProdi : null;

        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id_prodi', $allowedProdiIds);
                if ($prodiId && ! in_array($prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->whereHas('kurikulumMatkul.matkul', function ($q) {
                    $q->where('nama', 'like', "%{$this->search}%")
                        ->orWhere('kode', 'like', "%{$this->search}%");
                })
                    ->orWhereHas('prodi', function ($q) {
                        $q->where('nama', 'like', "%{$this->search}%")
                            ->orWhere('kode', 'like', "%{$this->search}%");
                    })
                    ->orWhereHas('dosenPic', function ($q) {
                        $q->where('nama', 'like', "%{$this->search}%")
                            ->orWhere('kode_dosen', 'like', "%{$this->search}%");
                    });
            });
        }

        if ($prodiId) {
            $query->where('id_prodi', $prodiId);
        }

        if ($this->filterSemester !== '') {
            $query->where('id_semester', (int) $this->filterSemester);
        }

        if ($this->filterKelompokKelas !== '') {
            $query->where('id_kelompok_kelas', (int) $this->filterKelompokKelas);
        }

        $kelasList = $query->orderBy('id')->paginate($this->perPage);
        $this->applySemesterKuliahKeToCollection($kelasList->getCollection(), $this->semesterIdToIndexMap());

        $prodiQuery = Prodi::with('jenjang')->whereNull('deleted_at');
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $prodiQuery->whereIn('id', $allowedProdiIds);
            }
        }

        // Opsi "Kelas Mahasiswa" mengikuti prodi yang dipilih; kalau belum ada prodi terpilih,
        // tampilkan semua kelas mahasiswa (tidak disaring).
        $kelompokKelasQuery = KelompokKelas::whereNull('deleted_at');
        if ($prodiId) {
            $kelompokKelasQuery->where('id_prodi', $prodiId);
        }

        // Diselipkan ke link "Lihat"/"Ubah" supaya tombol Kembali di halaman detail/ubah bisa
        // mendarat di halaman/filter yang sama persis — lihat Kelas\Concerns\ForwardsIndexState.
        $returnParams = array_filter([
            'search' => $this->search !== '' ? $this->search : null,
            'id_prodi' => $this->filterProdi !== '' ? $this->filterProdi : null,
            'id_semester' => $this->filterSemester !== '' ? $this->filterSemester : null,
            'id_kelompok_kelas' => $this->filterKelompokKelas !== '' ? $this->filterKelompokKelas : null,
            'page' => $kelasList->currentPage() > 1 ? $kelasList->currentPage() : null,
        ], fn ($value) => $value !== null);

        // ->extends() (bukan #[Layout] attribute) — lihat catatan di App\Livewire\Admin\Fakultas\Index::render()
        return view('livewire.admin.kelas.index', [
            'kelasList' => $kelasList,
            'prodiOptions' => $prodiQuery->orderBy('nama')->get()->map(fn (Prodi $p) => (object) [
                'id' => $p->id,
                'label' => $p->jenjang?->kode ? "{$p->nama} ({$p->jenjang->kode})" : $p->nama,
            ]),
            'semesterOptions' => Semester::whereNull('deleted_at')->orderByDesc('kode')->get(['id', 'kode', 'nama'])
                ->map(fn (Semester $s) => (object) ['id' => $s->id, 'label' => "{$s->nama} ({$s->kode})"]),
            'kelompokKelasOptions' => $kelompokKelasQuery->orderBy('nama')->get(['id', 'nama']),
            'returnQuery' => http_build_query($returnParams),
        ])->extends('layouts.web');
    }
}
