<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Throwable;

/**
 * Jalankan migrasi database dari panel superadmin.
 *
 * KENAPA ADA: instalasi yang diperbarui dengan cara mengganti berkas secara manual (kampus di
 * shared hosting tanpa akses shell) tetap harus menjalankan migrasi setelahnya. Tanpa halaman
 * ini, satu-satunya jalan adalah SSH — yang justru tidak dimiliki kelompok pengguna yang paling
 * membutuhkannya.
 *
 * Sengaja HANYA "migrate", tidak pernah rollback/fresh/refresh. Migrasi maju bersifat menambah
 * dan bisa dijalankan berulang tanpa efek (yang sudah jalan dilewati); perintah yang lain bisa
 * menghapus data produksi dalam satu klik, dan tidak ada alasan cukup kuat untuk menaruh
 * kemampuan itu di belakang tombol web.
 */
class SuperadminMigrasiController extends Controller
{
    public function index(): View
    {
        return view('superadmin.migrasi', $this->migrationState());
    }

    public function run(): RedirectResponse
    {
        $state = $this->migrationState();

        if ($state['pending'] === []) {
            return redirect()
                ->route('superadmin.migrasi')
                ->with('status', 'Tidak ada migrasi yang tertunda. Database sudah mutakhir.');
        }

        // Migrasi bisa memakan waktu lama pada database besar. set_time_limit() memang sering
        // dimatikan di shared hosting — kalau begitu panggilan ini tidak berpengaruh dan
        // batas dari server yang berlaku, jadi kegagalannya jangan sampai menghentikan proses.
        @set_time_limit(0);

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('superadmin.migrasi')
                ->with('error', 'Migrasi gagal: '.$e->getMessage())
                ->with('output', Artisan::output());
        }

        return redirect()
            ->route('superadmin.migrasi')
            ->with('status', 'Migrasi selesai dijalankan.')
            ->with('output', Artisan::output());
    }

    /**
     * Migrasi yang belum pernah dijalankan, dihitung dari selisih berkas migrasi yang terdaftar
     * terhadap isi tabel `migrations`.
     *
     * Repository yang belum ada (instalasi yang belum pernah migrate sama sekali) diperlakukan
     * sebagai "belum ada yang dijalankan", bukan error — justru itu keadaan yang paling butuh
     * halaman ini.
     *
     * @return array{pending: list<string>, ranCount: int, totalCount: int}
     */
    private function migrationState(): array
    {
        $migrator = app('migrator');

        // paths() berisi path yang didaftarkan lewat loadMigrationsFrom (termasuk milik plugin
        // yang sedang aktif); database/migrations sendiri tidak termasuk di sana.
        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [database_path('migrations')])
        );

        try {
            $ran = $migrator->getRepository()->repositoryExists()
                ? $migrator->getRepository()->getRan()
                : [];
        } catch (Throwable) {
            $ran = [];
        }

        $names = array_keys($files);

        return [
            'pending' => array_values(array_diff($names, $ran)),
            'ranCount' => count(array_intersect($names, $ran)),
            'totalCount' => count($names),
        ];
    }
}
