<?php

use App\Http\Middleware\EnforceImpersonationTimeout;
use App\Http\Middleware\EnsurePanelPermission;
use App\Http\Middleware\EnsurePartnerApiKey;
use App\Http\Middleware\EnsureUserHasKeuanganAccess;
use App\Http\Middleware\EnsureUserHasSuperadminAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsAdminProdi;
use App\Http\Middleware\EnsureUserIsAdminProdiWeb;
use App\Http\Middleware\EnsureUserIsAdminWeb;
use App\Http\Middleware\EnsureUserIsDosen;
use App\Http\Middleware\EnsureUserIsDosenWeb;
use App\Http\Middleware\EnsureUserIsMahasiswa;
use App\Http\Middleware\EnsureUserIsMahasiswaWeb;
use App\Http\Middleware\EnsureUserIsSuperadmin;
use App\Http\Middleware\EnsureUserIsSuperadminWeb;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Untuk request API yang belum login, jangan panggil route('login') (tidak ada di API)
        // sehingga tidak terjadi RouteNotFoundException. Redirect = null → nanti exception
        // handler mengembalikan 401 JSON berkat shouldRenderJsonWhen.
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('api/*')) {
                return null;
            }

            return route('login');
        });

        // Aktifkan middleware stateful API agar Sanctum bisa memproses
        // permintaan berbasis cookie (SPA) maupun bearer token.
        $middleware->statefulApi();

        // Wizard pembaruan menyalakan mode pemeliharaan tepat sebelum menukar berkas aplikasi,
        // lalu MASIH perlu menjalankan langkah-langkah berikutnya (migrasi, bersihkan cache,
        // matikan pemeliharaan) lewat request berikutnya. Tanpa pengecualian ini, mode
        // pemeliharaan mengunci halaman yang sedang menjalankan pembaruan itu sendiri, dan
        // satu-satunya jalan keluar adalah menghapus storage/framework/down lewat shell —
        // persis yang tidak dimiliki pengguna yang paling membutuhkan wizard ini.
        //
        // Aman karena seluruh rute di bawahnya tetap dijaga middleware superadmin.web.
        $middleware->preventRequestsDuringMaintenance(except: [
            'pembaruan',
            'pembaruan/*',
        ]);

        // Pastikan CORS middleware aktif untuk semua request
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Aktifkan CORS middleware untuk API routes
        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        // Aktifkan CORS middleware untuk web routes juga (untuk preflight requests), plus
        // auto-expire sesi "Login as" (lihat App\Http\Middleware\EnforceImpersonationTimeout) —
        // global karena harus jalan di rute admin, dosen, maupun mahasiswa.
        $middleware->web(append: [
            HandleCors::class,
            EnforceImpersonationTimeout::class,
        ]);

        // Daftarkan alias untuk middleware role-based
        $middleware->alias([
            'guest' => RedirectIfAuthenticated::class,
            'role.admin' => EnsureUserIsAdmin::class,
            'role.admin.web' => EnsureUserIsAdminWeb::class,
            'panel.permission' => EnsurePanelPermission::class,
            'role.superadmin' => EnsureUserIsSuperadmin::class,
            'role.admin.keuangan' => EnsureUserHasKeuanganAccess::class,
            'role.admin.superadmin' => EnsureUserHasSuperadminAccess::class,
            'role.admin.prodi' => EnsureUserIsAdminProdi::class,
            'role.admin.prodi.web' => EnsureUserIsAdminProdiWeb::class,
            'role.mahasiswa' => EnsureUserIsMahasiswa::class,
            'role.dosen' => EnsureUserIsDosen::class,
            'role.mahasiswa.web' => EnsureUserIsMahasiswaWeb::class,
            'role.dosen.web' => EnsureUserIsDosenWeb::class,
            'partner.api.key' => EnsurePartnerApiKey::class,
            'superadmin.web' => EnsureUserIsSuperadminWeb::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Request ke API selalu dapat response JSON (termasuk 401 Unauthenticated),
        // agar tidak redirect ke route('login') yang tidak ada di API.
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
