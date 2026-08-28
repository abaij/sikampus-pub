@section('title', ($keringananBiayaId ? 'Ubah' : 'Tambah') . ' Keringanan Biaya — ' . config('app.name'))
@section('header_title', ($keringananBiayaId ? 'Ubah' : 'Tambah') . ' Keringanan Biaya')
@section('header_icon', 'hand-coins')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Keuangan'],
        ['label' => 'Keringanan Biaya', 'route' => route('admin.keuangan.keringanan-biaya')],
        ['label' => $keringananBiayaId ? 'Ubah' : 'Tambah'],
    ]])
@endsection

<div>
    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h3 class="mb-4 text-sm font-semibold text-neutral-700">Mahasiswa</h3>

            @if ($keringananBiayaId)
                <div class="rounded-lg px-4 py-3 text-sm shadow-border">
                    <div class="font-semibold text-neutral-900">{{ $selectedMahasiswaLabel }}</div>
                    <p class="mt-1 text-xs text-neutral-500">Mahasiswa tidak dapat diubah dari halaman ini.</p>
                </div>
            @else
                @error('id_mahasiswa') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror

                @if ($id_mahasiswa)
                    <div class="flex items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm shadow-border">
                        <span class="font-semibold text-neutral-900">{{ $selectedMahasiswaLabel }}</span>
                        <button type="button" wire:click="clearMahasiswa" class="text-xs font-medium text-sky-600 hover:text-sky-700">
                            Ganti mahasiswa
                        </button>
                    </div>
                @else
                    <input
                        type="text"
                        wire:model.live.debounce.400ms="mahasiswaSearch"
                        placeholder="Cari nama atau NIM mahasiswa..."
                        class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                    />
                    @if ($mahasiswaSearch !== '')
                        <div class="mt-2 max-h-56 space-y-1 overflow-y-auto">
                            @forelse ($this->mahasiswaResults as $mhs)
                                <button
                                    type="button"
                                    wire:click="selectMahasiswa({{ $mhs->id }}, '{{ addslashes($mhs->nama.' ('.$mhs->nim.')') }}')"
                                    class="flex w-full items-center justify-between gap-3 rounded-lg px-4 py-2.5 text-left text-sm shadow-border transition hover:bg-neutral-50"
                                >
                                    <span class="font-medium text-neutral-900">{{ $mhs->nama }}</span>
                                    <span class="text-xs text-neutral-500">{{ $mhs->nim }}</span>
                                </button>
                            @empty
                                <p class="px-1 py-2 text-sm text-neutral-500">Tidak ditemukan mahasiswa yang cocok.</p>
                            @endforelse
                        </div>
                    @endif
                @endif
            @endif
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h3 class="mb-4 text-sm font-semibold text-neutral-700">Informasi Pengajuan</h3>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Semester *</label>
                    <x-searchable-select model="id_semester" :options="$this->semesterOptions" placeholder="— Pilih semester —" />
                    @error('id_semester') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jenis Keringanan Biaya *</label>
                    {{-- :live supaya isian Nominal ikut berganti saat jenis persentase dipilih. --}}
                    <x-searchable-select model="id_jenis_keringanan_biaya" :options="$this->jenisKeringananBiayaOptions" placeholder="— Pilih jenis —" :live="true" />
                    @error('id_jenis_keringanan_biaya') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    @if ($this->persentaseTerpilih !== null)
                        {{-- Jenis persentase: nominalnya ditentukan sistem saat disetujui, bukan diketik
                             admin, supaya persen tidak pernah tersimpan sebagai rupiah. --}}
                        @php($perkiraan = $this->perkiraanNominal)
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nominal (Rp)</label>
                        <div class="w-full rounded-lg bg-neutral-50 px-3 py-2.5 text-sm text-neutral-700 shadow-border">
                            @if ($perkiraan && $perkiraan['dasar'] > 0)
                                Rp{{ number_format($perkiraan['nominal'], 0, ',', '.') }}
                                <span class="text-neutral-500">
                                    ({{ rtrim(rtrim(number_format($perkiraan['persen'], 2, ',', '.'), '0'), ',') }}% dari
                                    Rp{{ number_format($perkiraan['dasar'], 0, ',', '.') }})
                                </span>
                            @elseif ($perkiraan)
                                <span class="text-amber-700">Belum ada tagihan pada semester itu — nominal belum bisa dihitung.</span>
                            @else
                                <span class="text-neutral-500">Pilih mahasiswa dan semester untuk melihat perkiraan nominal.</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-neutral-500">Dihitung sistem dari total tagihan semester saat status diubah menjadi Disetujui.</p>
                    @else
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nominal (Rp) *</label>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model="nominal"
                            placeholder="0"
                            class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('nominal') ring-2 ring-red-500 @enderror shadow-border"
                        />
                    @endif
                    @error('nominal') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    @error('status') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Aturan Akses Keuangan</label>
                    <x-searchable-select model="id_aturan_akses_keuangan" :options="$this->aturanAksesKeuanganOptions" placeholder="— Tidak terikat aturan tertentu —" />
                    @error('id_aturan_akses_keuangan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Status *</label>
                    <select wire:model="status" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('status') ring-2 ring-red-500 @enderror shadow-border">
                        @foreach ($this->statusOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('status') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Tanggal Pengajuan</label>
                    <input
                        type="date"
                        wire:model="tanggal_pengajuan"
                        class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tanggal_pengajuan') ring-2 ring-red-500 @enderror shadow-border"
                    />
                    @error('tanggal_pengajuan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Keterangan</label>
                    <textarea wire:model="keterangan" rows="3" placeholder="Keterangan pengajuan (opsional)" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Lampiran</label>
                    @if ($existingFileLampiranUrl)
                        <p class="mb-2 text-xs text-neutral-500">
                            <a href="{{ $existingFileLampiranUrl }}" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-600 hover:text-sky-700">Lihat lampiran saat ini</a>
                            — unggah file baru di bawah untuk menggantinya.
                        </p>
                    @endif
                    <input
                        type="file"
                        wire:model="fileLampiranUpload"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('fileLampiranUpload') ring-2 ring-red-500 @enderror shadow-border"
                    />
                    <p class="mt-1.5 text-xs text-neutral-500">Format PDF, JPG, JPEG, PNG, atau WEBP. Maksimal 5 MB.</p>
                    <div wire:loading wire:target="fileLampiranUpload" class="mt-1.5 text-xs text-neutral-500">Mengunggah...</div>
                    @error('fileLampiranUpload') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.keuangan.keringanan-biaya') }}" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                Batal
            </a>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                Simpan
            </button>
        </div>
    </form>
</div>
