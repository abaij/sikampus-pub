@section('title', ($jadwalId ? 'Ubah' : 'Tambah') . ' Jadwal — ' . config('app.name'))
@section('header_title', ($jadwalId ? 'Ubah' : 'Tambah') . ' Jadwal')
@section('header_icon', 'calendar-clock')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Jadwal', 'route' => route('admin.akademik.jadwal')],
        ['label' => $jadwalId ? 'Ubah' : 'Tambah'],
    ]])
@endsection

<div>
    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-4 text-base font-semibold text-neutral-900">Kelas</h2>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Filter Prodi</label>
                    {{-- :live wajib: updatedFilterProdi() di Form.php dan wire:key kelas di bawah
                         baru berjalan kalau nilainya sampai ke server begitu dipilih, bukan menunggu
                         request lain (mis. saat mengetik di pencarian dosen). --}}
                    <x-searchable-select
                        model="filterProdi"
                        :live="true"
                        :options="$prodiOptions"
                        optionLabel="label"
                        placeholder="— Semua prodi —"
                    />
                    <p class="mt-1 text-xs text-neutral-500">Hanya menyaring daftar kelas di bawah, tidak disimpan.</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Filter Semester</label>
                    <x-searchable-select
                        model="filterSemester"
                        :live="true"
                        :options="$semesterOptions"
                        optionLabel="label"
                        placeholder="— Semua semester —"
                    />
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kelas *</label>
                    {{-- wire:key terikat filterProdi/filterSemester: x-searchable-select memakai
                         wire:ignore, jadi kalau filternya berganti elemen ini harus benar-benar
                         diganti (bukan di-patch) supaya opsi kelas yang baru ikut termuat. --}}
                    <x-searchable-select
                        wire:key="id-kelas-select-{{ $filterProdi }}-{{ $filterSemester }}"
                        model="id_kelas"
                        :options="$this->kelasOptions"
                        optionLabel="label"
                        placeholder="— Pilih kelas —"
                    />
                    @error('id_kelas') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-4 text-base font-semibold text-neutral-900">Informasi Pertemuan</h2>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                @if (! $jadwalId)
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jumlah Pertemuan *</label>
                        <input type="number" min="1" max="99" wire:model="jumlah_pertemuan" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('jumlah_pertemuan') ring-2 ring-red-500 @enderror shadow-border" />
                        <p class="mt-1 text-xs text-neutral-500">Membuat sekaligus N slot pertemuan (ke-1 sampai ke-N) untuk kelas ini.</p>
                        @error('jumlah_pertemuan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Pertemuan Ke- *</label>
                        <input type="number" min="1" max="99" wire:model="urutan_pertemuan" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('urutan_pertemuan') ring-2 ring-red-500 @enderror shadow-border" />
                        @error('urutan_pertemuan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jenis Kuliah</label>
                    <x-searchable-select
                        model="id_jenis_kuliah"
                        :options="$jenisKuliahOptions"
                        placeholder="— Opsional —"
                    />
                    @error('id_jenis_kuliah') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">
                        {{ $jadwalId ? 'Tanggal' : 'Tanggal Mulai' }}
                    </label>
                    <input type="date" wire:model="tanggal" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tanggal') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('tanggal') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                @if (! $jadwalId)
                    <div class="flex items-center gap-2 pt-7">
                        <input type="checkbox" wire:model="tanggal_hari_otomatis" id="tanggal_hari_otomatis" class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                        <label for="tanggal_hari_otomatis" class="text-sm font-medium text-neutral-700">Tanggal &amp; hari otomatis per minggu</label>
                    </div>
                @endif

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Hari</label>
                    <x-searchable-select
                        model="hari"
                        :options="$hariOptions"
                        placeholder="— Opsional —"
                    />
                    @error('hari') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-2 pt-7">
                    <input type="checkbox" wire:model="is_active" id="is_active" class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                    <label for="is_active" class="text-sm font-medium text-neutral-700">Jadwal aktif</label>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jam Mulai</label>
                    <input type="time" wire:model="jam_mulai" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('jam_mulai') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('jam_mulai') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jam Selesai</label>
                    <input type="time" wire:model="jam_selesai" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('jam_selesai') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('jam_selesai') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Ruangan</label>
                    <x-searchable-select
                        model="id_ruangan"
                        :options="$ruanganOptions"
                        placeholder="— Opsional —"
                    />
                    @error('id_ruangan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-1 text-base font-semibold text-neutral-900">Dosen Pengajar</h2>
            <p class="mb-3 text-xs text-neutral-500">Dosen yang mengajar pada slot pertemuan ini.</p>

            @if (! empty($dosenIds))
                <div class="mb-3 flex flex-wrap gap-2">
                    @foreach ($dosenIds as $id)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 py-1 pl-3 pr-1.5 text-sm text-neutral-800">
                            {{ $dosenLabelById[$id] ?? "Dosen #{$id}" }}
                            <button type="button" wire:click="removeDosen({{ $id }})" class="rounded-full p-0.5 text-neutral-500 transition hover:bg-neutral-200 hover:text-neutral-900">
                                <i data-lucide="x" class="h-3.5 w-3.5" aria-hidden="true"></i>
                            </button>
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="relative">
                <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" aria-hidden="true"></i>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="dosenSearch"
                    placeholder="Ketik nama atau kode dosen untuk mencari..."
                    class="w-full rounded-lg py-2 pl-9 pr-3 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                />
            </div>

            @if ($dosenSearch !== '')
                <div class="mt-2 max-h-56 overflow-y-auto rounded-lg shadow-border">
                    @forelse ($this->dosenSearchResults as $dosen)
                        <button
                            type="button"
                            wire:click="addDosen({{ $dosen->id }})"
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm text-neutral-700 transition hover:bg-neutral-50"
                        >
                            <span>{{ trim(($dosen->gelar_depan ? $dosen->gelar_depan . ' ' : '') . $dosen->nama . ($dosen->gelar_belakang ? ', ' . $dosen->gelar_belakang : '')) }}</span>
                            <span class="text-xs text-neutral-400">{{ $dosen->kode_dosen }}</span>
                        </button>
                    @empty
                        <p class="px-3 py-2 text-sm text-neutral-500">Tidak ada dosen yang cocok.</p>
                    @endforelse
                </div>
            @endif
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ $backUrl }}" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                Batal
            </a>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                Simpan
            </button>
        </div>
    </form>
</div>
