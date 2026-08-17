@extends('layouts.web')

@section('title', 'Halaman Kedaluwarsa — ' . config('app.name'))

@section('content')
<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
    <div class="mx-auto max-w-md rounded-2xl bg-white p-8 text-center shadow-border">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
            <i data-lucide="refresh-cw" class="h-6 w-6" aria-hidden="true"></i>
        </div>
        <h1 class="text-lg font-semibold text-neutral-900">Halaman Kedaluwarsa</h1>
        <p class="mt-2 text-sm leading-relaxed text-neutral-600">
            Sesi Anda sudah tidak berlaku lagi (halaman terlalu lama dibuka atau sesi login berakhir), sehingga data
            yang tadi dikirim tidak dapat diproses. Muat ulang halaman ini lalu coba lagi.
        </p>
        <button
            type="button"
            onclick="history.length > 1 ? history.back() : (window.location.href = '{{ route('login') }}')"
            class="mt-6 inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
        >
            <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
            Muat Ulang Halaman
        </button>
    </div>

    <script>
        // Alert browser, selain kartu di atas — supaya user yang tidak membaca halaman pun
        // sadar harus memuat ulang, bukan mengira sistem sedang error. Begitu alert ditutup,
        // langsung arahkan balik ke halaman formulirnya (fresh, dengan token CSRF baru).
        window.addEventListener('DOMContentLoaded', function () {
            alert('Sesi Anda sudah berakhir. Halaman akan dimuat ulang, silakan coba kirim ulang formulirnya.');
            history.length > 1 ? history.back() : (window.location.href = '{{ route('login') }}');
        });
    </script>
</div>
@endsection
