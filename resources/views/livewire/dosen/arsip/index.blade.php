@section('title', 'Arsip — ' . config('app.name'))
@section('header_title', 'Arsip perkuliahan')
@section('header_subtitle', 'Arsip nilai dan kehadiran kelas yang pernah Anda ampu, per semester.')

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
            <x-searchable-select model="filterSemester" :options="$this->semesterOptions" :live="true" placeholder="Semua semester" />
        </div>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        @if (empty($rows))
            <div class="p-10 text-center">
                <i data-lucide="archive" class="mx-auto mb-4 h-10 w-10 text-neutral-300" aria-hidden="true"></i>
                <p class="font-medium text-neutral-600">Tidak ada arsip kelas</p>
                <p class="mt-1 text-sm text-neutral-500">
                    @if ($filterSemester !== '')
                        Tidak ada kelas yang Anda ampu pada semester yang dipilih. Coba pilih "Semua semester".
                    @else
                        Anda belum pernah tercatat sebagai pengampu kelas.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <th class="px-6 py-3">Kode mata kuliah</th>
                            <th class="px-6 py-3">Nama mata kuliah</th>
                            <th class="px-6 py-3">Semester</th>
                            <th class="px-6 py-3">SKS</th>
                            <th class="px-6 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($rows as $kelas)
                            @php $km = $kelas->kurikulumMatkul; @endphp
                            <tr wire:key="arsip-kelas-{{ $kelas->id }}" class="hover:bg-neutral-50/70">
                                <td class="px-6 py-4 font-medium text-neutral-900">{{ $km?->kodeMatkulLabel() ?? '-' }}</td>
                                <td class="px-6 py-4 text-neutral-800">{{ $km?->namaMatkulLabel() ?? '-' }}</td>
                                <td class="px-6 py-4">
                                    @if ($kelas->semester)
                                        <div class="text-neutral-800">{{ $kelas->semester->nama }}</div>
                                        @if ($kelas->semester->kode)
                                            <div class="text-xs text-neutral-500">{{ $kelas->semester->kode }}</div>
                                        @endif
                                    @else
                                        <span class="text-neutral-500">-</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-neutral-600">{{ $km?->sksLabel() ?? 0 }}</td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('dosen.arsip.nilai', $kelas->id) }}" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                                            <i data-lucide="eye" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            Lihat nilai
                                        </a>
                                        <a href="{{ route('dosen.kehadiran.rekap', $kelas->id) }}" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                                            <i data-lucide="clipboard-list" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            Lihat kehadiran
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
