<?php

namespace App\Livewire\Dosen\Kehadiran;

use App\Models\Dosen;
use App\Models\JadwalDosen;
use App\Models\Kehadiran;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Perkuliahan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Detail extends Component
{
    private const STATUS_VALUES = ['hadir', 'izin', 'sakit', 'alfa'];

    // Locked: save() memakai perkuliahanId langsung untuk menulis kehadiran — properti publik
    // biasa bisa di-override client lewat request Livewire.
    #[Locked]
    public int $perkuliahanId;

    #[Locked]
    public int $dosenId;

    /** @var array<int, array{status: string, keterangan: string}> keyed by id_mahasiswa */
    public array $form = [];

    public function mount(int $id): void
    {
        $dosen = Dosen::where('id_user', Auth::id())->firstOrFail();
        $this->dosenId = $dosen->id;

        $perkuliahan = Perkuliahan::with('jadwal.kelas')->find($id);
        abort_unless($perkuliahan && $perkuliahan->jadwal && $perkuliahan->jadwal->kelas, 404, 'Perkuliahan tidak ditemukan.');
        abort_unless($this->dosenHasAccess($perkuliahan), 403, 'Anda tidak memiliki akses ke perkuliahan ini.');

        $this->perkuliahanId = $id;

        foreach ($this->mahasiswa as $item) {
            $this->form[$item['mahasiswa']['id']] = [
                'status' => $item['kehadiran']['status'] ?? '',
                'keterangan' => $item['kehadiran']['keterangan'] ?? '',
            ];
        }
    }

    /**
     * Sama persis dengan KehadiranController::dosenBisaAksesPerkuliahan.
     */
    private function dosenHasAccess(Perkuliahan $perkuliahan): bool
    {
        $kelas = $perkuliahan->jadwal->kelas;
        if ((int) $kelas->id_dosen_pic === $this->dosenId) {
            return true;
        }

        if (KelasDosen::where('id_dosen', $this->dosenId)->where('id_kelas', $kelas->id)->whereNull('deleted_at')->exists()) {
            return true;
        }

        return JadwalDosen::where('id_jadwal', $perkuliahan->id_jadwal)
            ->where('id_dosen', $this->dosenId)
            ->where('status', 'active')
            ->exists();
    }

    #[Computed]
    public function perkuliahan(): Perkuliahan
    {
        return Perkuliahan::with([
            'jadwal.kelas.kurikulumMatkul.matkul',
            'jadwal.kelas.prodi.jenjang',
            'jadwal.kelas.semester',
        ])->findOrFail($this->perkuliahanId);
    }

    /**
     * Sama persis dengan KehadiranController::getByPerkuliahan, kecuali satu hal yang sengaja
     * berbeda: diurutkan berdasarkan NIM mahasiswa atas permintaan pengguna — API-nya sendiri
     * tidak mengurutkan sama sekali (urutan insersi KRS apa adanya).
     *
     * @return array<int, array{id_krs: int, mahasiswa: array<string, mixed>, kehadiran: array<string, mixed>|null}>
     */
    #[Computed]
    public function mahasiswa(): array
    {
        return Krs::with(['mahasiswa:id,nim,nama', 'mahasiswa.prodi:id,nama'])
            ->where('id_kelas', $this->perkuliahan->jadwal->id_kelas)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->get()
            ->sortBy(fn (Krs $krs) => $krs->mahasiswa->nim ?? '')
            ->map(function (Krs $krs) {
                $kehadiran = Kehadiran::where('id_perkuliahan', $this->perkuliahanId)
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

    /**
     * Sama persis dengan KehadiranController::storeOrUpdate — setiap mahasiswa wajib punya status
     * kehadiran yang valid sebelum bisa disimpan (tidak ada default).
     */
    public function save(): void
    {
        $mahasiswaIds = collect($this->mahasiswa)->pluck('mahasiswa.id')->all();

        $rules = [];
        foreach ($mahasiswaIds as $idMhs) {
            $rules["form.{$idMhs}.status"] = ['required', 'string', 'in:'.implode(',', self::STATUS_VALUES)];
            $rules["form.{$idMhs}.keterangan"] = ['nullable', 'string', 'max:500'];
        }

        $this->validate($rules, [
            'form.*.status.required' => 'Pilih status kehadiran (Hadir, Izin, Sakit, atau Alfa) untuk setiap mahasiswa sebelum menyimpan.',
            'form.*.status.in' => 'Pilih status kehadiran (Hadir, Izin, Sakit, atau Alfa) untuk setiap mahasiswa sebelum menyimpan.',
        ]);

        foreach ($mahasiswaIds as $idMhs) {
            Kehadiran::updateOrCreate(
                ['id_perkuliahan' => $this->perkuliahanId, 'id_mhs' => $idMhs],
                ['status' => $this->form[$idMhs]['status'], 'keterangan' => $this->form[$idMhs]['keterangan'] !== '' ? $this->form[$idMhs]['keterangan'] : null]
            );
        }

        unset($this->mahasiswa);
        session()->flash('status', 'Kehadiran berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.dosen.kehadiran.detail')->extends('layouts.dosen');
    }
}
