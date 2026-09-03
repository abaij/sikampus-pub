<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pelacak status satu proses import file yang dijalankan lewat job antrian (bukan langsung
 * sinkron di request HTTP) — dipakai supaya import file besar (mis. ratusan/ribuan baris) tidak
 * kena batas waktu eksekusi PHP per-request. Livewire meng-poll baris ini sampai status-nya
 * "completed"/"failed".
 */
class ImportBatch extends Model
{
    protected $fillable = [
        'type',
        'id_user',
        'status',
        'file_path',
        'allowed_prodi_ids',
        'actor',
        'result',
        'error_message',
    ];

    protected $casts = [
        'allowed_prodi_ids' => 'array',
        'result' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true);
    }
}
