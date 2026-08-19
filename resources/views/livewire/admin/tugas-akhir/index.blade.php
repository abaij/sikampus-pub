@php
    $statusLabel = fn (?string $s) => match ($s) {
        'draft' => 'Draft',
        'submitted' => 'Terkirim',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'returned' => 'Dikembalikan',
        default => '—',
    };
    $statusBadgeClass = fn (?string $s) => match ($s) {
        'approved' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-rose-50 text-rose-700',
        'returned' => 'bg-amber-50 text-amber-700',
        'submitted' => 'bg-sky-50 text-sky-700',
        default => 'bg-neutral-100 text-neutral-600',
    };
@endphp

@section('title', 'Tugas Akhir — ' . config('app.name'))
@section('header_title', 'Tugas Akhir')
@section('header_subtitle', 'Pengajuan judul, pembimbing, dan ujian sidang tugas akhir')
@section('header_icon', 'graduation-cap')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Tugas Akhir'],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ route('admin.akademik.tugas-akhir.template') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
        Download Template
    </a>
    <a
        href="{{ route('admin.akademik.tugas-akhir.import') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
        Import Tugas Akhir
    </a>
@endsection

<div>
    <div class="rounded-2xl bg-white shadow-border">
        <div class="space-y-4 border-b border-neutral-200 p-4">
            <div class="relative">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari nama mahasiswa, NIM, atau judul tugas akhir..."
                    class="w-full rounded-lg py-2 pl-9 pr-3 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                />
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Prodi</label>
                    <x-searchable-select
                        model="filterProdi"
                        :live="true"
                        :options="$prodiOptions"
                        optionLabel="label"
                        placeholder="Semua Prodi"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Semester</label>
                    <x-searchable-select
                        model="filterSemester"
                        :live="true"
                        :options="$semesterOptions"
                        optionLabel="label"
                        placeholder="Semua Semester"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Status</label>
                    <x-searchable-select
                        model="filterStatus"
                        :live="true"
                        :options="$this->statusOptions()"
                        placeholder="Semua Status"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Jenis</label>
                    <x-searchable-select
                        model="filterJenis"
                        :live="true"
                        :options="$this->jenisOptions()"
                        placeholder="Semua Jenis"
                    />
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Mahasiswa</th>
                        <th class="px-4 py-3">NIM</th>
                        <th class="px-4 py-3">Prodi</th>
                        <th class="px-4 py-3">Semester</th>
                        <th class="px-4 py-3">Jenis</th>
                        <th class="px-4 py-3">Judul</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($tugasAkhirList as $ta)
                        <tr wire:key="ta-{{ $ta->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-neutral-900">{{ $ta->mahasiswa?->nama ?? '—' }}</div>
                                @if ($ta->mahasiswa?->email)
                                    <div class="text-xs text-neutral-500">{{ $ta->mahasiswa->email }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-neutral-600">{{ $ta->mahasiswa?->nim ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $ta->mahasiswa?->prodi?->nama ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $ta->semester?->nama ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600 whitespace-nowrap">
                                {{ $ta->is_proposal === true ? 'Proposal' : ($ta->is_proposal === false ? 'Skripsi / Tesis / TA' : '—') }}
                            </td>
                            <td class="px-4 py-3 text-neutral-700 max-w-[240px]">
                                <span class="line-clamp-2" title="{{ $ta->judul }}">{{ $ta->judul ?: '—' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusBadgeClass($ta->status) }}">
                                    {{ $statusLabel($ta->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a
                                    href="{{ route('admin.akademik.tugas-akhir.show', $ta->id) }}"
                                    class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                    title="Lihat detail"
                                >
                                    <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-neutral-500">Belum ada data tugas akhir.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 p-4">
            {{ $tugasAkhirList->links() }}
        </div>
    </div>
</div>
