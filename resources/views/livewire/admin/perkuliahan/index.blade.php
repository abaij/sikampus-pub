@section('title', 'Perkuliahan — ' . config('app.name'))
@section('header_title', 'Perkuliahan')
@section('header_subtitle', 'Pemantauan sesi perkuliahan dan kehadiran mahasiswa per kelas')
@section('header_icon', 'calendar-days')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Perkuliahan'],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ route('admin.akademik.perkuliahan.template') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
        Download Template
    </a>
    <a
        href="{{ route('admin.akademik.perkuliahan.import') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
        Import Perkuliahan
    </a>
@endsection

<div>
    <div class="rounded-2xl bg-white shadow-border">
        <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 p-4">
            <div class="relative flex-1 min-w-[220px]">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari nama/kode mata kuliah, prodi, atau dosen PIC..."
                    class="w-full rounded-lg py-2 pl-9 pr-3 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                />
            </div>
            <div class="w-56">
                <x-searchable-select
                    model="filterProdi"
                    :live="true"
                    :options="$prodiOptions"
                    optionLabel="label"
                    placeholder="Semua prodi"
                />
            </div>
            <div class="w-56">
                <x-searchable-select
                    model="filterSemester"
                    :live="true"
                    :options="$semesterOptions"
                    optionLabel="label"
                    placeholder="Semua semester"
                />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Kelas / Mata Kuliah</th>
                        <th class="px-4 py-3 text-center">Jumlah Jadwal</th>
                        <th class="px-4 py-3">Dosen Penanggung Jawab</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($kelasList as $kelas)
                        <tr wire:key="kelas-{{ $kelas->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-neutral-900">
                                    {{ $kelas->kurikulumMatkul?->matkul?->kode ? "{$kelas->kurikulumMatkul->matkul->kode} - " : '' }}{{ $kelas->kurikulumMatkul?->matkul?->nama ?? '—' }}
                                </div>
                                @if ($kelas->kode)
                                    <div class="text-xs text-neutral-500">Kode: {{ $kelas->kode }}</div>
                                @endif
                                <div class="text-xs text-neutral-500">
                                    {{ $kelas->prodi ? ($kelas->prodi->jenjang?->kode ? "{$kelas->prodi->nama} ({$kelas->prodi->jenjang->kode})" : $kelas->prodi->nama) : '—' }}
                                    @if ($kelas->semester)
                                        · {{ $kelas->semester->nama }} ({{ $kelas->semester->kode }})
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-center tabular-nums text-neutral-900">{{ $kelas->jadwal_count }}</td>
                            <td class="px-4 py-3 text-neutral-900">{{ $kelas->dosenPic?->nama ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-2">
                                    <a
                                        href="{{ route('admin.akademik.perkuliahan.show', $kelas->id) }}{{ $returnQuery ? '?' . $returnQuery : '' }}"
                                        class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
                                    >
                                        <i data-lucide="calendar-days" class="h-4 w-4" aria-hidden="true"></i>
                                        Kehadiran
                                    </a>
                                    <a
                                        href="{{ route('admin.akademik.perkuliahan.nilai', $kelas->id) }}{{ $returnQuery ? '?' . $returnQuery : '' }}"
                                        class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
                                    >
                                        <i data-lucide="clipboard-list" class="h-4 w-4" aria-hidden="true"></i>
                                        Nilai
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-neutral-500">Belum ada data kelas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 p-4">
            {{ $kelasList->links() }}
        </div>
    </div>
</div>
