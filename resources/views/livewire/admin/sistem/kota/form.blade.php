@section('title', ($kotaId ? 'Ubah' : 'Tambah') . ' Kota — ' . config('app.name'))
@section('header_title', ($kotaId ? 'Ubah' : 'Tambah') . ' Kota')
@section('header_icon', 'building-2')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Pengaturan'],
        ['label' => 'Sistem'],
        ['label' => 'Kota', 'route' => route('admin.sistem.kota')],
        ['label' => $kotaId ? 'Ubah' : 'Tambah'],
    ]])
@endsection

<div>
    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nama Kota *</label>
                    <input type="text" wire:model="nama" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('nama') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('nama') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kode *</label>
                    <input type="text" wire:model="kode" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('kode') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('kode') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Filter Negara</label>
                    <x-searchable-select
                        model="filterNegara"
                        :live="true"
                        :options="$negaraOptions"
                        placeholder="Semua negara"
                    />
                    <p class="mt-1.5 text-xs text-neutral-500">Hanya menyaring daftar provinsi di bawah, tidak disimpan.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Provinsi</label>
                    {{-- wire:key terikat filterNegara: x-searchable-select memakai wire:ignore, jadi
                         kalau negara berganti elemen ini harus benar-benar diganti (bukan di-patch)
                         supaya opsi provinsi yang baru (hasil filter negara) ikut termuat. --}}
                    <x-searchable-select
                        wire:key="id-provinsi-select-{{ $filterNegara }}"
                        model="id_provinsi"
                        :options="$provinsiOptions"
                        placeholder="— Pilih provinsi —"
                    />
                    @error('id_provinsi') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.sistem.kota') }}" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                Batal
            </a>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                Simpan
            </button>
        </div>
    </form>
</div>
