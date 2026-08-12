@section('title', 'Bimbingan Akademik — ' . config('app.name'))

{{-- Tombol "Tambah catatan" sengaja TIDAK di @section('page_actions') — Livewire hanya
     memasang wire:id ke root HTML yang berada di LUAR @section apa pun (lihat penjelasan lengkap
     di komentar serupa pada livewire/mahasiswa/krs/index.blade.php); wire:click di dalam
     page_actions tidak pernah terikat ke komponen. Header di bawah ini meniru markup
     @hasSection('header_title') milik layouts.mahasiswa supaya tampilan tetap sama, tapi harus
     jadi ANAK PERTAMA dari div ini (bukan sibling) — Livewire cuma mengizinkan satu elemen root
     per komponen. --}}
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold tracking-tight text-neutral-900">Bimbingan Akademik</h1>
            <p class="mt-1 text-sm text-neutral-500">Riwayat catatan bimbingan dengan dosen wali per semester.</p>
        </div>
        @if ($this->dosenWali)
            <div class="flex shrink-0 items-center gap-2">
                <button
                    type="button"
                    wire:click="openTambah"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
                >
                    <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                    Tambah catatan
                </button>
            </div>
        @endif
    </div>

    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="w-full sm:w-72">
        <label class="mb-1.5 block text-xs font-medium text-neutral-500">Semester</label>
        <x-searchable-select
            model="filterSemester"
            :options="$this->semesterOptions"
            :live="true"
            :clearable="false"
            placeholder="Pilih semester"
        />
    </div>

    @if ($this->dosenWali?->dosen)
        <div class="rounded-2xl bg-white p-4 shadow-border">
            <div class="flex items-start gap-3">
                <i data-lucide="graduation-cap" class="mt-0.5 h-5 w-5 shrink-0 text-sky-600" aria-hidden="true"></i>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">Dosen wali</p>
                    <p class="text-base font-semibold text-neutral-900">{{ $this->dosenWali->dosen->nama }}</p>
                    <p class="mt-1 text-sm text-neutral-600">
                        {{ collect([
                            $this->dosenWali->dosen->kode_dosen ? 'Kode: '.$this->dosenWali->dosen->kode_dosen : null,
                            $this->dosenWali->dosen->nidn ? 'NIDN: '.$this->dosenWali->dosen->nidn : null,
                        ])->filter()->implode(' · ') ?: '-' }}
                    </p>
                </div>
            </div>
        </div>
    @else
        <div class="rounded-lg border border-amber-100 bg-amber-50/80 px-4 py-3 text-sm text-amber-900">
            Anda belum memiliki penugasan dosen wali aktif. Data bimbingan akan tampil setelah administrasi menetapkan dosen wali.
        </div>
    @endif

    @if ($this->rows->isEmpty())
        <div class="rounded-2xl bg-white p-10 text-center shadow-border">
            <i data-lucide="file-text" class="mx-auto h-10 w-10 text-neutral-400" aria-hidden="true"></i>
            <p class="mt-3 font-medium text-neutral-700">Belum ada catatan bimbingan</p>
            <p class="mt-1 text-sm text-neutral-500">Pilih semester lain atau tunggu entri dari dosen wali.</p>
            @if ($this->dosenWali)
                <button
                    type="button"
                    wire:click="openTambah"
                    class="mt-3 inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
                >
                    <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                    Tambah catatan
                </button>
            @endif
        </div>
    @else
        <div class="overflow-hidden rounded-2xl bg-white shadow-border">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="w-12 px-4 py-3">No</th>
                            <th class="px-4 py-3">Tanggal</th>
                            <th class="min-w-[160px] px-4 py-3">Catatan dosen</th>
                            <th class="min-w-[160px] px-4 py-3">Catatan mahasiswa</th>
                            <th class="px-4 py-3">Berkas</th>
                            <th class="px-4 py-3">Validasi</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($this->rows as $idx => $row)
                            <tr wire:key="bimbingan-{{ $row->id }}">
                                <td class="px-4 py-3 text-neutral-600">{{ $idx + 1 }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-neutral-800">{{ $row->tanggal_bimbingan?->translatedFormat('d M Y') ?? '-' }}</td>
                                <td class="max-w-xs px-4 py-3 text-neutral-700">
                                    <span class="line-clamp-4 whitespace-pre-wrap">{{ trim((string) $row->catatan_dosen) !== '' ? $row->catatan_dosen : '—' }}</span>
                                </td>
                                <td class="max-w-xs px-4 py-3 text-neutral-700">
                                    <span class="line-clamp-4 whitespace-pre-wrap">{{ trim((string) $row->catatan_mhs) !== '' ? $row->catatan_mhs : '—' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row->file_url)
                                        <a href="{{ $row->file_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-sky-600 hover:text-sky-700">
                                            Lihat
                                            <i data-lucide="external-link" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                        </a>
                                    @else
                                        <span class="text-neutral-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-col gap-1 text-xs">
                                        <span class="inline-flex items-center gap-1 font-medium {{ $row->waktu_validasi_dosen ? 'text-emerald-700' : 'text-neutral-400' }}">
                                            <i data-lucide="{{ $row->waktu_validasi_dosen ? 'check-circle-2' : 'circle-dashed' }}" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            Dosen: {{ $row->waktu_validasi_dosen?->translatedFormat('d M Y H:i') ?? '-' }}
                                        </span>
                                        <span class="inline-flex items-center gap-1 font-medium {{ $row->waktu_validasi_mhs ? 'text-emerald-700' : 'text-neutral-400' }}">
                                            <i data-lucide="{{ $row->waktu_validasi_mhs ? 'check-circle-2' : 'circle-dashed' }}" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            Mhs: {{ $row->waktu_validasi_mhs?->translatedFormat('d M Y H:i') ?? '-' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button
                                        type="button"
                                        wire:click="openDetail({{ $row->id }})"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-neutral-700 shadow-border transition hover:bg-neutral-50"
                                    >
                                        <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                        Lihat
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Modal tambah catatan --}}
    @if ($showTambahModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-lg font-semibold text-neutral-900">Tambah catatan bimbingan akademik</h3>
                <p class="mt-1 text-sm text-neutral-500">Catatan akan tercatat untuk Anda pada semester yang dipilih dan dapat dibaca oleh dosen wali.</p>

                <div class="mt-6 space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Semester</label>
                        <x-searchable-select
                            model="tambahSemester"
                            :options="$this->semesterOptions"
                            :clearable="false"
                            placeholder="Pilih semester"
                        />
                        @error('tambahSemester') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Tanggal bimbingan</label>
                        <input type="date" wire:model="tambahTanggal" class="w-full rounded-lg px-3 py-2 text-sm shadow-border outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Catatan mahasiswa</label>
                        <textarea
                            wire:model="tambahCatatan"
                            rows="5"
                            placeholder="Tuliskan agenda pertemuan, pertanyaan, atau hal yang ingin dibahas dengan dosen wali…"
                            class="w-full rounded-lg px-3 py-2 text-sm shadow-border outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tambahCatatan') ring-2 ring-red-500 @enderror"
                        ></textarea>
                        @error('tambahCatatan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-neutral-500">Lampiran (opsional)</label>
                        <input type="file" wire:model="tambahFile" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,image/*" class="block w-full text-sm text-neutral-700" />
                        @error('tambahFile') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="closeTambah" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50">Batal</button>
                    <button
                        type="button"
                        wire:click="submitTambah"
                        wire:loading.attr="disabled"
                        wire:target="submitTambah,tambahFile"
                        class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 disabled:opacity-50"
                    >
                        Simpan
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal detail --}}
    @if ($this->detailRow)
        @php $row = $this->detailRow; @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-lg font-semibold text-neutral-900">Detail bimbingan akademik</h3>
                <p class="mt-1 text-sm text-neutral-500">Baca catatan dosen, tulis tanggapan Anda. Centang validasi jika Anda menyetujui entri ini, lalu simpan.</p>

                <div class="mt-5 space-y-4 border-t border-neutral-100 pt-5 text-sm">
                    <div class="grid gap-1">
                        <span class="text-xs font-medium uppercase tracking-wide text-neutral-500">Semester</span>
                        <span class="text-neutral-800">{{ $row->semester ? $row->semester->kode.' · '.$row->semester->nama : '—' }}</span>
                    </div>
                    <div class="grid gap-1">
                        <span class="text-xs font-medium uppercase tracking-wide text-neutral-500">Tanggal bimbingan</span>
                        <span class="text-neutral-800">{{ $row->tanggal_bimbingan?->translatedFormat('d M Y') ?? '-' }}</span>
                    </div>
                    <div class="grid gap-1">
                        <span class="text-xs font-medium uppercase tracking-wide text-neutral-500">Catatan dosen</span>
                        <p class="whitespace-pre-wrap rounded-lg bg-neutral-50 px-3 py-2 text-neutral-800 shadow-border">{{ trim((string) $row->catatan_dosen) !== '' ? $row->catatan_dosen : '—' }}</p>
                    </div>
                    @if ($row->file_url)
                        <div>
                            <span class="text-xs font-medium uppercase tracking-wide text-neutral-500">Berkas</span>
                            <div class="mt-1">
                                <a href="{{ $row->file_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-sky-600 hover:text-sky-700">
                                    Buka lampiran
                                    <i data-lucide="external-link" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-3 text-xs text-neutral-600">
                        <span class="{{ $row->waktu_validasi_dosen ? 'text-emerald-700' : 'text-neutral-400' }}">Validasi dosen: {{ $row->waktu_validasi_dosen?->translatedFormat('d M Y H:i') ?? '-' }}</span>
                        <span class="{{ $row->waktu_validasi_mhs ? 'text-emerald-700' : 'text-neutral-400' }}">Validasi mahasiswa: {{ $row->waktu_validasi_mhs?->translatedFormat('d M Y H:i') ?? '-' }}</span>
                    </div>
                </div>

                <div class="mt-6 space-y-4 border-t border-neutral-100 pt-5">
                    @if (! $row->waktu_validasi_mhs)
                        <div class="flex gap-3 rounded-lg bg-neutral-50/80 px-3 py-3 shadow-border">
                            <input
                                id="validasi-mhs-checkbox"
                                type="checkbox"
                                wire:model="detailValidasiChecked"
                                class="mt-0.5 h-4 w-4 shrink-0 rounded border-neutral-300 text-emerald-600 focus:ring-emerald-500"
                            />
                            <label for="validasi-mhs-checkbox" class="cursor-pointer text-sm leading-snug text-neutral-700">
                                <span class="font-medium text-neutral-900">Validasi sebagai mahasiswa</span>
                                <span class="mt-0.5 block text-neutral-600">Saya menyatakan telah membaca dan menyetujui catatan bimbingan ini. Waktu validasi akan dicatat setelah simpan.</span>
                            </label>
                        </div>
                    @endif
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-neutral-700">Catatan mahasiswa</label>
                        <textarea
                            wire:model="detailCatatanDraft"
                            rows="5"
                            @disabled($row->waktu_validasi_mhs)
                            placeholder="Tuliskan tanggapan atau konfirmasi Anda terhadap bimbingan ini…"
                            class="w-full rounded-lg px-3 py-2 text-sm shadow-border outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 disabled:cursor-not-allowed disabled:bg-neutral-50 @error('detailCatatanDraft') ring-2 ring-red-500 @enderror"
                        ></textarea>
                        @error('detailCatatanDraft') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        @if ($row->waktu_validasi_mhs)
                            <p class="text-xs text-neutral-500">Entri sudah Anda validasi; catatan tidak dapat diubah.</p>
                        @endif
                    </div>
                </div>

                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="closeDetail" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50">Tutup</button>
                    @if (! $row->waktu_validasi_mhs)
                        <button
                            type="button"
                            wire:click="saveDetail"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 disabled:opacity-50"
                        >
                            Simpan
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
