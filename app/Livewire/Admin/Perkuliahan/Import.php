<?php

namespace App\Livewire\Admin\Perkuliahan;

use App\Jobs\ImportPerkuliahanJob;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Import Perkuliahan dijalankan lewat job antrian (App\Jobs\ImportPerkuliahanJob), bukan sinkron
 * di request ini — file besar (ratusan/ribuan baris) memproses banyak query per baris dan bisa
 * kena batas waktu eksekusi PHP per-request kalau dijalankan langsung di sini (pernah terjadi:
 * "Maximum execution time of 30 seconds exceeded" di storage/logs/laravel.log, yang ke user
 * tampil sebagai alert sesi berakhir karena request-nya mati di tengah jalan). Alur baru: upload
 * → simpan file ke disk → buat baris ImportBatch → dispatch job → halaman poll status tiap
 * beberapa detik sampai job selesai.
 */
class Import extends Component
{
    use WithFileUploads;

    public $file = null;

    public ?int $batchId = null;

    public string $status = '';

    public ?array $result = null;

    public ?string $jobError = null;

    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }

    /**
     * Kalau halaman ini dibuka ulang (mis. refresh browser) sementara batch sebelumnya dari user
     * yang sama masih berjalan, sambung lagi pelacakannya alih-alih kembali ke form kosong —
     * poin utama pemindahan ke job antrian adalah supaya refresh/putus koneksi tidak bikin user
     * kehilangan jejak hasil importnya.
     */
    public function mount(): void
    {
        $userId = Auth::id();
        if (! $userId) {
            return;
        }

        $latest = ImportBatch::where('type', 'perkuliahan')
            ->where('id_user', $userId)
            ->whereIn('status', ['pending', 'processing'])
            ->latest('id')
            ->first();

        if ($latest) {
            $this->batchId = $latest->id;
            $this->status = $latest->status;
        }
    }

    public function import(): void
    {
        $this->result = null;
        $this->jobError = null;
        $this->validate();

        $user = Auth::user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;
        $actor = $user ? (string) ($user->email ?? $user->id) : 'import';

        $storedPath = $this->file->store('imports/perkuliahan', 'local');

        $batch = ImportBatch::create([
            'type' => 'perkuliahan',
            'id_user' => $user?->id,
            'status' => 'pending',
            'file_path' => $storedPath,
            'allowed_prodi_ids' => $allowedProdiIds,
            'actor' => $actor,
        ]);

        ImportPerkuliahanJob::dispatch($batch->id);

        $this->batchId = $batch->id;
        $this->status = 'pending';
        $this->reset('file');
    }

    /**
     * Dipanggil berkala lewat wire:poll selama batch belum selesai — lihat import.blade.php.
     */
    public function poll(): void
    {
        if (! $this->batchId) {
            return;
        }

        $batch = ImportBatch::find($this->batchId);
        if (! $batch) {
            $this->batchId = null;
            $this->status = '';

            return;
        }

        $this->status = $batch->status;

        if ($batch->status === 'completed') {
            $this->result = $batch->result;
            $this->batchId = null;
        } elseif ($batch->status === 'failed') {
            $this->jobError = $batch->error_message ?? 'Terjadi kesalahan saat mengimport data.';
            $this->batchId = null;
        }
    }

    /**
     * Kembali ke form kosong untuk mengimport file lain, setelah hasil/error sebelumnya sudah dilihat.
     */
    public function resetImport(): void
    {
        $this->reset('batchId', 'status', 'result', 'jobError');
    }

    public function render()
    {
        return view('livewire.admin.perkuliahan.import')->extends('layouts.web');
    }
}
