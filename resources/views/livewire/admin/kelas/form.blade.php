@section('title', ($kelasId ? 'Ubah' : 'Tambah') . ' Kelas — ' . config('app.name'))
@section('header_title', ($kelasId ? 'Ubah' : 'Tambah') . ' Kelas')
@section('header_icon', 'presentation')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Kelas', 'route' => route('admin.akademik.kelas')],
        ['label' => $kelasId ? 'Ubah' : 'Tambah'],
    ]])
@endsection

<div>
    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-4 text-base font-semibold text-neutral-900">Informasi Kelas</h2>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Program Studi *</label>
                    <x-searchable-select
                        model="id_prodi"
                        :live="true"
                        :options="$prodiOptions"
                        optionLabel="label"
                        placeholder="— Pilih program studi —"
                    />
                    @error('id_prodi') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kurikulum Mata Kuliah *</label>
                    @if (! $id_prodi)
                        <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            Pilih program studi terlebih dahulu.
                        </p>
                    @else
                        {{-- wire:key terikat id_prodi: x-searchable-select memakai wire:ignore, jadi
                             kalau prodi berganti elemen ini harus benar-benar diganti (bukan di-patch)
                             supaya opsi kurikulum mata kuliah yang baru ikut termuat. --}}
                        <x-searchable-select
                            wire:key="kurikulum-matkul-select-{{ $id_prodi }}"
                            model="id_kurikulum_matkul"
                            :options="$this->kurikulumMatkulOptions"
                            optionLabel="label"
                            placeholder="— Pilih mata kuliah —"
                        />
                    @endif
                    @error('id_kurikulum_matkul') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Semester Berjalan *</label>
                    <x-searchable-select
                        model="id_semester"
                        :options="$semesterOptions"
                        optionLabel="label"
                        placeholder="— Pilih semester —"
                    />
                    @error('id_semester') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Angkatan (cohort) *</label>
                    <x-searchable-select
                        model="id_angkatan"
                        :options="$semesterOptions"
                        optionLabel="label"
                        placeholder="— Semester referensi angkatan —"
                    />
                    <p class="mt-1 text-xs text-neutral-500">Semester <em>masuk</em> mahasiswa, bukan semester berjalan — terisi otomatis dari kelas mahasiswa di bawah. Kelas hanya muncul di pengajuan KRS mahasiswa yang angkatannya sama.</p>
                    @error('id_angkatan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kelas Mahasiswa</label>
                    {{-- :live supaya updatedIdKelompokKelas() jalan dan mengisi angkatan dari
                         semester masuk mahasiswa di rombongan ini. --}}
                    <x-searchable-select
                        model="id_kelompok_kelas"
                        :options="$kelompokKelasOptions"
                        placeholder="— Opsional —"
                        :live="true"
                    />
                    @error('id_kelompok_kelas') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kode Kelas</label>
                    <input type="text" maxlength="255" wire:model="kode" placeholder="Opsional" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('kode') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('kode') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jumlah Pertemuan</label>
                    <input type="number" min="1" max="99" wire:model="jml_pertemuan" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('jml_pertemuan') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('jml_pertemuan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kuota</label>
                    <input type="number" min="0" max="32767" wire:model="kuota" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('kuota') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('kuota') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-2 pt-7">
                    <input type="checkbox" wire:model="is_mingguan" id="is_mingguan" class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                    <label for="is_mingguan" class="text-sm font-medium text-neutral-700">Pertemuan mingguan</label>
                </div>

                <div class="flex items-center gap-2 pt-7">
                    <input type="checkbox" wire:model="is_active" id="is_active" class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                    <label for="is_active" class="text-sm font-medium text-neutral-700">Kelas aktif</label>
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-4 text-base font-semibold text-neutral-900">Pengajar</h2>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Dosen Penanggung Jawab (PIC)</label>
                    <x-searchable-select
                        model="id_dosen_pic"
                        :options="$dosenOptions"
                        optionLabel="label"
                        placeholder="— Opsional —"
                    />
                    @error('id_dosen_pic') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Tim Pengampu Tambahan</label>
                    <p class="mb-2 text-xs text-neutral-500">Dosen selain PIC yang ikut mengampu kelas ini.</p>

                    @if (! empty($dosenTimIds))
                        <div class="mb-3 flex flex-wrap gap-2">
                            @foreach ($dosenTimIds as $id)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-neutral-100 py-1 pl-3 pr-1.5 text-sm text-neutral-800">
                                    {{ $dosenLabelById[$id] ?? "Dosen #{$id}" }}
                                    <button type="button" wire:click="removeDosenTim({{ $id }})" class="rounded-full p-0.5 text-neutral-500 transition hover:bg-neutral-200 hover:text-neutral-900">
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
                                    wire:click="addDosenTim({{ $dosen->id }})"
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
            </div>
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
