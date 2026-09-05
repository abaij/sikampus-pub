{{-- Pil status ya/tidak untuk daftar preflight. $ok bool; $yes/$no teksnya. --}}
@if ($ok)
    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">
        <i data-lucide="check" class="h-3.5 w-3.5" aria-hidden="true"></i>{{ $yes }}
    </span>
@else
    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700">
        <i data-lucide="minus" class="h-3.5 w-3.5" aria-hidden="true"></i>{{ $no }}
    </span>
@endif
