@section('title', 'KTM — ' . config('app.name'))
@section('header_title', 'Kartu Tanda Mahasiswa')
@section('header_subtitle', 'Lihat, buat, atau perbarui KTM digital Anda')
@section('header_icon', 'id-card')

<div class="space-y-6">
    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if (session('ktm_error'))
        <div class="flex gap-3 rounded-lg border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <i data-lucide="alert-circle" class="h-5 w-5 shrink-0 text-rose-600" aria-hidden="true"></i>
            <span>{{ session('ktm_error') }}</span>
        </div>
    @endif

    <div class="rounded-2xl bg-white p-6 shadow-border">
        @if (! $ktmId)
            <div class="flex flex-col items-center gap-4 py-8 text-center">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-neutral-100">
                    <i data-lucide="id-card" class="h-8 w-8 text-neutral-400" aria-hidden="true"></i>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-neutral-900">Anda belum memiliki KTM digital</h3>
                    <p class="mt-1 text-sm text-neutral-500">Buat KTM Anda sekarang — gambar akan dibuat otomatis berdasarkan data NIM, nama, dan prodi Anda.</p>
                </div>

                <button
                    type="button"
                    wire:click="buatKtm"
                    wire:loading.attr="disabled"
                    wire:target="buatKtm"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <i data-lucide="id-card" class="h-4 w-4" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="buatKtm">Buat KTM</span>
                    <span wire:loading wire:target="buatKtm">Memproses...</span>
                </button>
            </div>
        @else
            <div class="flex flex-col items-center gap-4">
                <img src="{{ $fileUrl }}" alt="KTM" class="w-full max-w-md rounded-xl shadow-border" />

                @if ($nomorKtm)
                    <p class="text-sm text-neutral-600">Nomor KTM: <span class="font-medium text-neutral-900">{{ $nomorKtm }}</span></p>
                @endif

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <a
                        href="{{ $fileUrl }}"
                        target="_blank"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
                    >
                        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
                        Buka / Unduh
                    </a>
                    <button
                        type="button"
                        wire:click="perbaruiKtm"
                        wire:loading.attr="disabled"
                        wire:target="perbaruiKtm"
                        class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
                        <span wire:loading.remove wire:target="perbaruiKtm">Perbarui KTM</span>
                        <span wire:loading wire:target="perbaruiKtm">Memproses...</span>
                    </button>
                </div>
            </div>
        @endif
    </div>
</div>
