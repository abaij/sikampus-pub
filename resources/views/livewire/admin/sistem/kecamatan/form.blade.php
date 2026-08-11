@section('title', ($kecamatanId ? 'Ubah' : 'Tambah') . ' Kecamatan — ' . config('app.name'))
@section('header_title', ($kecamatanId ? 'Ubah' : 'Tambah') . ' Kecamatan')
@section('header_icon', 'map-pin')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Pengaturan'],
        ['label' => 'Sistem'],
        ['label' => 'Kecamatan', 'route' => route('admin.sistem.kecamatan')],
        ['label' => $kecamatanId ? 'Ubah' : 'Tambah'],
    ]])
@endsection

<div>
    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nama Kecamatan *</label>
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
                    <p class="mt-1.5 text-xs text-neutral-500">Hanya menyaring daftar provinsi &amp; kota di bawah, tidak disimpan.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Filter Provinsi</label>
                    {{-- wire:key terikat filterNegara: lihat catatan yang sama di Kota\form.blade.php. --}}
                    <x-searchable-select
                        wire:key="filter-provinsi-select-{{ $filterNegara }}"
                        model="filterProvinsi"
                        :live="true"
                        :options="$provinsiOptions"
                        placeholder="Semua provinsi"
                    />
                    <p class="mt-1.5 text-xs text-neutral-500">Hanya menyaring daftar kota di bawah, tidak disimpan.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kota</label>
                    {{-- wire:key terikat filterNegara+filterProvinsi supaya opsi kota ikut termuat ulang. --}}
                    <x-searchable-select
                        wire:key="id-kota-select-{{ $filterNegara }}-{{ $filterProvinsi }}"
                        model="id_kota"
                        :options="$kotaOptions"
                        placeholder="— Pilih kota —"
                    />
                    @error('id_kota') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.sistem.kecamatan') }}" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                Batal
            </a>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                Simpan
            </button>
        </div>
    </form>
</div>
