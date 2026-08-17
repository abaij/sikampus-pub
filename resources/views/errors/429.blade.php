@extends('layouts.web')

@section('title', 'Terlalu Banyak Permintaan — ' . config('app.name'))

@section('content')
<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
    <div class="mx-auto max-w-md rounded-2xl bg-white p-8 text-center shadow-border">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
            <i data-lucide="timer" class="h-6 w-6" aria-hidden="true"></i>
        </div>
        <h1 class="text-lg font-semibold text-neutral-900">Terlalu Banyak Permintaan</h1>
        <p class="mt-2 text-sm leading-relaxed text-neutral-600">
            Anda mengirim permintaan terlalu sering dalam waktu singkat. Untuk menjaga kestabilan sistem, silakan
            tunggu sebentar lalu coba lagi.
        </p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
            <button
                type="button"
                onclick="window.location.reload()"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
            >
                <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
                Coba Lagi
            </button>
            @auth
                @php($dashboardRouteName = auth()->user()->webDashboardRouteName())
                @if ($dashboardRouteName)
                    <a
                        href="{{ route($dashboardRouteName) }}"
                        class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
                    >
                        <i data-lucide="layout-dashboard" class="h-4 w-4" aria-hidden="true"></i>
                        Ke Dashboard
                    </a>
                @endif
            @else
                <a
                    href="{{ route('login') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
                >
                    <i data-lucide="log-in" class="h-4 w-4" aria-hidden="true"></i>
                    Ke Halaman Masuk
                </a>
            @endauth
        </div>
    </div>
</div>
@endsection
