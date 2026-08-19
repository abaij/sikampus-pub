@php
    $formatIpk = function ($ipk) {
        if ($ipk === null) {
            return '—';
        }

        return number_format((float) $ipk, 2);
    };
@endphp

@section('title', 'Yudisium — ' . config('app.name'))
@section('header_title', 'Yudisium')
@section('header_subtitle', 'Kelola data kelulusan mahasiswa')
@section('header_icon', 'award')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Yudisium'],
    ]])
@endsection

{{-- Hanya tautan yang boleh diletakkan di page_actions: layouts.web me-render section ini di
     luar root <div> komponen, sehingga wire:click di sini tidak pernah terikat Livewire.
     Tombol ekspor karena itu ditempatkan di dalam badan komponen. --}}
@section('page_actions')
    <a
        href="{{ route('admin.akademik.yudisium.template') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
        Download Template
    </a>
    <a
        href="{{ route('admin.akademik.yudisium.import') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
        Import Yudisium
    </a>
    <a
        href="{{ route('admin.akademik.yudisium.create') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
    >
        <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
        Baru
    </a>
@endsection

<div>
    @if (session('status'))
        <div class="mb-4 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
        <button
            type="button"
            wire:click="exportPdf"
            wire:loading.attr="disabled"
            wire:target="exportPdf"
            class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50"
        >
            <span wire:loading.remove wire:target="exportPdf" class="inline-flex items-center gap-2">
                <i data-lucide="file-text" class="h-4 w-4" aria-hidden="true"></i>
                PDF
            </span>
            <span wire:loading wire:target="exportPdf" class="inline-flex items-center gap-2">
                <i data-lucide="loader-2" class="h-4 w-4 animate-spin" aria-hidden="true"></i>
                Menyiapkan...
            </span>
        </button>
        <button
            type="button"
            wire:click="exportExcel"
            wire:loading.attr="disabled"
            wire:target="exportExcel"
            class="inline-flex items-center gap-2 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-50"
        >
            <span wire:loading.remove wire:target="exportExcel" class="inline-flex items-center gap-2">
                <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
                Excel
            </span>
            <span wire:loading wire:target="exportExcel" class="inline-flex items-center gap-2">
                <i data-lucide="loader-2" class="h-4 w-4 animate-spin" aria-hidden="true"></i>
                Menyiapkan...
            </span>
        </button>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        <div class="space-y-4 border-b border-neutral-200 p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Prodi</label>
                    <x-searchable-select
                        model="filterProdi"
                        :live="true"
                        :options="$prodiOptions"
                        optionLabel="label"
                        placeholder="Semua Prodi"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Semester Masuk</label>
                    <x-searchable-select
                        model="filterSemesterMasuk"
                        :live="true"
                        :options="$semesterOptions"
                        optionLabel="label"
                        placeholder="Semua Semester"
                    />
                </div>
            </div>
            <div class="relative">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari nama mahasiswa, NIM, email, no ijazah, atau judul skripsi..."
                    class="w-full rounded-lg py-2 pl-9 pr-3 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Mahasiswa</th>
                        <th class="px-4 py-3">NIM</th>
                        <th class="px-4 py-3">Prodi</th>
                        <th class="px-4 py-3">Jenis Keluar</th>
                        <th class="px-4 py-3">IPK</th>
                        <th class="px-4 py-3">No. Ijazah</th>
                        <th class="px-4 py-3">Tanggal Keluar</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($yudisiumList as $y)
                        <tr wire:key="yudisium-{{ $y->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-neutral-900">{{ $y->mahasiswa?->nama ?? '—' }}</div>
                                @if ($y->mahasiswa?->email)
                                    <div class="text-xs text-neutral-500">{{ $y->mahasiswa->email }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-neutral-600">{{ $y->mahasiswa?->nim ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $y->mahasiswa?->prodi?->nama ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $y->jenis_keluar?->nama ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $formatIpk($y->ipk) }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $y->no_ijazah ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $y->tgl_keluar ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <a
                                    href="{{ route('admin.akademik.yudisium.show', $y->id) }}"
                                    class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                    title="Lihat detail"
                                >
                                    <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-neutral-500">Belum ada data yudisium.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 p-4">
            {{ $yudisiumList->links() }}
        </div>
    </div>
</div>
