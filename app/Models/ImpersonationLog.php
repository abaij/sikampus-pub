<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jejak audit fitur "Login as" (App\Http\Controllers\Web\ImpersonateController) — satu baris per
 * sesi impersonate, dibuat saat admin mulai login-as dan diisi `ended_at` saat kembali (baik lewat
 * tombol "Kembali ke admin" maupun auto-expire di App\Http\Middleware\EnforceImpersonationTimeout).
 * Perlu ada terpisah dari kolom updated_at biasa karena perubahan data yang dilakukan admin saat
 * impersonate tercatat sebagai milik target user, bukan admin — tanpa log ini tidak ada cara
 * membedakan "mahasiswa mengubah datanya sendiri" dari "admin mengubah atas nama mahasiswa".
 */
class ImpersonationLog extends Model
{
    protected $fillable = [
        'id_admin',
        'id_target_user',
        'ip_address',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'ended_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_admin');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_target_user');
    }
}
