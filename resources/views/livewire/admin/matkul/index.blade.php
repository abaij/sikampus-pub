@section('title', 'Mata Kuliah — ' . config('app.name'))
@section('header_title', 'Mata Kuliah')
@section('header_subtitle', 'Data master mata kuliah')
@section('header_icon', 'book-open')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Mata Kuliah'],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ route('admin.akademik.matkul.template') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
        Download Template
    </a>
    <a
        href="{{ route('admin.akademik.matkul.import') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
        Import Mata Kuliah
    </a>
    <a
        href="{{ route('admin.akademik.matkul.create') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
    >
        <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
        Tambah Mata Kuliah
    </a>
@endsection

<div>
    @if (session('status'))
        <div class="mb-4 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif
    @if (session('error'))
        <div class="mb-4 flex gap-3 rounded-lg border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <i data-lucide="alert-circle" class="h-5 w-5 shrink-0 text-rose-600" aria-hidden="true"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <div class="rounded-2xl bg-white shadow-border">
        <div class="space-y-4 border-b border-neutral-200 p-4">
            <div class="relative">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari kode atau nama mata kuliah..."
                    class="w-full rounded-lg py-2 pl-9 pr-3 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                />
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Program Studi</label>
                    <x-searchable-select
                        model="filterProdi"
                        :live="true"
                        :options="$prodiOptions"
                        optionLabel="label"
                        placeholder="Semua prodi"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Jenis Mata Kuliah</label>
                    <x-searchable-select
                        model="filterJenisMatkul"
                        :live="true"
                        :options="$jenisMatkulOptions"
                        optionLabel="label"
                        placeholder="Semua jenis"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Semester</label>
                    <x-searchable-select
                        model="filterSemester"
                        :live="true"
                        :options="array_combine(range(1, 8), range(1, 8))"
                        placeholder="Semua"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Status</label>
                    <x-searchable-select
                        model="filterStatus"
                        :live="true"
                        :options="['active' => 'Aktif', 'inactive' => 'Tidak Aktif']"
                        placeholder="Semua"
                    />
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                <input
                    type="checkbox"
                    wire:model.live="showTrashed"
                    class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10"
                />
                Tampilkan mata kuliah yang sudah dihapus
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Kode</th>
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">SKS</th>
                        <th class="px-4 py-3">Semester</th>
                        <th class="px-4 py-3">Prodi</th>
                        <th class="px-4 py-3">Jenis</th>
                        <th class="px-4 py-3 text-center">MK Prasyarat</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($matkulList as $matkul)
                        <tr wire:key="matkul-{{ $matkul->id }}" class="{{ $matkul->trashed() ? 'bg-neutral-50 text-neutral-500' : '' }}">
                            <td class="px-4 py-3 font-mono font-medium text-neutral-900">{{ $matkul->kode }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-neutral-900">{{ $matkul->nama }}</div>
                                @if ($matkul->nama_en)
                                    <div class="text-xs text-neutral-500">{{ $matkul->nama_en }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-neutral-600">{{ $matkul->sks ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $matkul->semester ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">
                                {{ $matkul->prodi ? $matkul->prodi->nama . ($matkul->prodi->jenjang?->kode ? " ({$matkul->prodi->jenjang->kode})" : '') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-neutral-600">
                                {{ $matkul->jenisMatkul ? ($matkul->jenisMatkul->kode ? "{$matkul->jenisMatkul->nama} ({$matkul->jenisMatkul->kode})" : $matkul->jenisMatkul->nama) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $matkul->matkul_prasyarat_links_count > 0 ? 'bg-sky-100 text-sky-800' : 'bg-neutral-100 text-neutral-600' }}">
                                    {{ $matkul->matkul_prasyarat_links_count > 0 ? 'Ada' : 'Tidak Ada' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($matkul->trashed())
                                    <span class="inline-flex rounded-full bg-rose-100 px-2 py-1 text-xs font-semibold text-rose-700">
                                        Dihapus
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {{ $matkul->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-neutral-100 text-neutral-700' }}">
                                        {{ $matkul->status === 'active' ? 'Aktif' : 'Tidak Aktif' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    @if ($matkul->trashed())
                                        <button
                                            type="button"
                                            wire:click="restore({{ $matkul->id }})"
                                            class="inline-flex items-center justify-center rounded-lg p-2 text-emerald-600 transition hover:bg-emerald-50 hover:text-emerald-700"
                                            title="Pulihkan"
                                        >
                                            <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
                                        </button>
                                    @else
                                        <a
                                            href="{{ route('admin.akademik.matkul.show', $matkul->id) }}{{ $returnQuery ? '?' . $returnQuery : '' }}"
                                            class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                            title="Lihat"
                                        >
                                            <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                        </a>
                                        <a
                                            href="{{ route('admin.akademik.matkul.edit', $matkul->id) }}{{ $returnQuery ? '?' . $returnQuery : '' }}"
                                            class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                            title="Ubah"
                                        >
                                            <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>
                                        </a>
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $matkul->id }})"
                                            class="inline-flex items-center justify-center rounded-lg p-2 text-rose-500 transition hover:bg-rose-50 hover:text-rose-700"
                                            title="Hapus"
                                        >
                                            <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-neutral-500">Belum ada data mata kuliah.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 p-4">
            {{ $matkulList->links() }}
        </div>
    </div>

    @if ($confirmingDeleteId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Hapus mata kuliah?</h3>
                <p class="mt-2 text-sm text-neutral-600">Tindakan ini tidak dapat dibatalkan.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelDelete" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                        Batal
                    </button>
                    <button type="button" wire:click="delete" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700">
                        Hapus
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
