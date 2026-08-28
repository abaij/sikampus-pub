<?php

namespace App\Models;

use App\Models\Concerns\MencatatPelaku;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KeringananBiaya extends Model
{
    use HasFactory, MencatatPelaku, SoftDeletes;

    protected $table = 'keringanan_biaya';

    protected $fillable = [
        'id_jenis_keringanan_biaya',
        'id_mahasiswa',
        'id_semester',
        'id_aturan_akses_keuangan',
        'nominal',
        'persentase',
        'dasar_perhitungan',
        'dasar_dihitung_pada',
        'keterangan',
        'file_lampiran',
        'status',
        'tanggal_pengajuan',
        'tanggal_approved',
        'approved_by',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $hidden = ['deleted_at'];

    protected $casts = [
        'nominal' => 'decimal:2',
        'persentase' => 'decimal:2',
        'dasar_perhitungan' => 'decimal:2',
        'dasar_dihitung_pada' => 'datetime',
        'tanggal_pengajuan' => 'datetime',
        'tanggal_approved' => 'datetime',
    ];

    protected $appends = ['file_lampiran_url'];

    public function getFileLampiranUrlAttribute(): ?string
    {
        if (empty($this->file_lampiran)) {
            return null;
        }

        $base = rtrim((string) config('app.url'), '/');

        return $base.'/storage/'.ltrim($this->file_lampiran, '/');
    }

    public function jenisKeringananBiaya(): BelongsTo
    {
        return $this->belongsTo(JenisKeringananBiaya::class, 'id_jenis_keringanan_biaya');
    }

    public function mahasiswa(): BelongsTo
    {
        return $this->belongsTo(Mahasiswa::class, 'id_mahasiswa');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class, 'id_semester');
    }

    public function aturanAksesKeuangan(): BelongsTo
    {
        return $this->belongsTo(AturanAksesKeuangan::class, 'id_aturan_akses_keuangan');
    }
}
