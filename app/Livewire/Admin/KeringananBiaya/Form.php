<?php

namespace App\Livewire\Admin\KeringananBiaya;

use App\Models\AturanAksesKeuangan;
use App\Models\JenisKeringananBiaya;
use App\Models\KeringananBiaya;
use App\Models\Mahasiswa;
use App\Models\Semester;
use App\Services\KeringananBiayaPersentaseService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class Form extends Component
{
    use WithFileUploads;

    public ?int $keringananBiayaId = null;

    // Mahasiswa dikunci (tidak bisa diganti) begitu dibuat, sama seperti halaman edit di
    // frontend — cari-lalu-pilih hanya tersedia di mode tambah.
    public string $mahasiswaSearch = '';

    public ?int $id_mahasiswa = null;

    public string $selectedMahasiswaLabel = '';

    // FK boleh ?int karena diikat lewat <x-searchable-select> (entangle), bukan <select> polos.
    public ?int $id_jenis_keringanan_biaya = null;

    public ?int $id_semester = null;

    public ?int $id_aturan_akses_keuangan = null;

    // Terikat <input type="number">/<input type="date">, bukan <select> — tetap string karena
    // input kosong mengirim "" yang tidak bisa dikonversi PHP ke properti typed int/float.
    public string $nominal = '';

    public string $keterangan = '';

    public string $status = 'pending';

    public string $tanggal_pengajuan = '';

    public $fileLampiranUpload = null;

    public ?string $existingFileLampiran = null;

    public ?string $existingFileLampiranUrl = null;

    public function mount(?int $id = null): void
    {
        $this->keringananBiayaId = $id;

        if ($id === null) {
            $this->tanggal_pengajuan = now()->format('Y-m-d');

            return;
        }

        $keringananBiaya = KeringananBiaya::with('mahasiswa')->findOrFail($id);

        $this->id_mahasiswa = $keringananBiaya->id_mahasiswa;
        $this->selectedMahasiswaLabel = $keringananBiaya->mahasiswa
            ? "{$keringananBiaya->mahasiswa->nama} ({$keringananBiaya->mahasiswa->nim})"
            : '';
        $this->id_jenis_keringanan_biaya = $keringananBiaya->id_jenis_keringanan_biaya;
        $this->id_semester = $keringananBiaya->id_semester;
        $this->id_aturan_akses_keuangan = $keringananBiaya->id_aturan_akses_keuangan;
        $this->nominal = (string) $keringananBiaya->nominal;
        $this->keterangan = (string) $keringananBiaya->keterangan;
        $this->status = $keringananBiaya->status ?? 'pending';
        $this->tanggal_pengajuan = $keringananBiaya->tanggal_pengajuan?->format('Y-m-d') ?? '';
        $this->existingFileLampiran = $keringananBiaya->file_lampiran;
        $this->existingFileLampiranUrl = $keringananBiaya->file_lampiran_url;
    }

    /**
     * Cari-lalu-pilih mahasiswa — mirip KategoriBiaya\Show::mahasiswaResults, hanya dipakai di
     * mode tambah karena mahasiswa tidak bisa diganti setelah pengajuan dibuat.
     */
    #[Computed]
    public function mahasiswaResults()
    {
        if ($this->mahasiswaSearch === '') {
            return collect();
        }

        return Mahasiswa::query()
            ->where(function ($q) {
                $q->where('nama', 'like', "%{$this->mahasiswaSearch}%")
                    ->orWhere('nim', 'like', "%{$this->mahasiswaSearch}%");
            })
            ->orderBy('nama')
            ->limit(20)
            ->get(['id', 'nama', 'nim']);
    }

    public function selectMahasiswa(int $id, string $label): void
    {
        $this->id_mahasiswa = $id;
        $this->selectedMahasiswaLabel = $label;
        $this->mahasiswaSearch = '';
    }

    public function clearMahasiswa(): void
    {
        $this->id_mahasiswa = null;
        $this->selectedMahasiswaLabel = '';
    }

    /**
     * Persen master jenis yang sedang dipilih, atau null kalau jenisnya bertipe rupiah.
     * Nominal untuk jenis persentase ditentukan sistem saat approve, bukan diketik admin.
     */
    #[Computed]
    public function persentaseTerpilih(): ?float
    {
        return KeringananBiayaPersentaseService::persentaseMaster($this->id_jenis_keringanan_biaya);
    }

    /** Perkiraan rupiah yang akan tersimpan saat disetujui, untuk ditampilkan di form. */
    #[Computed]
    public function perkiraanNominal(): ?array
    {
        $persen = $this->persentaseTerpilih;
        if ($persen === null || ! $this->id_mahasiswa || ! $this->id_semester) {
            return null;
        }

        $dasar = KeringananBiayaPersentaseService::dasarPerhitungan($this->id_mahasiswa, $this->id_semester);

        return ['persen' => $persen, 'dasar' => $dasar, 'nominal' => round($dasar * $persen / 100, 2)];
    }

    #[Computed]
    public function jenisKeringananBiayaOptions(): array
    {
        return JenisKeringananBiaya::query()
            ->where(function ($q) {
                $q->where('is_active', true);
                if ($this->id_jenis_keringanan_biaya) {
                    $q->orWhere('id', $this->id_jenis_keringanan_biaya);
                }
            })
            ->orderBy('nama')
            ->pluck('nama', 'id')
            ->all();
    }

    #[Computed]
    public function semesterOptions(): array
    {
        return Semester::orderByDesc('kode')
            ->get(['id', 'nama', 'kode'])
            ->mapWithKeys(fn ($s) => [$s->id => $s->kode ? "{$s->nama} ({$s->kode})" : $s->nama])
            ->all();
    }

    #[Computed]
    public function aturanAksesKeuanganOptions(): array
    {
        return AturanAksesKeuangan::query()
            ->where(function ($q) {
                $q->where('status', 'active');
                if ($this->id_aturan_akses_keuangan) {
                    $q->orWhere('id', $this->id_aturan_akses_keuangan);
                }
            })
            ->orderBy('nama')
            ->pluck('nama', 'id')
            ->all();
    }

    public function statusOptions(): array
    {
        return [
            'pending' => 'Menunggu',
            'approved' => 'Disetujui',
            'rejected' => 'Ditolak',
        ];
    }

    /**
     * Sama persis dengan KeringananBiayaController::validateStore/validateUpdate.
     */
    protected function rules(): array
    {
        return [
            'id_mahasiswa' => ['required', 'integer', 'exists:mahasiswa,id'],
            'id_jenis_keringanan_biaya' => ['required', 'integer', 'exists:jenis_keringanan_biaya,id'],
            'id_semester' => ['required', 'integer', 'exists:semester,id'],
            'id_aturan_akses_keuangan' => ['nullable', 'integer', 'exists:aturan_akses_keuangan,id'],
            // Jenis persentase: nominal dihitung sistem saat approve, jadi isian admin diabaikan.
            'nominal' => $this->persentaseTerpilih !== null
                ? ['nullable', 'numeric', 'min:0']
                : ['required', 'numeric', 'min:0'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'tanggal_pengajuan' => ['nullable', 'date'],
            'fileLampiranUpload' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }

    protected function messages(): array
    {
        return [
            'id_mahasiswa.required' => 'Mahasiswa harus dipilih.',
            'id_jenis_keringanan_biaya.required' => 'Jenis keringanan biaya harus dipilih.',
            'id_semester.required' => 'Semester harus dipilih.',
            'nominal.required' => 'Nominal harus diisi.',
            'fileLampiranUpload.mimes' => 'Lampiran harus berformat PDF, JPG, JPEG, PNG, atau WEBP.',
            'fileLampiranUpload.max' => 'Ukuran lampiran maksimal 5 MB.',
        ];
    }

    /**
     * Sama persis dengan KeringananBiayaController::applyStatusFields.
     */
    private function applyStatusFields(KeringananBiaya $row, string $status): ?string
    {
        $row->status = $status;
        if ($status === 'approved') {
            $gagal = KeringananBiayaPersentaseService::terapkanSaatApprove($row);
            if ($gagal !== null) {
                return $gagal;
            }
            $row->tanggal_approved = now();
            $user = Auth::user();
            $row->approved_by = $user?->name ?? (string) $user?->id;
        } else {
            $row->tanggal_approved = null;
            $row->approved_by = null;
        }

        return null;
    }

    /**
     * Sama persis dengan KeringananBiayaController::store/update — termasuk cek duplikat
     * kombinasi jenis+mahasiswa+semester dan penyimpanan/penggantian file lampiran.
     */
    public function save()
    {
        $validated = $this->validate();

        if ($validated['keterangan'] === '') {
            $validated['keterangan'] = null;
        }

        $exists = KeringananBiaya::where('id_jenis_keringanan_biaya', $validated['id_jenis_keringanan_biaya'])
            ->where('id_mahasiswa', $validated['id_mahasiswa'])
            ->where('id_semester', $validated['id_semester'])
            ->when($this->keringananBiayaId, fn ($q) => $q->where('id', '!=', $this->keringananBiayaId))
            ->exists();

        if ($exists) {
            $this->addError('id_jenis_keringanan_biaya', 'Sudah ada keringanan untuk kombinasi jenis, mahasiswa, dan semester ini.');

            return;
        }

        $user = Auth::user();
        $userName = $user?->name ?? (string) $user?->id;

        if ($this->keringananBiayaId) {
            $row = KeringananBiaya::findOrFail($this->keringananBiayaId);
        } else {
            $row = new KeringananBiaya;
            $row->tanggal_pengajuan = $validated['tanggal_pengajuan'] ?: now();
            $row->created_by = $userName;
        }

        $row->id_mahasiswa = $validated['id_mahasiswa'];
        $row->id_jenis_keringanan_biaya = $validated['id_jenis_keringanan_biaya'];
        $row->id_semester = $validated['id_semester'];
        $row->id_aturan_akses_keuangan = $validated['id_aturan_akses_keuangan'];
        $persenTerpilih = $this->persentaseTerpilih;
        if ($persenTerpilih !== null) {
            // Snapshot persen diambil dari master saat baris dibuat/jenisnya diganti; nominalnya
            // diisi KeringananBiayaPersentaseService saat status jadi approved.
            $row->persentase = $persenTerpilih;
            $row->nominal = $row->nominal ?? 0;
        } else {
            $row->persentase = null;
            $row->dasar_perhitungan = null;
            $row->dasar_dihitung_pada = null;
            $row->nominal = $validated['nominal'];
        }
        $row->keterangan = $validated['keterangan'];

        if ($this->keringananBiayaId && $validated['tanggal_pengajuan']) {
            $row->tanggal_pengajuan = $validated['tanggal_pengajuan'];
        }

        if ($this->fileLampiranUpload) {
            if ($row->file_lampiran && Storage::disk('public')->exists($row->file_lampiran)) {
                Storage::disk('public')->delete($row->file_lampiran);
            }
            $row->file_lampiran = $this->fileLampiranUpload->store('keringanan-biaya', 'public');
        }

        if ($this->keringananBiayaId) {
            $row->updated_by = $userName;
        }

        $gagalStatus = $this->applyStatusFields($row, $validated['status']);
        if ($gagalStatus !== null) {
            $this->addError('status', $gagalStatus);

            return;
        }
        $row->save();

        session()->flash('status', 'Keringanan biaya berhasil disimpan.');

        return redirect()->route('admin.keuangan.keringanan-biaya');
    }

    public function render()
    {
        return view('livewire.admin.keringanan-biaya.form')->extends('layouts.web');
    }
}
