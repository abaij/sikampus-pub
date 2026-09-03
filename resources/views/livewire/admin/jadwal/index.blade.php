@section('title', 'Jadwal — ' . config('app.name'))
@section('header_title', 'Jadwal')
@section('header_subtitle', 'Jadwal pertemuan kuliah per kelas')
@section('header_icon', 'calendar-clock')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Jadwal'],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ route('admin.akademik.jadwal.template') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
        Download Template
    </a>
    <a
        href="{{ route('admin.akademik.jadwal.import') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
        Import Jadwal
    </a>
    <a
        href="{{ route('admin.akademik.jadwal.create') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
    >
        <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
        Tambah Jadwal
    </a>
@endsection

<div>
    @if (session('status'))
        <div class="mb-4 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="rounded-2xl bg-white shadow-border">
        <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 p-4">
            <div class="w-56">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari mata kuliah, ruangan, atau hari..."
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
            <div class="w-56">
                {{-- wire:key terikat filterProdi/filterSemester: x-searchable-select memakai
                     wire:ignore, jadi kalau prodi/semester berganti elemen ini harus benar-benar
                     diganti (bukan di-patch) supaya opsi kelas yang baru ikut termuat. --}}
                <x-searchable-select
                    wire:key="filter-kelas-select-{{ $filterProdi }}-{{ $filterSemester }}"
                    model="filterKelas"
                    :live="true"
                    :options="$kelasOptions"
                    optionLabel="label"
                    placeholder="Semua kelas"
                />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Mata Kuliah</th>
                        <th class="px-4 py-3">Prodi</th>
                        <th class="px-4 py-3">Pertemuan</th>
                        <th class="px-4 py-3">Hari / Tanggal</th>
                        <th class="px-4 py-3">Jam</th>
                        <th class="px-4 py-3">Ruang</th>
                        <th class="px-4 py-3">Status Sesi</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($jadwalList as $jadwal)
                        <tr wire:key="jadwal-{{ $jadwal->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-neutral-900">
                                    {{ $jadwal->kelas?->kurikulumMatkul?->matkul?->kode ? "{$jadwal->kelas->kurikulumMatkul->matkul->kode} - " : '' }}{{ $jadwal->kelas?->kurikulumMatkul?->matkul?->nama ?? '—' }}
                                </div>
                                <div class="text-xs text-neutral-500">
                                    {{ $jadwal->is_active ? 'Aktif' : 'Tidak Aktif' }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-neutral-600">
                                {{ $jadwal->kelas?->prodi ? ($jadwal->kelas->prodi->jenjang?->kode ? "{$jadwal->kelas->prodi->nama} ({$jadwal->kelas->prodi->jenjang->kode})" : $jadwal->kelas->prodi->nama) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-neutral-600 tabular-nums">
                                {{ $jadwal->urutan_pertemuan !== null ? "Ke-{$jadwal->urutan_pertemuan}" : '—' }}
                            </td>
                            <td class="px-4 py-3 text-neutral-600">
                                {{ $jadwal->hari ? ucfirst($jadwal->hari) : '—' }}
                                @if ($jadwal->tanggal)
                                    <br /><span class="text-xs text-neutral-500">{{ $jadwal->tanggal->format('d M Y') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-neutral-600 tabular-nums whitespace-nowrap">
                                {{ $jadwal->jam_mulai && $jadwal->jam_selesai ? "{$jadwal->jam_mulai} – {$jadwal->jam_selesai}" : ($jadwal->jam_mulai ?? $jadwal->jam_selesai ?? '—') }}
                            </td>
                            <td class="px-4 py-3 text-neutral-600">{{ $jadwal->ruangan?->nama ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @php
                                    $sesiClass = match ($jadwal->sesi_status) {
                                        'sedang_berlangsung' => 'bg-sky-50 text-sky-700',
                                        'selesai' => 'bg-emerald-50 text-emerald-700',
                                        default => 'bg-neutral-100 text-neutral-600',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $sesiClass }}">
                                    {{ $jadwal->sesi_status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a
                                        href="{{ route('admin.akademik.jadwal.show', $jadwal->id) }}{{ $returnQuery ? '?' . $returnQuery : '' }}"
                                        class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                        title="Lihat detail"
                                    >
                                        <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                    </a>
                                    <a
                                        href="{{ route('admin.akademik.jadwal.edit', $jadwal->id) }}{{ $returnQuery ? '?' . $returnQuery : '' }}"
                                        class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                        title="Ubah"
                                    >
                                        <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>
                                    </a>
                                    <button
                                        type="button"
                                        wire:click="confirmDelete({{ $jadwal->id }})"
                                        class="inline-flex items-center justify-center rounded-lg p-2 text-rose-500 transition hover:bg-rose-50 hover:text-rose-700"
                                        title="Hapus"
                                    >
                                        <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-neutral-500">Belum ada data jadwal.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 p-4">
            {{ $jadwalList->links() }}
        </div>
    </div>

    @if ($confirmingDeleteId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Hapus jadwal?</h3>
                <p class="mt-2 text-sm text-neutral-600">Tindakan ini tidak dapat dibatalkan.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        wire:click="cancelDelete"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        wire:click="delete"
                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700"
                    >
                        Hapus
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
