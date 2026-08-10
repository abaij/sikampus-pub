@section('title', ($kurikulumId ? 'Ubah' : 'Tambah') . ' Kurikulum — ' . config('app.name'))
@section('header_title', ($kurikulumId ? 'Ubah' : 'Tambah') . ' Kurikulum')
@section('header_icon', 'library-big')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Akademik'],
        ['label' => 'Kurikulum', 'route' => route('admin.akademik.kurikulum')],
        ['label' => $kurikulumId ? 'Ubah' : 'Tambah'],
    ]])
@endsection

<div>
    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-4 text-base font-semibold text-neutral-900">Informasi Dasar</h2>
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
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kode *</label>
                    <input type="text" wire:model="kode" placeholder="KUR2024" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('kode') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('kode') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nama *</label>
                    <input type="text" wire:model="nama" placeholder="Kurikulum 2024" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('nama') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('nama') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Periode Mulai Berlaku *</label>
                    <x-searchable-select
                        model="id_tahun_berlaku"
                        :options="$semesterOptions"
                        optionLabel="label"
                        placeholder="— Pilih periode mulai berlaku —"
                    />
                    @error('id_tahun_berlaku') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">SKS Wajib Minimal</label>
                    <input type="number" min="0" max="1000" wire:model="sks_wajib_minimal" placeholder="144" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('sks_wajib_minimal') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('sks_wajib_minimal') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Status</label>
                    <x-searchable-select
                        model="status"
                        :clearable="false"
                        :options="['active' => 'Aktif', 'inactive' => 'Tidak Aktif']"
                    />
                    @error('status') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Deskripsi</label>
                    <textarea wire:model="deskripsi" rows="3" placeholder="Deskripsi kurikulum" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    @error('deskripsi') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="mb-1 text-base font-semibold text-neutral-900">Mata Kuliah</h2>
            @if (! $id_prodi)
                <p class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Pilih Program Studi terlebih dahulu untuk melihat daftar mata kuliah yang tersedia.
                </p>
            @elseif ($matkulOptions->isEmpty())
                <p class="text-sm text-neutral-500">Tidak ada mata kuliah untuk prodi ini.</p>
            @else
                <div class="mb-4 flex items-center justify-between gap-3">
                    <p class="text-sm text-neutral-500">Centang mata kuliah yang termasuk dalam kurikulum ini, lalu atur semester rekomendasi dan status wajib/pilihan.</p>
                    @php
                        $matkulOptionIds = $matkulOptions->pluck('id')->all();
                        $isAllMatkulSelected = count($matkulOptionIds) > 0 && empty(array_diff($matkulOptionIds, $selectedMatkulIds));
                    @endphp
                    <button
                        type="button"
                        wire:click="toggleSelectAllMatkul"
                        class="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border"
                    >
                        {{ $isAllMatkulSelected ? 'Hapus Semua' : 'Pilih Semua' }}
                    </button>
                </div>
                <div class="max-h-96 overflow-y-auto overflow-x-auto rounded-xl shadow-border">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th class="px-4 py-3">
                                    {{-- wire:key berubah setiap $isAllMatkulSelected berganti supaya Livewire mengganti
                                    elemen ini (bukan sekadar patch atribut) — checkbox tanpa wire:model tidak otomatis
                                    disinkronkan propertinya oleh morph, dan browser mengabaikan perubahan atribut
                                    "checked" begitu elemen pernah disentuh user. Recreate elemen menghindari itu. --}}
                                    <input
                                        type="checkbox"
                                        wire:click="toggleSelectAllMatkul"
                                        wire:key="matkul-select-all-{{ $isAllMatkulSelected ? 1 : 0 }}"
                                        @checked($isAllMatkulSelected)
                                        class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10"
                                    />
                                </th>
                                <th class="px-4 py-3">Kode</th>
                                <th class="px-4 py-3">Nama</th>
                                <th class="px-4 py-3">SKS</th>
                                <th class="px-4 py-3">Semester Rekomendasi</th>
                                <th class="px-4 py-3">Wajib</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            @foreach ($matkulOptions as $matkul)
                                @php $isSelected = in_array($matkul->id, $selectedMatkulIds); @endphp
                                <tr wire:key="form-matkul-{{ $matkul->id }}" class="{{ $isSelected ? 'bg-neutral-50' : '' }}">
                                    <td class="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            wire:model.live="selectedMatkulIds"
                                            value="{{ $matkul->id }}"
                                            class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10"
                                        />
                                    </td>
                                    <td class="px-4 py-3 font-mono font-medium text-neutral-900">{{ $matkul->kode }}</td>
                                    <td class="px-4 py-3 text-neutral-900">{{ $matkul->nama }}</td>
                                    <td class="px-4 py-3 text-neutral-600">{{ $matkul->sks ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($isSelected)
                                            <input
                                                type="number"
                                                min="1"
                                                max="14"
                                                wire:model="matkulDetail.{{ $matkul->id }}.semester_rekomendasi"
                                                class="w-20 rounded-lg px-2 py-1.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                                            />
                                        @else
                                            <span class="text-neutral-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($isSelected)
                                            <input
                                                type="checkbox"
                                                wire:model="matkulDetail.{{ $matkul->id }}.is_wajib"
                                                class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10"
                                            />
                                        @else
                                            <span class="text-neutral-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
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
