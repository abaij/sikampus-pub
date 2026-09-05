<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu percobaan pembaruan aplikasi, berikut jejak langkahnya.
 *
 * Dicatat di database (bukan sekadar sesi) karena pembaruan berlangsung lintas beberapa
 * request: unduh, verifikasi, dan ekstrak masing-masing request sendiri supaya tidak menabrak
 * max_execution_time. Tanpa state yang bertahan, menutup tab di tengah proses akan
 * meninggalkan pekerjaan setengah jadi yang tidak bisa dilacak siapa pun.
 */
class UpdateRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const PATH_ARCHIVE = 'archive';

    public const PATH_GIT = 'git';

    /**
     * Urutan langkah per jalur. `status` hanya menyimpan keadaan akhir (berjalan/berhasil/gagal),
     * sedangkan `step` menyimpan langkah yang akan dikerjakan berikutnya — memisahkan keduanya
     * membuat baris yang gagal tetap menunjukkan di mana persisnya kegagalan terjadi.
     *
     * Langkah yang MENGUBAH berkas hidup dimulai dari 'swap' (arsip) dan 'checkout' (git);
     * semua langkah sebelumnya hanya menyentuh direktori kerja.
     */
    public const STEPS = [
        self::PATH_ARCHIVE => ['download', 'verify', 'extract', 'swap', 'finalize'],
        self::PATH_GIT => ['fetch', 'checkout', 'dependencies', 'finalize'],
    ];

    public const MUTATING_STEP = [
        self::PATH_ARCHIVE => 'swap',
        self::PATH_GIT => 'checkout',
    ];

    protected $fillable = [
        'version_from',
        'version_to',
        'path',
        'status',
        'step',
        'error_message',
        'log',
        'workspace_path',
        'backup_path',
        'id_user',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    /**
     * Sedang berjalan = belum sampai keadaan akhir. Dipakai untuk mencegah dua pembaruan
     * berjalan bersamaan, yang akan saling menimpa direktori staging dan backup.
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * @return list<string>
     */
    public function steps(): array
    {
        return self::STEPS[$this->path] ?? [];
    }

    /**
     * Langkah berikutnya setelah yang sekarang, atau null kalau ini yang terakhir.
     */
    public function nextStep(): ?string
    {
        $steps = $this->steps();
        $index = array_search($this->step, $steps, true);

        if ($index === false) {
            return $steps[0] ?? null;
        }

        return $steps[$index + 1] ?? null;
    }

    /**
     * True kalau proses sudah melewati titik di mana berkas hidup mulai diubah. Dipakai UI untuk
     * memutuskan apakah membatalkan masih aman — setelah titik ini, "batal" berarti meninggalkan
     * instalasi setengah tertukar, jadi tombolnya tidak boleh ditawarkan.
     */
    public function hasStartedMutating(): bool
    {
        $steps = $this->steps();
        $mutating = self::MUTATING_STEP[$this->path] ?? null;

        $current = array_search($this->step, $steps, true);
        $threshold = array_search($mutating, $steps, true);

        return $current !== false && $threshold !== false && $current >= $threshold;
    }

    /**
     * Tambahkan satu baris ke jejak langkah, selalu bertanda waktu. Menulis langsung ke kolom
     * (bukan menumpuk di memori) supaya jejaknya tetap ada walau proses mati di tengah — justru
     * itu keadaan yang paling butuh dibaca.
     */
    public function appendLog(string $line): void
    {
        $this->update([
            'log' => trim(($this->log ?? '')."\n".now()->format('H:i:s').'  '.$line),
        ]);
    }

    public function markFailed(string $message, string $status = self::STATUS_FAILED): void
    {
        $this->update([
            'status' => $status,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        $this->appendLog('GAGAL: '.$message);
    }
}
