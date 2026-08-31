<?php

namespace App\Livewire\Admin\Transkrip;

use App\Models\Setting;
use App\Services\TranskripPdfGenerator;
use Livewire\Component;

/**
 * Pengaturan → Akademik → Penandatangan Transkrip.
 *
 * Mengisi identitas pejabat yang tercetak di blok tanda tangan setiap transkrip nilai
 * (App\Services\TranskripPdfGenerator). Berlaku institusi-wide, bukan per prodi — satu transkrip
 * resmi hanya punya satu penandatangan.
 */
class Penandatangan extends Component
{
    public string $jabatan = '';

    public string $jabatanEn = '';

    public string $namaPejabat = '';

    public string $nip = '';

    public string $kotaTerbit = '';

    /** Format Y-m-d (terikat <input type="date">); kosong = transkrip memakai tanggal hari ini. */
    public string $tanggalTerbit = '';

    public function mount(): void
    {
        $pengaturan = TranskripPdfGenerator::pengaturanPenandatangan();

        $this->jabatan = $pengaturan['jabatan'];
        $this->jabatanEn = $pengaturan['jabatan_en'];
        $this->namaPejabat = $pengaturan['nama'];
        $this->nip = $pengaturan['nip'];
        $this->kotaTerbit = $pengaturan['kota_terbit'];
        $this->tanggalTerbit = $pengaturan['tanggal_terbit'];
    }

    protected function rules(): array
    {
        // Semua nullable: transkrip tetap boleh dicetak sebelum pengaturan ini diisi (kolom yang
        // kosong tercetak sebagai "-"), jadi jangan memaksa superadmin mengisi lebih dulu.
        return [
            'jabatan' => ['nullable', 'string', 'max:150'],
            'jabatanEn' => ['nullable', 'string', 'max:150'],
            'namaPejabat' => ['nullable', 'string', 'max:150'],
            'nip' => ['nullable', 'string', 'max:50'],
            'kotaTerbit' => ['nullable', 'string', 'max:100'],
            'tanggalTerbit' => ['nullable', 'date'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'jabatan' => 'jabatan',
            'jabatanEn' => 'jabatan (Inggris)',
            'namaPejabat' => 'nama pejabat',
            'nip' => 'NIP',
            'kotaTerbit' => 'kota penerbitan',
            'tanggalTerbit' => 'tanggal terbit',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $keys = TranskripPdfGenerator::KEY_PENANDATANGAN;

        $values = [
            $keys['jabatan'] => trim($this->jabatan),
            $keys['jabatan_en'] => trim($this->jabatanEn),
            $keys['nama'] => trim($this->namaPejabat),
            $keys['nip'] => trim($this->nip),
            $keys['kota_terbit'] => trim($this->kotaTerbit),
            $keys['tanggal_terbit'] => trim($this->tanggalTerbit),
        ];

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        session()->flash('status', 'Pengaturan penandatangan transkrip berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.admin.transkrip.penandatangan')->extends('layouts.web');
    }
}
