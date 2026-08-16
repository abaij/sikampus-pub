@section('title', 'Import Nilai — ' . config('app.name'))
@section('header_title', 'Import Nilai')
@section('header_subtitle', 'Import data nilai mahasiswa secara massal dari file Excel')
@section('header_icon', 'upload')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Nilai', 'route' => route('admin.akademik.nilai')],
        ['label' => 'Import'],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ route('admin.akademik.nilai') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
    <a
        href="{{ route('admin.akademik.nilai.template') }}"
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
            <li>Isi data mengikuti kolom pada baris pertama template — kolom bertanda <span class="font-semibold">*</span> wajib diisi.</li>
            <li>NIM, Kode Mata Kuliah, dan Kode Semester harus sudah ada di sistem, dan mahasiswa tersebut harus sudah punya KRS untuk kombinasi mata kuliah + semester itu.</li>
            <li>Angka Mutu dan Huruf Mutu opsional; Is Final opsional (true/false, default false kalau kosong).</li>
            <li>Kalau KRS yang dituju sudah punya nilai, baris itu akan <span class="font-semibold">memperbarui</span> nilai yang ada, bukan membuat baris baru.</li>
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
                    <p class="text-xs font-semibold uppercase text-emerald-700">Berhasil (Baru)</p>
                    <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ $result['success_count'] }}</p>
                </div>
                <div class="rounded-lg bg-sky-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase text-sky-700">Diperbarui</p>
                    <p class="mt-1 text-2xl font-semibold text-sky-700">{{ $result['updated_count'] }}</p>
                </div>
            </div>

            @if ($result['errors'] !== [])
                <div
                    class="rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800"
                    x-data="{
                        copied: false,
                        copyLog() {
                            const text = @js(implode(PHP_EOL, $result['errors']));
                            const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2000); };
                            if (navigator.clipboard && window.isSecureContext) {
                                navigator.clipboard.writeText(text).then(done);
                                return;
                            }
                            const el = document.createElement('textarea');
                            el.value = text;
                            el.style.position = 'fixed';
                            el.style.opacity = '0';
                            document.body.appendChild(el);
                            el.select();
                            document.execCommand('copy');
                            document.body.removeChild(el);
                            done();
                        },
                    }"
                >
                    <div class="flex items-center justify-between gap-3">
                        <p class="font-semibold">Peringatan ({{ count($result['errors']) }} baris):</p>
                        <button
                            type="button"
                            @click="copyLog()"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-200 bg-white px-2.5 py-1 text-xs font-medium text-amber-800 transition hover:bg-amber-100"
                        >
                            <i data-lucide="clipboard-copy" class="h-3.5 w-3.5" aria-hidden="true"></i>
                            <span x-text="copied ? 'Tersalin!' : 'Salin Log'"></span>
                        </button>
                    </div>
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
