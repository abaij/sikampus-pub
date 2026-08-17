@extends('layouts.web')

@section('title', 'Sedang Pemeliharaan — ' . config('app.name'))

@section('content')
<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
    <div class="mx-auto max-w-md rounded-2xl bg-white p-8 text-center shadow-border">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-neutral-100 text-neutral-600">
            <i data-lucide="wrench" class="h-6 w-6" aria-hidden="true"></i>
        </div>
        <h1 class="text-lg font-semibold text-neutral-900">Sistem Sedang Pemeliharaan</h1>
        <p class="mt-2 text-sm leading-relaxed text-neutral-600">
            Kami sedang melakukan pemeliharaan terjadwal untuk meningkatkan layanan. Mohon maaf atas
            ketidaknyamanannya — sistem akan kembali tersedia sebentar lagi.
        </p>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-2">
            <button
                type="button"
                onclick="window.location.reload()"
                class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
            >
                <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
                Coba Lagi
            </button>
        </div>
    </div>
</div>
@endsection
