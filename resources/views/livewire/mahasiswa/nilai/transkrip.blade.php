@section('title', 'Transkrip Nilai — ' . config('app.name'))

{{-- Judul dirender di dalam elemen root komponen, bukan lewat @section('header_title')
     + @section('page_actions'): tombol wire:click di dalam @section tidak pernah mendapat
     wire:id di leluhurnya sehingga kliknya tidak terikat ke komponen. Alasan lengkapnya ada di
     resources/views/livewire/mahasiswa/krs/index.blade.php. --}}

@php
    $data = $this->data;
    $formatIp = fn (?float $ip) => $ip === null ? '-' : number_format($ip, 2);
    $nilaiColor = function (?string $huruf) {
        if (! $huruf) {
            return 'text-neutral-500';
        }
        $h = strtoupper($huruf);
        if (in_array($h, ['A', 'A-'], true)) {
            return 'text-emerald-600';
        }
        if (in_array($h, ['B+', 'B', 'B-'], true)) {
            return 'text-sky-600';
        }
        if (in_array($h, ['C+', 'C', 'C-'], true)) {
            return 'text-amber-600';
        }
        if (in_array($h, ['D', 'D+'], true)) {
            return 'text-orange-600';
        }

        return 'text-rose-600';
    };
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold tracking-tight text-neutral-900">Transkrip Nilai</h1>
        </div>
        @if (count($data['mata_kuliah']) > 0)
            <div class="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    wire:click="exportPdf"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 disabled:opacity-50"
                >
                    <i data-lucide="file-down" class="h-4 w-4" aria-hidden="true"></i>
                    Export PDF
                </button>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-sky-100 p-2">
                    <i data-lucide="book-open" class="h-5 w-5 text-sky-600" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-xs text-neutral-500">Total SKS</div>
                    <div class="text-2xl font-bold text-neutral-900">{{ $data['statistik']['total_sks'] }}</div>
                </div>
            </div>
        </div>
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-emerald-100 p-2">
                    <i data-lucide="award" class="h-5 w-5 text-emerald-600" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-xs text-neutral-500">SKS dengan Nilai</div>
                    <div class="text-2xl font-bold text-neutral-900">{{ $data['statistik']['total_sks_dengan_nilai'] }}</div>
                </div>
            </div>
        </div>
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-amber-100 p-2">
                    <i data-lucide="trending-up" class="h-5 w-5 text-amber-600" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="text-xs text-neutral-500">IPK</div>
                    <div class="text-2xl font-bold text-amber-600">{{ $formatIp($data['statistik']['ipk']) }}</div>
                </div>
            </div>
        </div>
    </div>

    @if (count($data['mata_kuliah']) === 0)
        <div class="rounded-2xl bg-white p-10 text-center shadow-border">
            <i data-lucide="graduation-cap" class="mx-auto h-10 w-10 text-neutral-400" aria-hidden="true"></i>
            <p class="mt-3 font-medium text-neutral-700">Tidak ada data transkrip</p>
            <p class="mt-1 text-sm text-neutral-500">Belum ada mata kuliah yang sudah memiliki nilai.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-2xl bg-white shadow-border">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-neutral-200 bg-neutral-50">
                        <tr>
                            <th class="px-6 py-3 text-xs font-semibold uppercase text-neutral-700">No</th>
                            <th class="px-6 py-3 text-xs font-semibold uppercase text-neutral-700">Kode</th>
                            <th class="px-6 py-3 text-xs font-semibold uppercase text-neutral-700">Mata Kuliah</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase text-neutral-700">Semester</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase text-neutral-700">SKS</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase text-neutral-700">Nilai Huruf</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase text-neutral-700">Nilai Angka</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold uppercase text-neutral-700">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($data['mata_kuliah'] as $idx => $mk)
                            <tr wire:key="mk-{{ $mk['id_krs'] }}" class="hover:bg-neutral-50">
                                <td class="px-6 py-4 text-sm text-neutral-600">{{ $idx + 1 }}</td>
                                <td class="px-6 py-4"><span class="font-mono text-sm font-semibold text-sky-600">{{ $mk['matkul']->kode }}</span></td>
                                <td class="px-6 py-4 font-medium text-neutral-900">{{ $mk['matkul']->nama }}</td>
                                <td class="px-6 py-4 text-center">
                                    <div class="text-sm text-neutral-600">{{ $mk['semester']->nama }}</div>
                                    <div class="text-xs text-neutral-500">{{ $mk['semester']->kode }}</div>
                                </td>
                                <td class="px-6 py-4 text-center text-sm text-neutral-600">{{ $mk['matkul']->sks }}</td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-neutral-50 text-sm font-bold {{ $nilaiColor($mk['nilai']->huruf_mutu) }}">
                                        {{ $mk['nilai']->huruf_mutu }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center text-sm text-neutral-600">
                                    {{ $mk['nilai']->angka_mutu !== null ? number_format((float) $mk['nilai']->angka_mutu, 2) : '-' }}
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if ($mk['nilai']->is_final)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-1 text-xs font-medium text-emerald-700">
                                            <i data-lucide="award" class="h-3 w-3" aria-hidden="true"></i>
                                            Final
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-700">Sementara</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
