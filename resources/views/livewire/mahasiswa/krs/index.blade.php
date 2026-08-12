@section('title', 'Kartu Rencana Studi — ' . config('app.name'))

{{-- Tombol export sengaja TIDAK ditaruh di @section('page_actions'): section itu dirender oleh
     layouts.mahasiswa lewat @yield di luar elemen root yang di-hydrate Livewire (Livewire hanya
     menyisipkan wire:id ke root HTML yang TIDAK berada di dalam @section manapun — lihat
     SupportPageComponents::renderContentsIntoLayout, yang membungkus konten non-section sebagai
     @section('content') lalu me-render sisanya lewat @extends biasa). Tombol dengan wire:click di
     dalam @section('page_actions') tidak akan pernah mendapat wire:id di elemen leluhurnya,
     sehingga klik tidak terikat ke komponen manapun dan tidak melakukan apa-apa. Header di bawah
     ini meniru markup @hasSection('header_title') milik layouts.mahasiswa supaya tampilannya
     sama, tapi harus jadi ANAK PERTAMA dari div ini (bukan sibling) — Livewire cuma mengizinkan
     satu elemen root per komponen, dua div sejajar di sini akan melempar
     MultipleRootElementsDetected. --}}
<div class="space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold tracking-tight text-neutral-900">Kartu Rencana Studi (KRS)</h1>
        </div>
        @if (count($this->krsBySemester) > 0)
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

    @if (count($this->krsBySemester) === 0)
        <div class="rounded-2xl bg-white p-10 text-center shadow-border">
            <i data-lucide="book-open" class="mx-auto h-10 w-10 text-neutral-400" aria-hidden="true"></i>
            <p class="mt-3 font-medium text-neutral-700">Tidak ada data KRS</p>
            <p class="mt-1 text-sm text-neutral-500">Anda belum memiliki KRS yang terdaftar.</p>
        </div>
    @else
        @foreach ($this->krsBySemester as $group)
            <div wire:key="semester-{{ $group['semester']->id }}" class="overflow-hidden rounded-2xl bg-white shadow-border">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-100 bg-neutral-50 px-4 py-3 sm:px-6">
                    <h3 class="text-base font-semibold text-neutral-900">{{ $group['semester']->nama }}</h3>
                    <div class="text-sm text-neutral-600">
                        <span class="font-medium text-neutral-900">Total SKS: {{ $group['total_sks_diacc'] }} / {{ $group['total_sks_diajukan'] }}</span>
                        @if ($group['total_sks_diajukan'] > 0)
                            <span class="ml-2 text-neutral-500">({{ round(($group['total_sks_diacc'] / $group['total_sks_diajukan']) * 100) }}% disetujui)</span>
                        @endif
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th class="w-12 px-4 py-3">No</th>
                                <th class="px-4 py-3">Kode</th>
                                <th class="px-4 py-3">Mata Kuliah</th>
                                <th class="w-16 px-4 py-3 text-center">SKS</th>
                                <th class="px-4 py-3">Dosen</th>
                                <th class="w-28 px-4 py-3">Status</th>
                                <th class="px-4 py-3">Tgl Disetujui</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            @foreach ($group['krs'] as $idx => $krs)
                                @php $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null; @endphp
                                <tr wire:key="krs-{{ $krs->id }}">
                                    <td class="px-4 py-3 text-neutral-600">{{ $idx + 1 }}</td>
                                    <td class="px-4 py-3 font-medium text-sky-600">{{ $matkul->kode ?? '-' }}</td>
                                    <td class="px-4 py-3 font-medium text-neutral-900">{{ $matkul->nama ?? '-' }}</td>
                                    <td class="px-4 py-3 text-center text-neutral-700">{{ $matkul->sks ?? 0 }}</td>
                                    <td class="px-4 py-3 text-neutral-700">{{ $krs->kelas->dosenPic->nama ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($krs->approved_at)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
                                                <i data-lucide="check-circle" class="h-3 w-3" aria-hidden="true"></i>
                                                Disetujui
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                                                <i data-lucide="clock" class="h-3 w-3" aria-hidden="true"></i>
                                                Menunggu
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-neutral-600">{{ $krs->approved_at?->translatedFormat('d M Y') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif
</div>
