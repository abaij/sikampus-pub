<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
{{-- Layout khusus area dosen (sidebar kiri), terpisah dari layouts.web (navbar atas) yang
     dipakai panel admin/mahasiswa/superadmin. $namaPerguruanTinggi & $logoPerguruanTinggiSrc
     dipasok View Composer yang sama di AppServiceProvider::boot(). --}}
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
        <style>
            body { font-family: ui-sans-serif, system-ui, sans-serif; }
        </style>
    @endif

    <script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900 antialiased">
@include('partials.impersonate-banner')
@php
    $authUser = auth()->user();
    $userInitials = $authUser
        ? (collect(explode(' ', trim((string) $authUser->name)))
            ->filter()
            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
            ->take(2)
            ->implode('') ?: '?')
        : '?';
@endphp

{{-- Toggle sidebar mobile lewat checkbox murni (tanpa Alpine) — konsisten dengan filosofi
     layouts.web bahwa navigasi persisten tidak boleh bergantung pada Livewire/Alpine, karena
     tidak semua halaman dosen tentu memuat komponen Livewire. --}}
<div class="min-h-screen lg:flex">
    {{-- Checkbox harus jadi sibling langsung dari elemen yang dituju oleh peer-checked:* (aside,
         backdrop) — Tailwind memakai general sibling combinator (~), yang tidak menembus level
         DOM. Sebelumnya checkbox ini diletakkan di luar <div>, jadi peer-checked:* di atas tidak
         pernah menyala; jangan pindahkan lagi ke luar div ini. --}}
    <input type="checkbox" id="dosen-sidebar-toggle" class="peer hidden">

    <label
        for="dosen-sidebar-toggle"
        class="fixed top-4 left-4 z-50 flex h-10 w-10 cursor-pointer items-center justify-center rounded-lg bg-white shadow-border lg:hidden"
    >
        <i data-lucide="menu" class="h-5 w-5 text-neutral-700" aria-hidden="true"></i>
    </label>

    <label
        for="dosen-sidebar-toggle"
        class="fixed inset-0 z-30 hidden bg-neutral-900/40 peer-checked:block lg:hidden"
    ></label>

    {{-- lg:sticky + lg:h-screen (bukan lg:static) supaya aside berhenti mengikuti tinggi konten
         dan tetap terkunci ke viewport saat desktop — tanpa ini, nav di dalamnya yang panjang
         (banyak menu/submenu) mendorong tinggi aside ikut tumbuh, dan overflow-y-auto pada <nav>
         tidak pernah aktif karena aside sendiri tidak dibatasi tingginya, sehingga seluruh
         halaman ikut discroll alih-alih hanya kontennya. --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-neutral-200 bg-white transition-transform duration-200 ease-in-out peer-checked:translate-x-0 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:self-start">
        <div class="flex items-center gap-3 border-b border-neutral-200 p-5">
            @if ($logoPerguruanTinggiSrc)
                <img
                    src="{{ $logoPerguruanTinggiSrc }}"
                    alt="{{ $namaPerguruanTinggi !== '' ? $namaPerguruanTinggi : 'Sikampus' }}"
                    class="h-12 w-12 shrink-0 rounded-xl bg-white object-contain shadow-border"
                />
            @else
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-neutral-900 text-white">
                    <i data-lucide="graduation-cap" class="h-6 w-6" aria-hidden="true"></i>
                </div>
            @endif
            <div class="min-w-0">
                <p class="truncate text-base font-semibold leading-tight text-neutral-900">
                    {{ $namaPerguruanTinggi !== '' ? $namaPerguruanTinggi : 'Sikampus' }}
                </p>
                <p class="truncate text-xs text-neutral-500">{{ config('app.name', 'Academic Information System') }}</p>
            </div>
        </div>

        <div class="border-b border-neutral-200 bg-neutral-50 p-4">
            <div class="flex items-center gap-3">
                @if ($dosenSidebarFotoUrl ?? null)
                    <img
                        src="{{ $dosenSidebarFotoUrl }}"
                        alt="{{ $authUser->name ?? '' }}"
                        class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-neutral-200"
                    />
                @else
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-neutral-900 text-sm font-semibold text-white">
                        {{ $userInitials }}
                    </div>
                @endif
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-neutral-900">{{ $authUser->name ?? '' }}</p>
                    @if ($dosenSidebarKodeDosen ?? null)
                        <p class="truncate text-xs text-neutral-500">Kode: {{ $dosenSidebarKodeDosen }}</p>
                    @endif
                </div>
            </div>
        </div>

        @if ($dosenHasProdiScope ?? false)
            <div class="px-4 pt-4">
                <a
                    href="{{ $dosenProdiPortalUrl }}"
                    class="flex w-full items-center justify-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2.5 text-sm font-semibold text-sky-800 shadow-sm transition hover:bg-sky-100"
                >
                    <i data-lucide="landmark" class="h-4 w-4 shrink-0" aria-hidden="true"></i>
                    Administrasi Prodi
                </a>
            </div>
        @endif

        <nav class="flex-1 space-y-1 overflow-y-auto p-4" aria-label="Navigasi dosen">
            @include('dosen.partials.sidebar')
        </nav>

        <div class="border-t border-neutral-200 p-4">
            <button
                type="button"
                onclick="document.getElementById('confirm-logout-modal').showModal()"
                class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-900"
            >
                <i data-lucide="log-out" class="h-5 w-5 text-neutral-500" aria-hidden="true"></i>
                Keluar
            </button>
        </div>
    </aside>

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
                <form method="post" action="{{ route('logout') }}">
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

    <div class="flex min-h-screen flex-1 flex-col">
        <header class="print:hidden sticky top-0 z-20 flex items-center justify-end border-b border-neutral-200 bg-white/90 px-4 py-3 backdrop-blur sm:px-6">
            <livewire:notifikasi.bell />
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6">
            @hasSection('breadcrumb')
                <div class="mb-4">
                    @yield('breadcrumb')
                </div>
            @endif

            @hasSection('header_title')
                <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="truncate text-2xl font-semibold tracking-tight text-neutral-900">@yield('header_title')</h1>
                        @hasSection('header_subtitle')
                            <p class="mt-1 text-sm text-neutral-500">@yield('header_subtitle')</p>
                        @endif
                    </div>
                    @hasSection('page_actions')
                        <div class="flex shrink-0 items-center gap-2">
                            @yield('page_actions')
                        </div>
                    @endif
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="print:hidden border-t border-neutral-100 px-4 py-6 text-center text-xs text-neutral-500 sm:px-6">
            &copy; {{ date('Y') }} {{ config('app.name') }}
            @if ($namaPerguruanTinggi)
                &middot; {{ $namaPerguruanTinggi }}
            @endif
        </footer>
    </div>
</div>

<script>
    function renderLucideIcons() {
        if (typeof lucide !== 'undefined' && lucide.createIcons) {
            lucide.createIcons();
        }
    }

    document.addEventListener('DOMContentLoaded', renderLucideIcons);

    document.addEventListener('livewire:init', function () {
        Livewire.hook('morph.updated', renderLucideIcons);
        Livewire.hook('morph.added', renderLucideIcons);

        // Sesi/token kedaluwarsa (419) saat submit form Livewire — pesan Bahasa Indonesia yang
        // konsisten dengan resources/views/errors/419.blade.php (form biasa/non-Livewire).
        // Lihat layouts/web.blade.php untuk penjelasan lengkap; duplikasi ini mengikuti pola
        // layout lain di aplikasi yang masing-masing berdiri sendiri, bukan hasil extends layout Blade lain.
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
