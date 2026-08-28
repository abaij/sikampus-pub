@section('title', 'Keringanan Biaya — ' . config('app.name'))

{{-- Tombol "Buat Pengajuan" sengaja TIDAK di @section('page_actions') — Livewire hanya
     memasang wire:id ke root HTML yang berada di LUAR @section apa pun (lihat penjelasan lengkap
     di komentar serupa pada livewire/mahasiswa/krs/index.blade.php); wire:click di dalam
     page_actions tidak pernah terikat ke komponen. Header di bawah ini meniru markup
     @hasSection('header_title') milik layouts.mahasiswa supaya tampilan tetap sama, tapi harus
     jadi ANAK PERTAMA dari div ini (bukan sibling) — Livewire cuma mengizinkan satu elemen root
     per komponen. --}}
@php
    $formatIdr = fn ($v) => 'Rp'.number_format((float) $v, 0, ',', '.');
    $formatDateTime = fn ($v) => $v ? \Carbon\Carbon::parse($v)->translatedFormat('d M Y H:i') : '-';
    $statusInfo = fn (?string $s) => match ($s) {
        'approved' => ['text' => 'Disetujui', 'icon' => 'check-circle-2', 'class' => 'bg-emerald-50 text-emerald-800'],
        'rejected' => ['text' => 'Ditolak', 'icon' => 'x-circle', 'class' => 'bg-red-50 text-red-800'],
        default => ['text' => 'Menunggu', 'icon' => 'clock', 'class' => 'bg-amber-50 text-amber-900'],
    };
    $selectedJenis = $this->selectedJenis;
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold tracking-tight text-neutral-900">Keringanan Biaya</h1>
            <p class="mt-1 text-sm text-neutral-500">Ajukan keringanan biaya per semester. Pengajuan Anda akan ditinjau terlebih dahulu oleh bagian keuangan.</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            <button
                type="button"
                wire:click="openFormModal"
                class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-sky-700"
            >
                <i data-lucide="plus-circle" class="h-5 w-5" aria-hidden="true"></i>
                Buat Pengajuan
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <section>
        <h2 class="mb-3 text-lg font-medium text-neutral-900">Riwayat pengajuan</h2>
        @if ($this->list->isEmpty())
            <p class="rounded-xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-600">
                Belum ada pengajuan.
            </p>
        @else
            <ul class="space-y-3">
                @foreach ($this->list as $row)
                    @php $st = $statusInfo($row->status); @endphp
                    <li wire:key="kb-{{ $row->id }}" class="rounded-2xl bg-white p-4 shadow-border">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="font-medium text-neutral-900">{{ $row->jenisKeringananBiaya->nama ?? '—' }}</p>
                                <p class="text-sm text-neutral-600">
                                    {{ $row->semester->nama ?? '' }}
                                    <span class="ml-2 inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium {{ $st['class'] }}">
                                        <i data-lucide="{{ $st['icon'] }}" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                        {{ $st['text'] }}
                                    </span>
                                </p>
                                @if ($row->keterangan)
                                    <p class="mt-2 text-sm text-neutral-600">{{ $row->keterangan }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-4 border-t border-neutral-100 pt-3 text-xs text-neutral-500">
                            <span>Diajukan: {{ $formatDateTime($row->tanggal_pengajuan) }}</span>
                            @if ($row->status === 'approved')
                                <span>Disetujui: {{ $formatDateTime($row->tanggal_approved) }}</span>
                            @endif
                            @if ($row->file_lampiran_url)
                                <a href="{{ $row->file_lampiran_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-sky-700 hover:underline">
                                    <i data-lucide="paperclip" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                    Lampiran
                                    <i data-lucide="external-link" class="h-3 w-3" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($showFormModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 p-4">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-border-lg">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="pr-8 text-lg font-semibold text-neutral-900">Pengajuan keringanan biaya</h2>
                    <button type="button" wire:click="closeFormModal" class="rounded-lg p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600" aria-label="Tutup">
                        <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
                    </button>
                </div>
                <p class="mt-1 text-sm text-neutral-500">Isi formulir berikut. Nominal mengikuti master jenis yang dipilih.</p>

                <form wire:submit="submit" class="mt-6 space-y-4">
                    @error('idJenis') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p> @enderror
                    @error('idSemester') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p> @enderror

                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700">Jenis keringanan</label>
                        <x-searchable-select model="idJenis" :options="$this->jenisOptions" :live="true" placeholder="— Pilih —" />
                        @if ($selectedJenis)
                            <div class="mt-2 space-y-1 text-xs text-neutral-600">
                                <p>
                                    <span class="font-medium text-neutral-700">Nominal pengajuan: </span>
                                    {{ $selectedJenis->is_persentase ? rtrim(rtrim(number_format((float) $selectedJenis->nominal, 2, ',', '.'), '0'), ',').'%' : $formatIdr($selectedJenis->nominal) }}
                                    <span class="text-neutral-500">
                                        @if ($selectedJenis->is_persentase)
                                            (dari total tagihan semester, dihitung saat pengajuan disetujui)
                                        @else
                                            (mengikuti master jenis)
                                        @endif
                                    </span>
                                </p>
                                @if ($selectedJenis->keterangan)
                                    <p class="text-neutral-500">{{ $selectedJenis->keterangan }}</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700">Semester</label>
                        <x-searchable-select model="idSemester" :options="$this->semesterOptionsRaw" placeholder="— Pilih —" />
                    </div>

                    @if ($this->jenisOptions->isEmpty())
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            Belum ada jenis keringanan aktif yang dapat dipilih. Hubungi bagian keuangan.
                        </p>
                    @endif

                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700">Keterangan (opsional)</label>
                        <textarea wire:model="keterangan" rows="3" placeholder="Alasan atau penjelasan singkat" class="w-full rounded-xl px-3 py-2 text-sm shadow-border outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10"></textarea>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-neutral-700">Lampiran (opsional)</label>
                        <input type="file" wire:model="fileLampiran" accept=".pdf,.jpg,.jpeg,.png,.webp" class="text-sm text-neutral-600" />
                        <p class="mt-1 text-xs text-neutral-500">PDF atau gambar, maks. 5 MB.</p>
                        @error('fileLampiran') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-wrap gap-3 border-t border-neutral-100 pt-4">
                        <button type="button" wire:click="closeFormModal" class="rounded-xl px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50">Batal</button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="submit,fileLampiran"
                            @disabled($this->jenisOptions->isEmpty())
                            class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <i data-lucide="file-text" class="h-4 w-4" aria-hidden="true"></i>
                            Kirim pengajuan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
