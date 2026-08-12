@section('title', 'Kehadiran — ' . config('app.name'))
@section('header_title', 'Kehadiran')

@php
    $p = $this->perkuliahan;
    $kelas = $p->jadwal->kelas;
    $km = $kelas->kurikulumMatkul;
    $statusOptions = [
        'hadir' => ['label' => 'Hadir', 'selected' => 'border-emerald-500 bg-emerald-50 text-emerald-800 ring-2 ring-emerald-500/30', 'idle' => 'shadow-border text-neutral-600 hover:bg-emerald-50/50'],
        'izin' => ['label' => 'Izin', 'selected' => 'border-sky-500 bg-sky-50 text-sky-800 ring-2 ring-sky-500/30', 'idle' => 'shadow-border text-neutral-600 hover:bg-sky-50/50'],
        'sakit' => ['label' => 'Sakit', 'selected' => 'border-amber-500 bg-amber-50 text-amber-900 ring-2 ring-amber-500/30', 'idle' => 'shadow-border text-neutral-600 hover:bg-amber-50/50'],
        'alfa' => ['label' => 'Alfa', 'selected' => 'border-rose-500 bg-rose-50 text-rose-800 ring-2 ring-rose-500/30', 'idle' => 'shadow-border text-neutral-600 hover:bg-rose-50/50'],
    ];
@endphp

@section('breadcrumb')
    {{-- Kembali ke tab Kehadiran pada slot jadwal terkait — itulah pintu masuk utama ke halaman
         ini sekarang (lihat tab 'kehadiran' di App\Livewire\Dosen\Jadwal\Detail), bukan ke
         App\Livewire\Dosen\Kehadiran\Index yang sudah tidak ada di menu. --}}
    <a
        href="{{ route('dosen.jadwal.detail', ['kelasId' => $p->jadwal->id_kelas, 'jadwalId' => $p->id_jadwal]) }}"
        class="inline-flex items-center gap-2 text-sm font-medium text-sky-600 hover:text-sky-700"
    >
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
@endsection

@section('header_subtitle')
    {{ $km?->kodeMatkulLabel() ?? '-' }} - {{ $km?->namaMatkulLabel() ?? '-' }} ({{ $km?->sksLabel() ?? 0 }} SKS)
    @if ($kelas->prodi) &middot; {{ $kelas->prodi->nama }}{{ $kelas->prodi->jenjang ? ' (' . $kelas->prodi->jenjang->nama . ')' : '' }} @endif
    &middot; {{ $p->waktu_mulai ? \Illuminate\Support\Carbon::parse($p->waktu_mulai)->translatedFormat('l, d F Y H:i') : ($p->tanggal ? \Illuminate\Support\Carbon::parse($p->tanggal)->translatedFormat('l, d F Y') : 'Sesi perkuliahan') }}
    @if ($p->materi) &middot; Materi: {{ $p->materi }} @endif
@endsection

<div class="space-y-4">
    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <form wire:submit="save">
        <div class="overflow-hidden rounded-2xl bg-white shadow-border">
            <div class="flex flex-col gap-1 border-b border-neutral-200 bg-neutral-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-neutral-900">Daftar mahasiswa ({{ count($this->mahasiswa) }})</h3>
                    <p class="mt-0.5 text-xs text-neutral-500">Pilih status kehadiran untuk tiap mahasiswa; tidak ada pilihan bawaan.</p>
                </div>
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-semibold text-sky-700 shadow-sm transition hover:bg-sky-100">
                    <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                    Simpan kehadiran
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <th class="px-6 py-3">No</th>
                            <th class="px-6 py-3">NIM</th>
                            <th class="px-6 py-3">Nama</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @forelse ($this->mahasiswa as $idx => $item)
                            @php $idMhs = $item['mahasiswa']['id']; @endphp
                            <tr wire:key="kehadiran-mhs-{{ $idMhs }}" class="hover:bg-neutral-50/70">
                                <td class="px-6 py-4 text-neutral-900">{{ $idx + 1 }}</td>
                                <td class="px-6 py-4 font-medium text-neutral-900">{{ $item['mahasiswa']['nim'] }}</td>
                                <td class="px-6 py-4 text-neutral-900">{{ $item['mahasiswa']['nama'] }}</td>
                                <td class="px-6 py-4 align-top">
                                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Status kehadiran">
                                        @foreach ($statusOptions as $value => $opt)
                                            <button
                                                type="button"
                                                wire:click="$set('form.{{ $idMhs }}.status', '{{ $value }}')"
                                                class="inline-flex min-w-[4.25rem] items-center justify-center rounded-lg border px-2.5 py-1.5 text-xs font-semibold shadow-sm transition {{ ($form[$idMhs]['status'] ?? '') === $value ? $opt['selected'] : $opt['idle'] }}"
                                            >
                                                {{ $opt['label'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                    @error("form.{$idMhs}.status") <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-6 py-4">
                                    <input type="text" wire:model="form.{{ $idMhs }}.keterangan" placeholder="Keterangan (opsional)" class="w-full rounded-lg px-3 py-2 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-neutral-500">Tidak ada mahasiswa yang mengambil mata kuliah ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </form>
</div>
