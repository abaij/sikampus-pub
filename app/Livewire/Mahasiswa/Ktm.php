<?php

namespace App\Livewire\Mahasiswa;

use App\Models\Ktm as KtmModel;
use App\Models\Mahasiswa;
use App\Models\Setting;
use App\Services\KtmImageGenerator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use RuntimeException;

class Ktm extends Component
{
    public const SETTING_KEY_KTM_TEMPLATE = 'ktm_template';

    public int $mahasiswaId;

    public ?int $ktmId = null;

    public ?string $nomorKtm = null;

    public ?string $fileUrl = null;

    public ?string $statusKtm = null;

    public bool $processing = false;

    public function mount(): void
    {
        $mahasiswa = Mahasiswa::where('id_user', Auth::id())->firstOrFail();
        $this->mahasiswaId = $mahasiswa->id;

        $this->loadKtm();
    }

    protected function loadKtm(): void
    {
        $ktm = KtmModel::where('id_mahasiswa', $this->mahasiswaId)->whereNull('deleted_at')->first();

        $this->ktmId = $ktm?->id;
        $this->nomorKtm = $ktm?->nomor_ktm;
        $this->fileUrl = $ktm?->file ? Storage::disk('public')->url($ktm->file) : null;
        $this->statusKtm = $ktm?->status;
    }

    /**
     * Sama dengan KtmController::myStore.
     */
    public function buatKtm(): void
    {
        $this->processing = true;

        if ($this->ktmId !== null) {
            $this->processing = false;

            return;
        }

        $templatePath = $this->currentTemplatePath();
        if (! $templatePath || ! Storage::disk('public')->exists($templatePath)) {
            $this->processing = false;
            session()->flash('ktm_error', 'Template KTM belum diatur oleh admin. Silakan hubungi pihak kampus.');

            return;
        }

        $mhs = Mahasiswa::query()->whereKey($this->mahasiswaId)->with(['prodi.jenjang'])->firstOrFail();

        try {
            $filePath = KtmImageGenerator::makeDefault()->generateToStorage($mhs, $templatePath);
        } catch (RuntimeException $e) {
            $this->processing = false;
            session()->flash('ktm_error', $e->getMessage());

            return;
        }

        $actor = $this->actorName();

        KtmModel::create([
            'id_mahasiswa' => $this->mahasiswaId,
            // Nomor KTM sengaja tidak diisi mahasiswa — petugas yang mengisinya lewat
            // Admin > KTM (App\Livewire\Admin\Ktm\Form).
            'nomor_ktm' => null,
            'file' => $filePath,
            'status' => 'active',
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        $this->loadKtm();
        $this->processing = false;
        session()->flash('status', 'KTM berhasil dibuat.');
    }

    /**
     * Sama dengan KtmController::myRegenerate/regenerate.
     */
    public function perbaruiKtm(): void
    {
        $this->processing = true;

        if ($this->ktmId === null) {
            $this->processing = false;

            return;
        }

        $ktm = KtmModel::findOrFail($this->ktmId);

        $templatePath = $this->currentTemplatePath();
        if (! $templatePath || ! Storage::disk('public')->exists($templatePath)) {
            $this->processing = false;
            session()->flash('ktm_error', 'Template KTM belum diatur oleh admin. Silakan hubungi pihak kampus.');

            return;
        }

        $mhs = Mahasiswa::query()->whereKey($this->mahasiswaId)->with(['prodi.jenjang'])->firstOrFail();
        $previousFile = $ktm->file;

        try {
            $filePath = KtmImageGenerator::makeDefault()->generateToStorage($mhs, $templatePath);
        } catch (RuntimeException $e) {
            $this->processing = false;
            session()->flash('ktm_error', $e->getMessage());

            return;
        }

        if ($previousFile && $previousFile !== $filePath) {
            Storage::disk('public')->delete($previousFile);
        }

        $ktm->update(['file' => $filePath, 'updated_by' => $this->actorName()]);

        $this->loadKtm();
        $this->processing = false;
        session()->flash('status', 'KTM berhasil diperbarui.');
    }

    protected function currentTemplatePath(): ?string
    {
        return Setting::query()->where('key', self::SETTING_KEY_KTM_TEMPLATE)->value('value');
    }

    protected function actorName(): string
    {
        $user = Auth::user();

        return $user ? ((string) ($user->name ?? $user->id)) : 'system';
    }

    public function render()
    {
        return view('livewire.mahasiswa.ktm')->extends('layouts.mahasiswa');
    }
}
