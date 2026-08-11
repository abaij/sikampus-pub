@section('title', 'Kelas Mahasiswa — ' . config('app.name'))
@section('header_title', 'Kelas Mahasiswa')
@section('header_subtitle', 'Data master kelas/kelompok mahasiswa')
@section('header_icon', 'users-round')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Administrasi'],
        ['label' => 'Mahasiswa'],
        ['label' => 'Kelas Mahasiswa'],
    ]])
@endsection

@section('page_actions')
    @if (\App\Support\PanelAccess::can(auth()->user(), 'grup mahasiswa', 'create'))
        <a
            href="{{ route('admin.administrasi.kelas-mahasiswa.template') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
        >
            <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
            Download Template
        </a>
        <a
            href="{{ route('admin.administrasi.kelas-mahasiswa.import') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
        >
            <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
            Import Kelas Mahasiswa
        </a>
        <a
            href="{{ route('admin.administrasi.kelas-mahasiswa.create') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
        >
            <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
            Tambah Kelas Mahasiswa
        </a>
    @endif
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
            <div class="relative flex-1 min-w-[200px]">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari nama kelas mahasiswa..."
                    class="w-full rounded-lg py-2 pl-9 pr-3 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                />
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Prodi</th>
                        <th class="px-4 py-3">Keterangan</th>
                        <th class="px-4 py-3 text-center">Jumlah Mahasiswa</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($kelompokKelasList as $kelompokKelas)
                        <tr wire:key="kelompok-kelas-{{ $kelompokKelas->id }}">
                            <td class="px-4 py-3 font-medium text-neutral-900">{{ $kelompokKelas->nama }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $kelompokKelas->prodi->nama ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $kelompokKelas->keterangan ?? '—' }}</td>
                            <td class="px-4 py-3 text-center tabular-nums text-neutral-600">{{ $kelompokKelas->jumlah_mahasiswa ?? 0 }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    @if (\App\Support\PanelAccess::can(auth()->user(), 'grup mahasiswa', 'update'))
                                        <a
                                            href="{{ route('admin.administrasi.kelas-mahasiswa.edit', $kelompokKelas->id) }}"
                                            class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                            title="Ubah"
                                        >
                                            <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                    @if (\App\Support\PanelAccess::can(auth()->user(), 'grup mahasiswa', 'delete'))
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $kelompokKelas->id }})"
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
                            <td colspan="5" class="px-4 py-10 text-center text-neutral-500">Belum ada data kelas mahasiswa.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 p-4">
            {{ $kelompokKelasList->links() }}
        </div>
    </div>

    @if ($confirmingDeleteId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Hapus kelas mahasiswa?</h3>
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
