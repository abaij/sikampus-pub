@section('title', 'Rekap Kehadiran — ' . config('app.name'))
@section('header_title', 'Rekap kehadiran')

@section('breadcrumb')
    <a href="{{ route('dosen.kehadiran', ['id_semester' => $this->kelas->id_semester]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-sky-600 hover:text-sky-700">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
@endsection

@php
    $kelas = $this->kelas;
    $km = $kelas->kurikulumMatkul;
    $rekap = $this->rekap;
@endphp

<div class="space-y-4">
    <div class="rounded-2xl bg-white p-5 shadow-border text-sm">
        <p><span class="font-semibold text-neutral-700">Dosen:</span> <span class="text-neutral-900">{{ Auth::user()->name }}</span></p>
        <p class="mt-1"><span class="font-semibold text-neutral-700">Mata kuliah:</span> <span class="text-neutral-900">{{ $km?->kodeMatkulLabel() ?? '-' }} - {{ $km?->namaMatkulLabel() ?? '-' }}</span></p>
        <p class="mt-1"><span class="font-semibold text-neutral-700">Prodi:</span> <span class="text-neutral-900">{{ $kelas->prodi?->nama ?? '-' }}{{ $kelas->prodi?->jenjang ? ' (' . $kelas->prodi->jenjang->nama . ')' : '' }}</span></p>
        <p class="mt-1"><span class="font-semibold text-neutral-700">Th. akademik:</span> <span class="text-neutral-900">{{ $kelas->semester?->nama ?? '-' }}</span></p>
    </div>

    <div class="rounded-2xl bg-white p-6 shadow-border">
        @include('livewire.dosen.kehadiran.partials.rekap-table', ['rekap' => $rekap])
    </div>
</div>
