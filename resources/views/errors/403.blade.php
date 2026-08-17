@extends('layouts.web')

@section('title', 'Akses Ditolak — ' . config('app.name'))

@section('content')
<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
    <div class="mx-auto max-w-md rounded-2xl bg-white p-8 text-center shadow-border">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-rose-50 text-rose-600">
            <i data-lucide="shield-alert" class="h-6 w-6" aria-hidden="true"></i>
        </div>
        <h1 class="text-lg font-semibold text-neutral-900">Akses Ditolak</h1>
        <p class="mt-2 text-sm leading-relaxed text-neutral-600">
            {{ $exception->getMessage() ?: 'Anda tidak memiliki hak akses untuk membuka halaman ini.' }}
        </p>
        @auth
            @php($dashboardRouteName = auth()->user()->webDashboardRouteName())
            @if ($dashboardRouteName)
                <a
                    href="{{ route($dashboardRouteName) }}"
                    class="mt-6 inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
                >
                    <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
                    Kembali ke Dashboard
                </a>
            @endif
        @endauth
    </div>
</div>
@endsection
