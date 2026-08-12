<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
{{-- $namaPerguruanTinggi & $logoPerguruanTinggiSrc dipasok oleh View Composer di
     AppServiceProvider::boot() (dishare ke view ini & auth/login.blade.php sekaligus). --}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'SIAK'))</title>

    @if ($logoPerguruanTinggiSrc)
        <link rel="icon" href="{{ $logoPerguruanTinggiSrc }}">
    @endif

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        {{-- Geist Sans di-self-host lewat paket npm geist (bukan CDN) — hanya tersedia setelah asset
             Vite di-build, jadi fallback ini pakai stack sans generik saja. --}}
        <style>
            body { font-family: ui-sans-serif, system-ui, sans-serif; }
        </style>
    @endif

    <script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="min-h-screen bg-white text-neutral-900 antialiased">
@include('partials.impersonate-banner')
@php
    // Panel admin (Livewire) punya dashboard & logout sendiri; dosen/mahasiswa masing-masing
    // punya dashboard sendiri tapi berbagi mekanisme logout generik dengan panel maintenance
    // superadmin ("dashboard"/"logout").
    $dashboardRouteName = match (true) {
        request()->routeIs('admin.*') => 'admin.dashboard',
        request()->routeIs('dosen.*') => 'dosen.dashboard',
        request()->routeIs('mahasiswa.*') => 'mahasiswa.dashboard',
        default => 'dashboard',
    };
    $logoutRouteName = request()->routeIs('admin.*') ? 'admin.logout' : 'logout';
    // Halaman maintenance superadmin ('dashboard', 'superadmin.*') tidak punya area sendiri —
    // usernya selalu berperan admin juga, jadi profilnya dituju ke admin.profil.
    $profilRouteName = match (true) {
        request()->routeIs('dosen.*') => 'dosen.profil',
        request()->routeIs('mahasiswa.*') => 'mahasiswa.profil',
        default => 'admin.profil',
    };
    $authUser = auth()->user();
    $userInitials = $authUser
        ? (collect(explode(' ', trim((string) $authUser->name)))
            ->filter()
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->take(2)
            ->implode('') ?: '?')
        : '?';
@endphp
@hasSection('header_title')
<div class="min-h-screen">
    <header class="relative print:hidden border-b border-neutral-200 bg-white shadow-sm">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-4 gap-y-3 px-4 py-4 sm:px-6">
            <a
                href="{{ route($dashboardRouteName) }}"
                class="flex min-w-0 shrink-0 items-center gap-3"
                title="Dashboard"
            >
                @if ($logoPerguruanTinggiSrc)
                    <img
                        src="{{ $logoPerguruanTinggiSrc }}"
                        alt="{{ $namaPerguruanTinggi !== '' ? $namaPerguruanTinggi : 'Sikampus' }}"
                        class="h-10 w-10 shrink-0 rounded-xl bg-white object-contain shadow-border"
                    />
                @else
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-neutral-900 text-white transition hover:bg-neutral-800">
                        <i data-lucide="graduation-cap" class="h-5 w-5" aria-hidden="true"></i>
                    </div>
                @endif
                <div class="min-w-0">
                    <span class="block truncate text-sm font-semibold tracking-tight text-neutral-900">
                        {{ $namaPerguruanTinggi !== '' ? $namaPerguruanTinggi : 'Sikampus' }}
                    </span>
                    @if ($semesterAktif)
                        <span class="block truncate text-xs text-neutral-500">{{ $semesterAktif }}</span>
                    @endif
                </div>
            </a>
            <nav class="flex flex-wrap items-center gap-1 sm:gap-2" aria-label="Utama">
                @if (request()->routeIs('dosen.*') || request()->routeIs('mahasiswa.*'))
                    {{-- Dosen/mahasiswa belum punya menu navigasi sendiri. --}}
                @else
                    @hasSection('nav')
                        @yield('nav')
                    @else
                        <a
                            href="{{ route('superadmin.konfigurasi') }}"
                            class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('superadmin.konfigurasi') ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
                        >
                            Konfigurasi
                        </a>
                        <a
                            href="{{ route('superadmin.migrasi') }}"
                            class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('superadmin.migrasi') ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
                        >
                            Migrasi
                        </a>
                        <a
                            href="{{ route('superadmin.test-upload') }}"
                            class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('superadmin.test-upload') ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
                        >
                            Test Upload
                        </a>
                    @endif
                @endif
            </nav>
            <div class="flex shrink-0 items-center">
                {{-- Persistent di semua halaman (dulu diulang per-halaman lewat header_actions) —
                     dropdown pakai CSS hover (bukan Alpine), sama seperti nav.blade.php, karena
                     layout ini dipakai baik oleh halaman Livewire maupun Blade biasa dan Livewire
                     hanya menyuntikkan Alpine ke halaman yang benar-benar memuat komponen Livewire. --}}
                <div class="group/user relative">
                    <button
                        type="button"
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white transition hover:bg-neutral-800 focus:outline-none focus:ring-2 focus:ring-neutral-900 focus:ring-offset-2"
                        title="{{ $authUser->name ?? '' }}"
                    >
                        {{ $userInitials }}
                    </button>
                    <div class="invisible absolute right-0 top-full z-20 w-56 pt-1 opacity-0 transition-opacity duration-100 group-hover/user:visible group-hover/user:opacity-100">
                        <div class="rounded-lg bg-white py-1 shadow-border-lg">
                            <div class="border-b border-neutral-100 px-3 py-2">
                                <p class="truncate text-sm font-semibold text-neutral-900">{{ $authUser->name ?? '' }}</p>
                                <p class="truncate text-xs text-neutral-500">{{ $authUser->email ?? '' }}</p>
                            </div>
                            <a
                                href="{{ route($profilRouteName) }}"
                                class="flex items-center gap-2 px-3 py-2 text-sm text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-900"
                            >
                                <i data-lucide="user" class="h-4 w-4" aria-hidden="true"></i>
                                Profil
                            </a>
                            <button
                                type="button"
                                onclick="document.getElementById('confirm-logout-modal').showModal()"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-600 transition hover:bg-rose-50"
                            >
                                <i data-lucide="log-out" class="h-4 w-4" aria-hidden="true"></i>
                                Keluar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    {{-- Modal konfirmasi logout — <dialog> native + vanilla JS, tanpa dependensi Alpine/Livewire
         supaya tetap berfungsi di halaman Blade biasa (dashboard superadmin, dsb). --}}
    {{-- Posisi lewat fixed+translate (bukan margin:auto bawaan <dialog>) karena preflight Tailwind
         me-reset margin semua elemen termasuk dialog, yang akan membatalkan centering otomatis browser. --}}
    <dialog id="confirm-logout-modal" class="fixed top-1/2 left-1/2 m-0 w-full max-w-sm -translate-x-1/2 -translate-y-1/2 rounded-2xl p-0 shadow-border-lg backdrop:bg-neutral-900/40">
        <div class="p-6">
            <h3 class="text-base font-semibold text-neutral-900">Keluar dari akun?</h3>
            <p class="mt-2 text-sm text-neutral-600">Anda perlu masuk kembali untuk mengakses halaman ini.</p>
            <div class="mt-6 flex justify-end gap-2">
                <button
                    type="button"
                    onclick="document.getElementById('confirm-logout-modal').close()"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border"
                >
                    Batal
                </button>
                <form method="post" action="{{ route($logoutRouteName) }}">
                    @csrf
                    <button
                        type="submit"
                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700"
                    >
                        Ya, Keluar
                    </button>
                </form>
            </div>
        </div>
    </dialog>
    <main class="mx-auto max-w-7xl px-4 py-10 sm:px-6">
        @hasSection('breadcrumb')
            <div class="mb-4">
                @yield('breadcrumb')
            </div>
        @endif

        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-neutral-100 text-neutral-900">
                    <i data-lucide="@yield('header_icon', 'layout-dashboard')" class="h-5 w-5" aria-hidden="true"></i>
                </div>
                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold tracking-tight text-balance text-neutral-900">@yield('header_title')</h1>
                    @hasSection('header_subtitle')
                    <p class="mt-1 text-sm text-neutral-500">@yield('header_subtitle')</p>
                    @endif
                </div>
            </div>
            @hasSection('page_actions')
                <div class="flex shrink-0 items-center gap-2">
                    @yield('page_actions')
                </div>
            @endif
        </div>

        @yield('content')
    </main>
</div>
@else
@yield('content')
@endif

<footer class="print:hidden border-t border-neutral-100 px-4 py-6 text-center text-xs text-neutral-500 sm:px-6">
    &copy; {{ date('Y') }} {{ config('app.name') }}
    @if ($namaPerguruanTinggi)
        &middot; {{ $namaPerguruanTinggi }}
    @endif
</footer>

<script>
    function renderLucideIcons() {
        if (typeof lucide !== 'undefined' && lucide.createIcons) {
            lucide.createIcons();
        }
    }

    document.addEventListener('DOMContentLoaded', renderLucideIcons);

    // Livewire hanya mengonversi <i data-lucide="..."> jadi SVG saat page load penuh.
    // Baris baru yang muncul lewat update AJAX (mis. klik pagination, search reaktif)
    // tidak pernah ter-render jadi ikon sampai halaman di-refresh manual — perbaiki
    // dengan menjalankan ulang createIcons() setiap kali Livewire selesai morph DOM.
    // morph.updated saja tidak cukup: elemen yang sama sekali baru (mis. modal yang baru
    // pertama kali muncul karena suatu properti berubah dari null) memicu morph.added,
    // bukan morph.updated — tanpa hook ini ikonnya baru muncul di render berikutnya.
    document.addEventListener('livewire:init', function () {
        Livewire.hook('morph.updated', renderLucideIcons);
        Livewire.hook('morph.added', renderLucideIcons);

        // Setiap aksi Livewire (submit form, klik tombol, dsb) yang gagal karena sesi/token
        // kedaluwarsa (419) ditangani di sini — berlaku untuk semua komponen Livewire di
        // seluruh aplikasi, bukan per halaman. Livewire sendiri sebenarnya sudah punya
        // penanganan bawaan untuk ini (confirm() berbahasa Inggris), tapi kita ganti dengan
        // pesan Bahasa Indonesia yang konsisten dengan resources/views/errors/419.blade.php
        // (dipakai form biasa/non-Livewire seperti login dan konfigurasi superadmin).
        let sessionExpiredAlertShown = false;
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status !== 419 || sessionExpiredAlertShown) {
                    return;
                }
                sessionExpiredAlertShown = true;
                preventDefault();
                alert('Sesi Anda sudah berakhir. Halaman akan dimuat ulang, silakan coba lagi.');
                window.location.reload();
            });
        });
    });

    document.addEventListener('livewire:navigated', renderLucideIcons);
</script>
@stack('scripts')
</body>
</html>
