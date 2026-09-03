<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\PerkuliahanImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Menjalankan PerkuliahanImportService di antrian, lepas dari batas waktu eksekusi PHP per-request
 * HTTP — file besar (ratusan/ribuan baris) dulunya diproses sinkron di
 * App\Livewire\Admin\Perkuliahan\Import::import() dan bisa kena "Maximum execution time exceeded"
 * yang tampil ke user sebagai alert sesi berakhir (respons 419 di tengah request yang mati).
 */
class ImportPerkuliahanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $importBatchId) {}

    public function handle(PerkuliahanImportService $service): void
    {
        $batch = ImportBatch::find($this->importBatchId);
        if (! $batch) {
            return;
        }

        $batch->update(['status' => 'processing']);

        try {
            if (! $batch->file_path || ! Storage::disk('local')->exists($batch->file_path)) {
                throw new \RuntimeException('File import tidak ditemukan (mungkin sudah dibersihkan).');
            }

            $result = $service->run(
                Storage::disk('local')->path($batch->file_path),
                $batch->allowed_prodi_ids,
                (string) $batch->actor,
            );

            $batch->update(['status' => 'completed', 'result' => $result]);
        } catch (\Throwable $e) {
            $batch->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        } finally {
            if ($batch->file_path) {
                Storage::disk('local')->delete($batch->file_path);
            }
        }
    }
}
