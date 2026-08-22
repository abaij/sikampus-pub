@section('title', 'Kelas Mata Kuliah — ' . config('app.name'))
@section('header_title', 'Kelas Mata Kuliah')
@section('header_subtitle', 'Daftar kelas yang Anda ampu (termasuk sebagai PIC atau tim pengampu)')

<div class="space-y-4">
    @php $rows = $this->rows; @endphp

    {{-- Tombol ekspor tinggal di dalam elemen root komponen (bukan @section('page_actions'))
         supaya wire:click-nya terikat — lihat catatan di livewire/mahasiswa/krs/index.blade.php. --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @if (! empty($rows))
                <button
                    type="button"
                    wire:click="exportExcel"
                    wire:loading.attr="disabled"
                    wire:target="exportExcel"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 disabled:opacity-50"
                >
                    <i data-lucide="file-spreadsheet" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="exportExcel">Ekspor Excel</span>
                    <span wire:loading wire:target="exportExcel">Memproses...</span>
                </button>

                <button
                    type="button"
                    wire:click="exportPdf"
                    wire:loading.attr="disabled"
                    wire:target="exportPdf"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 disabled:opacity-50"
                >
                    <i data-lucide="file-down" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="exportPdf">Ekspor PDF</span>
                    <span wire:loading wire:target="exportPdf">Memproses...</span>
                </button>
            @endif
        </div>

        <div class="w-full sm:w-64">
            <x-searchable-select
                model="filterSemester"
                :options="$this->semesterOptions"
                :live="true"
                placeholder="Semua semester"
            />
        </div>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        @if (empty($rows))
            <div class="p-10 text-center">
                <i data-lucide="book-open" class="mx-auto mb-4 h-10 w-10 text-neutral-300" aria-hidden="true"></i>
                <p class="font-medium text-neutral-600">Tidak ada kelas</p>
                <p class="mt-1 text-sm text-neutral-500">Anda tidak terdaftar sebagai pengampu untuk semester ini, atau belum ada penjadwalan.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-600">
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Kode kelas</th>
                            <th class="px-4 py-3">Mata kuliah</th>
                            <th class="px-4 py-3">SKS</th>
                            <th class="px-4 py-3">Program studi</th>
                            <th class="px-4 py-3">Kelompok</th>
                            <th class="px-4 py-3">PIC</th>
                            <th class="px-4 py-3">Jadwal (ringkas)</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($rows as $idx => $row)
                            @php
                                $kelas = $row['kelas'];
                                $km = $kelas->kurikulumMatkul;
                            @endphp
                            <tr wire:key="kelas-{{ $kelas->id }}" class="hover:bg-neutral-50/70">
                                <td class="px-4 py-3 text-neutral-500">{{ $idx + 1 }}</td>
                                <td class="px-4 py-3 font-medium text-sky-700">{{ $kelas->kode }}</td>
                                <td class="min-w-[200px] px-4 py-3">
                                    <div class="font-medium text-neutral-900">{{ $km?->namaMatkulLabel() ?? '—' }}</div>
                                    <div class="text-xs text-neutral-500">{{ $km?->kodeMatkulLabel() ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $km?->sksLabel() ?? 0 }}</td>
                                <td class="min-w-[160px] px-4 py-3">
                                    @if ($kelas->prodi)
                                        <div>{{ $kelas->prodi->nama }}</div>
                                        @if ($kelas->prodi->jenjang)
                                            <div class="text-xs text-neutral-500">{{ $kelas->prodi->jenjang->nama }}</div>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3">{{ $kelas->kelompokKelas?->nama ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['is_pic'])
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Ya</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-neutral-800">Bukan</span>
                                    @endif
                                </td>
                                <td class="max-w-[200px] px-4 py-3 text-neutral-600">{{ $row['jadwal_ringkas'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a
                                        href="{{ route('dosen.jadwal.show', ['kelasId' => $kelas->id, 'id_semester' => $filterSemester !== '' ? $filterSemester : null]) }}"
                                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50"
                                    >
                                        <i data-lucide="list" class="h-3.5 w-3.5 text-neutral-400" aria-hidden="true"></i>
                                        Lihat jadwal
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
