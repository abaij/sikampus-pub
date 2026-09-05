@extends('layouts.web')

@section('title', 'Migrasi — ' . config('app.name'))

@section('header_title', 'Migrasi')
@section('header_subtitle', 'Superadmin')
@section('header_icon', 'database')

@section('header_actions')
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button
            type="submit"
            class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 focus:outline-none focus:ring-2 focus:ring-neutral-900 focus:ring-offset-2"
        >
            <i data-lucide="log-out" class="h-4 w-4" aria-hidden="true"></i>
            Keluar
        </button>
    </form>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="rounded-2xl bg-white p-8 shadow-border">
            <div class="mb-6">
                <h2 class="text-xl font-semibold tracking-tight text-neutral-900">Migrasi Database</h2>
                <p class="mt-2 text-sm leading-relaxed text-neutral-600">
                    Menjalankan migrasi yang belum pernah dijalankan. Diperlukan setelah memperbarui
                    berkas aplikasi ke versi baru. Migrasi yang sudah pernah jalan otomatis dilewati,
                    jadi menjalankan halaman ini berulang kali aman.
                </p>
            </div>

            @if (session('status'))
                <div class="mb-6 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
                    <i data-lucide="circle-check" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 flex gap-3 rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
                    <i data-lucide="circle-alert" class="h-5 w-5 shrink-0 text-red-600" aria-hidden="true"></i>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-neutral-50 px-4 py-3">
                    <p class="text-xs text-neutral-500">Sudah dijalankan</p>
                    <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $ranCount }}</p>
                </div>
                <div class="rounded-xl bg-neutral-50 px-4 py-3">
                    <p class="text-xs text-neutral-500">Tertunda</p>
                    <p class="mt-1 text-2xl font-semibold {{ count($pending) > 0 ? 'text-amber-600' : 'text-neutral-900' }}">{{ count($pending) }}</p>
                </div>
                <div class="rounded-xl bg-neutral-50 px-4 py-3">
                    <p class="text-xs text-neutral-500">Total berkas migrasi</p>
                    <p class="mt-1 text-2xl font-semibold text-neutral-900">{{ $totalCount }}</p>
                </div>
            </div>

            @if (count($pending) > 0)
                <div class="mt-6">
                    <h3 class="text-sm font-semibold text-neutral-900">Migrasi yang akan dijalankan</h3>
                    <ul class="mt-2 max-h-64 overflow-auto rounded-lg bg-neutral-50 p-4 font-mono text-xs leading-relaxed text-neutral-700">
                        @foreach ($pending as $migration)
                            <li>{{ $migration }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="mt-6 flex gap-3 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <i data-lucide="triangle-alert" class="h-5 w-5 shrink-0 text-amber-600" aria-hidden="true"></i>
                    <span>
                        <strong>Backup database Anda terlebih dahulu.</strong> Migrasi mengubah struktur
                        tabel dan tidak dapat dibatalkan dari halaman ini. Pada database besar, proses ini
                        bisa memakan waktu beberapa menit — jangan menutup halaman selama berjalan.
                    </span>
                </div>

                <form
                    method="post"
                    action="{{ route('superadmin.migrasi.run') }}"
                    class="mt-6"
                    onsubmit="return confirm('Jalankan {{ count($pending) }} migrasi yang tertunda? Pastikan database sudah di-backup.');"
                >
                    @csrf
                    <button
                        type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
                    >
                        <i data-lucide="play" class="h-4 w-4" aria-hidden="true"></i>
                        Jalankan Migrasi
                    </button>
                </form>
            @else
                <div class="mt-6 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    <i data-lucide="circle-check" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
                    <span>Tidak ada migrasi tertunda. Struktur database sudah mutakhir.</span>
                </div>
            @endif
        </div>

        @if (session('output'))
            <div class="rounded-2xl bg-white p-8 shadow-border">
                <h3 class="text-sm font-semibold text-neutral-900">Keluaran</h3>
                <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-neutral-900 p-4 font-mono text-xs leading-relaxed text-neutral-100">{{ session('output') }}</pre>
            </div>
        @endif
    </div>
@endsection
