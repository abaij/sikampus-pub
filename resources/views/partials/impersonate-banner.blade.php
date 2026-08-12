@if (session('impersonator_id'))
    {{-- Banner fitur "Login as" (App\Http\Controllers\Web\ImpersonateController) — di-include di
         SEMUA layout (web/dosen/mahasiswa/prodi), bukan cuma layouts.web, karena target
         impersonate (dosen/mahasiswa) punya layout sendiri masing-masing dengan sidebar sendiri,
         bukan extends dari layouts.web. Sengaja bukan Livewire/Alpine supaya tetap tampil walau
         halaman yang sedang dibuka bukan komponen Livewire. --}}
    <div class="print:hidden sticky top-0 z-30 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 bg-amber-500 px-4 py-2 text-center text-sm font-medium text-amber-950">
        <span class="inline-flex items-center gap-1.5">
            <i data-lucide="venetian-mask" class="h-4 w-4" aria-hidden="true"></i>
            Anda sedang login sebagai <strong>{{ auth()->user()->name ?? '' }}</strong> ({{ auth()->user()->role ?? '' }})
        </span>
        <form method="post" action="{{ route('impersonate.stop') }}">
            @csrf
            <button type="submit" class="rounded-md bg-amber-950/10 px-2 py-1 font-semibold underline decoration-dotted underline-offset-2 transition hover:bg-amber-950/20">
                Kembali ke admin
            </button>
        </form>
    </div>
@endif
