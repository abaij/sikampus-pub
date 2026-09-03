@php
    $statusBadgeClass = function (?string $nama) {
        $nama = mb_strtolower(trim((string) $nama));
        return match (true) {
            $nama === '' => 'bg-neutral-100 text-neutral-600',
            str_contains($nama, 'aktif') => 'bg-emerald-50 text-emerald-700',
            str_contains($nama, 'cuti') => 'bg-amber-50 text-amber-700',
            str_contains($nama, 'lulus') => 'bg-blue-50 text-blue-700',
            str_contains($nama, 'dropout') => 'bg-rose-50 text-rose-700',
            default => 'bg-neutral-100 text-neutral-600',
        };
    };
@endphp

@section('title', 'Mahasiswa — ' . config('app.name'))
@section('header_title', 'Mahasiswa')
@section('header_subtitle', 'Data induk mahasiswa')
@section('header_icon', 'graduation-cap')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Administrasi'],
        ['label' => 'Mahasiswa'],
    ]])
@endsection

@section('page_actions')
    @if (\App\Support\PanelAccess::can(auth()->user(), 'mahasiswa', 'create'))
        <a
            href="{{ route('admin.administrasi.mahasiswa.create') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
        >
            <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
            Tambah Mahasiswa
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
    @if (session('error'))
        <div class="mb-4 flex gap-3 rounded-lg border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <i data-lucide="alert-circle" class="h-5 w-5 shrink-0 text-rose-600" aria-hidden="true"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    {{--
        Tombol Export sengaja ditaruh di dalam <div> root (bukan @section('page_actions')) —
        page_actions dirender layouts.web DI LUAR subtree yang di-morph Livewire, jadi href-nya
        tidak akan pernah ikut ter-update saat filter berubah. Di sini href dihitung ulang setiap
        render supaya file yang di-export selalu sesuai filter yang sedang dipilih.
    --}}
    <div class="mb-4 flex justify-end">
        <a
            href="{{ route('admin.administrasi.mahasiswa.export', array_filter([
                'search' => $search !== '' ? $search : null,
                'id_prodi' => $filterProdi !== '' ? $filterProdi : null,
                'id_kelompok_kelas' => $filterKelompokKelas !== '' ? $filterKelompokKelas : null,
                'id_semester_masuk' => $filterSemesterMasuk !== '' ? $filterSemesterMasuk : null,
                'id_status_akademik' => $filterStatusAkademik !== '' ? $filterStatusAkademik : null,
            ])) }}"
            class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
        >
            <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
            Export Excel
        </a>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        <div class="space-y-4 border-b border-neutral-200 p-4">
            <div class="relative">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Cari nama, NIM, atau email..."
                    class="w-full rounded-lg py-2 pl-9 pr-3 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                />
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Prodi</label>
                    <x-searchable-select
                        model="filterProdi"
                        :live="true"
                        :options="$this->prodiOptions->mapWithKeys(fn ($opt) => [$opt->id => $opt->nama.($opt->jenjang ? ' - '.$opt->jenjang->kode : '')])->all()"
                        placeholder="Semua Prodi"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Kelas Mahasiswa</label>
                    {{-- wire:key terikat filterProdi: x-searchable-select memakai wire:ignore, jadi
                         kalau prodi berganti elemen ini harus benar-benar diganti (bukan di-patch)
                         supaya opsi kelas mahasiswa yang baru (hasil filter prodi) ikut termuat. --}}
                    <x-searchable-select
                        wire:key="filter-kelompok-kelas-select-{{ $filterProdi }}"
                        model="filterKelompokKelas"
                        :live="true"
                        :options="$this->kelompokKelasOptions"
                        placeholder="Semua Kelas Mahasiswa"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Semester Masuk</label>
                    <x-searchable-select
                        model="filterSemesterMasuk"
                        :live="true"
                        :options="$this->semesterOptions"
                        placeholder="Semua Semester"
                    />
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold text-neutral-700">Status Akademik</label>
                    <x-searchable-select
                        model="filterStatusAkademik"
                        :live="true"
                        :options="$this->statusAkademikOptions"
                        placeholder="Semua Status"
                    />
                </div>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
                <input
                    type="checkbox"
                    wire:model.live="showTrashed"
                    class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10"
                />
                Tampilkan mahasiswa yang sudah dihapus
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-3">Nama</th>
                        <th class="px-4 py-3">Prodi</th>
                        <th class="px-4 py-3">Kelas Mahasiswa</th>
                        <th class="px-4 py-3">Thn Masuk</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    @forelse ($mahasiswaList as $mhs)
                        <tr wire:key="mhs-{{ $mhs->id }}" class="{{ $mhs->trashed() ? 'bg-neutral-50 text-neutral-500' : '' }}">
                            <td class="px-4 py-3">
                                <div class="font-medium text-neutral-900">{{ $mhs->nama }}</div>
                                <div class="text-xs text-neutral-500">{{ $mhs->nim ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-neutral-600">
                                {{ $mhs->prodi->nama ?? '—' }}
                                @if ($mhs->prodi?->kode)
                                    <div class="text-xs text-neutral-400">{{ $mhs->prodi->kode }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-neutral-600">{{ $mhs->kelompok_kelas->nama ?? '—' }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ $mhs->semester_masuk->nama ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($mhs->trashed())
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-700">
                                        Dihapus
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusBadgeClass($mhs->status_akademik->nama ?? null) }}">
                                        {{ $mhs->status_akademik->nama ?? '—' }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    @if ($mhs->trashed())
                                        @if (\App\Support\PanelAccess::can(auth()->user(), 'mahasiswa', 'delete'))
                                            <button
                                                type="button"
                                                wire:click="restore({{ $mhs->id }})"
                                                class="inline-flex items-center justify-center rounded-lg p-2 text-emerald-600 transition hover:bg-emerald-50 hover:text-emerald-700"
                                                title="Pulihkan"
                                            >
                                                <i data-lucide="rotate-ccw" class="h-4 w-4" aria-hidden="true"></i>
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="confirmForceDelete({{ $mhs->id }})"
                                                class="inline-flex items-center justify-center rounded-lg p-2 text-rose-600 transition hover:bg-rose-50 hover:text-rose-800"
                                                title="Hapus Permanen"
                                            >
                                                <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                                            </button>
                                        @else
                                            <span class="text-xs text-neutral-400">—</span>
                                        @endif
                                    @else
                                        <a
                                            href="{{ route('admin.administrasi.mahasiswa.show', $mhs->id) }}{{ $returnQuery ? '?'.$returnQuery : '' }}"
                                            class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                            title="Lihat Detail"
                                        >
                                            <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-neutral-500">Belum ada data mahasiswa.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-neutral-200 p-4">
            {{ $mahasiswaList->links() }}
        </div>
    </div>

    @if ($confirmingForceDeleteId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Hapus permanen mahasiswa?</h3>
                <p class="mt-2 text-sm text-neutral-600">Data akan benar-benar dihapus dari database dan tidak bisa dipulihkan lagi — berbeda dari hapus biasa. Tindakan ini tidak dapat dibatalkan.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelForceDelete" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                        Batal
                    </button>
                    <button type="button" wire:click="forceDeleteMahasiswa" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700">
                        Hapus Permanen
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
