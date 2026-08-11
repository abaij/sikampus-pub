@section('title', 'Import Negara — ' . config('app.name'))
@section('header_title', 'Import Negara')
@section('header_subtitle', 'Import data negara secara massal dari file Excel')
@section('header_icon', 'upload')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Pengaturan'],
        ['label' => 'Sistem'],
        ['label' => 'Negara', 'route' => route('admin.sistem.negara')],
        ['label' => 'Import'],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ route('admin.sistem.negara') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
    <a
        href="{{ route('admin.sistem.negara.template') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
        Download Template
    </a>
@endsection

<div>
    <div class="mb-6 rounded-2xl bg-white p-6 shadow-border">
        <h2 class="mb-2 text-sm font-semibold text-neutral-900">Cara import</h2>
        <ol class="list-inside list-decimal space-y-1 text-sm text-neutral-600">
            <li>Download template Excel lewat tombol "Download Template" di atas.</li>
            <li>Isi data mengikuti kolom pada baris pertama (Kode, Nama) — kedua kolom wajib diisi.</li>
            <li>Kode negara harus unik; baris dengan kode yang sudah ada di sistem akan diabaikan.</li>
            <li>Upload file (.xlsx atau .xls, maks 10MB) lalu klik "Proses Import".</li>
        </ol>
    </div>

    <div class="rounded-2xl bg-white p-6 shadow-border">
        <form wire:submit="import" class="space-y-4">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-neutral-700">File Excel</label>
                <input
                    type="file"
                    wire:model="file"
                    accept=".xlsx,.xls"
                    class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('file') ring-2 ring-red-500 @enderror shadow-border"
                />
                @error('file') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                <div wire:loading wire:target="file" class="mt-1.5 text-sm text-neutral-500">Mengunggah file...</div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="import"
                    class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="import">Proses Import</span>
                    <span wire:loading wire:target="import">Memproses...</span>
                </button>
            </div>
        </form>
    </div>

    @if ($result)
        <div class="mt-6 rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-4 text-sm font-semibold text-neutral-900">Hasil Import</h2>

            <div class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-2">
                <div class="rounded-lg bg-emerald-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-emerald-700">Berhasil</p>
                    <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ $result['success_count'] }}</p>
                </div>
                <div class="rounded-lg bg-rose-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-rose-700">Dilewati</p>
                    <p class="mt-1 text-2xl font-semibold text-rose-700">{{ $result['skip_count'] }}</p>
                </div>
            </div>

            @if ($result['errors'] !== [])
                <div class="rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <p class="font-semibold">Peringatan ({{ count($result['errors']) }} baris):</p>
                    <ul class="mt-2 max-h-80 list-inside list-disc space-y-0.5 overflow-y-auto">
                        @foreach ($result['errors'] as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>
