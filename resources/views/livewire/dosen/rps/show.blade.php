@section('title', 'RPS — ' . config('app.name'))
@section('header_title', 'Rencana Pembelajaran Semester')

@section('breadcrumb')
    <a href="{{ route('dosen.rps', ['id_semester' => $this->kelas->id_semester]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-sky-600 hover:text-sky-700">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
@endsection

@php
    $kelas = $this->kelas;
    $km = $kelas->kurikulumMatkul;
    $rps = $this->rps;
@endphp

<div class="space-y-4">
    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-border">
        <span class="rounded bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">{{ $km?->kodeMatkulLabel() ?? '—' }}</span>
        <h2 class="mt-2 text-lg font-semibold text-neutral-900">{{ $km?->namaMatkulLabel() ?? '—' }}</h2>
        <p class="mt-1 text-sm text-neutral-500">
            Kelas {{ $kelas->kode }}
            @if ($kelas->semester)
                · {{ $kelas->semester->nama }} ({{ $kelas->semester->kode }})
            @endif
            @if ($kelas->prodi)
                · {{ $kelas->prodi->nama }}
            @endif
        </p>
    </div>

    <div class="flex gap-1 overflow-x-auto border-b border-neutral-200">
        @foreach (['info' => 'Info RPS', 'cpl' => 'CPL', 'cpmk' => 'CPMK & Sub-CPMK', 'pembelajaran' => 'Rincian Pembelajaran'] as $tab => $label)
            <button
                type="button"
                wire:click="$set('activeTab', '{{ $tab }}')"
                class="border-b-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap transition {{ $activeTab === $tab ? 'border-neutral-900 text-neutral-900' : 'border-transparent text-neutral-500 hover:text-neutral-700' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Tab: Info RPS --}}
    @if ($activeTab === 'info')
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <form wire:submit="saveInfo" class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Deskripsi Mata Kuliah</label>
                        <textarea wire:model="deskripsi_matkul" rows="4" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Deskripsi Mata Kuliah (EN)</label>
                        <textarea wire:model="deskripsi_matkul_en" rows="4" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Materi Kuliah</label>
                        <textarea wire:model="materi_kuliah" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Model Pembelajaran</label>
                        <textarea wire:model="model_pembelajaran" rows="2" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Pustaka Utama</label>
                        <textarea wire:model="pustaka_utama" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Pustaka Pendukung</label>
                        <textarea wire:model="pustaka_pendukung" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Media Perangkat Lunak</label>
                        <input type="text" wire:model="media_perangkat_lunak" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Media Perangkat Keras</label>
                        <input type="text" wire:model="media_perangkat_keras" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Tanggal Penyusunan</label>
                        <input type="date" wire:model="tanggal_penyusunan" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                    </div>
                </div>
                <div class="flex justify-end border-t border-neutral-200 pt-4">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                        <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                        Simpan Info RPS
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Tab: CPL --}}
    @if ($activeTab === 'cpl')
        <div class="space-y-4">
            <div class="flex justify-end">
                <button type="button" wire:click="openCplModal" class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700">
                    <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                    Tambah CPL
                </button>
            </div>
            @if (! $rps || $rps->rpsCpl->isEmpty())
                <div class="rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-600">
                    Belum ada CPL.
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($rps->rpsCpl as $cpl)
                        <div wire:key="cpl-{{ $cpl->id }}" class="rounded-xl bg-white p-4 shadow-border">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1 space-y-1">
                                    <p class="text-sm text-neutral-900">{{ $cpl->cpl ?: '—' }}</p>
                                    @if ($cpl->cpl_en)
                                        <p class="text-sm text-neutral-500 italic">{{ $cpl->cpl_en }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" wire:click="openCplModal({{ $cpl->id }})" class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700">
                                        <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" wire:click="deleteCpl({{ $cpl->id }})" wire:confirm="Hapus CPL ini?" class="rounded-lg p-2 text-neutral-500 hover:bg-rose-50 hover:text-rose-600">
                                        <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Tab: CPMK & Sub-CPMK --}}
    @if ($activeTab === 'cpmk')
        <div class="space-y-4">
            <div class="flex justify-end">
                <button type="button" wire:click="openCpmkModal" class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700">
                    <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                    Tambah CPMK
                </button>
            </div>
            @if (! $rps || $rps->rpsCpmk->isEmpty())
                <div class="rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-600">
                    Belum ada CPMK.
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($rps->rpsCpmk as $cpmk)
                        <div wire:key="cpmk-{{ $cpmk->id }}" class="rounded-xl bg-white p-4 shadow-border">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1 space-y-1">
                                    <p class="text-sm font-medium text-neutral-900">{{ $cpmk->cpmk ?: '—' }}</p>
                                    @if ($cpmk->cpmk_en)
                                        <p class="text-sm text-neutral-500 italic">{{ $cpmk->cpmk_en }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" wire:click="openCpmkModal({{ $cpmk->id }})" class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700">
                                        <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" wire:click="deleteCpmk({{ $cpmk->id }})" wire:confirm="Hapus CPMK ini beserta sub-CPMK-nya?" class="rounded-lg p-2 text-neutral-500 hover:bg-rose-50 hover:text-rose-600">
                                        <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="mt-3 space-y-2 border-t border-neutral-100 pt-3 pl-4">
                                @forelse ($cpmk->rpsSubcpmk as $sub)
                                    <div wire:key="subcpmk-{{ $sub->id }}" class="flex items-start justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2">
                                        <div class="min-w-0 flex-1 space-y-0.5">
                                            <p class="text-sm text-neutral-800">{{ $sub->subcpmk ?: '—' }}</p>
                                            @if ($sub->subcpmk_en)
                                                <p class="text-xs text-neutral-500 italic">{{ $sub->subcpmk_en }}</p>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 items-center gap-1">
                                            <button type="button" wire:click="openSubcpmkModal({{ $cpmk->id }}, {{ $sub->id }})" class="rounded-lg p-1.5 text-neutral-500 hover:bg-neutral-200 hover:text-neutral-700">
                                                <i data-lucide="pencil" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" wire:click="deleteSubcpmk({{ $sub->id }})" wire:confirm="Hapus sub-CPMK ini?" class="rounded-lg p-1.5 text-neutral-500 hover:bg-rose-50 hover:text-rose-600">
                                                <i data-lucide="trash-2" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-xs text-neutral-500">Belum ada sub-CPMK.</p>
                                @endforelse
                                <button type="button" wire:click="openSubcpmkModal({{ $cpmk->id }})" class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-50">
                                    <i data-lucide="plus" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                    Tambah sub-CPMK
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Tab: Rincian Pembelajaran --}}
    @if ($activeTab === 'pembelajaran')
        <div class="space-y-4">
            <div class="flex justify-end">
                <button type="button" wire:click="openPembelajaranModal" class="inline-flex items-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700">
                    <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                    Tambah Rincian
                </button>
            </div>
            @if (! $rps || $rps->rpsPembelajaran->isEmpty())
                <div class="rounded-2xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-600">
                    Belum ada rincian pembelajaran.
                </div>
            @else
                <div class="overflow-hidden rounded-xl bg-white shadow-border">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                            <thead>
                                <tr class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                    <th class="px-4 py-3">Ke</th>
                                    <th class="px-4 py-3">Sub-CPMK</th>
                                    <th class="px-4 py-3">Materi</th>
                                    <th class="px-4 py-3">Bentuk/Kriteria Penilaian</th>
                                    <th class="px-4 py-3 text-center">Bobot</th>
                                    <th class="px-4 py-3 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200">
                                @foreach ($rps->rpsPembelajaran as $p)
                                    <tr wire:key="pembelajaran-{{ $p->id }}" class="hover:bg-neutral-50/70">
                                        <td class="px-4 py-3 text-neutral-900">{{ $p->urutan_pertemuan ?? '-' }}</td>
                                        <td class="max-w-[200px] px-4 py-3 text-neutral-700">{{ $p->sub_cpmk ?: '-' }}</td>
                                        <td class="max-w-[220px] px-4 py-3 text-neutral-700">{{ $p->materi ?: '-' }}</td>
                                        <td class="max-w-[220px] px-4 py-3 text-neutral-700">{{ $p->bentuk_kriteria_penilaian ?: '-' }}</td>
                                        <td class="px-4 py-3 text-center text-neutral-900">{{ $p->bobot !== null ? number_format((float) $p->bobot, 2) : '-' }}</td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="flex items-center justify-center gap-1">
                                                <button type="button" wire:click="openPembelajaranModal({{ $p->id }})" class="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700">
                                                    <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" wire:click="deletePembelajaran({{ $p->id }})" wire:confirm="Hapus rincian pembelajaran ini?" class="rounded-lg p-2 text-neutral-500 hover:bg-rose-50 hover:text-rose-600">
                                                    <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Modal CPL --}}
    @if ($showCplModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-border-lg">
                <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-neutral-900">{{ $editingCplId ? 'Ubah CPL' : 'Tambah CPL' }}</h3>
                    <button type="button" wire:click="closeCplModal" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600">
                        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </div>
                <form wire:submit="saveCpl" class="space-y-4 p-6">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">CPL (Bahasa Indonesia)</label>
                        <textarea wire:model="form_cpl" rows="4" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">CPL (English)</label>
                        <textarea wire:model="form_cpl_en" rows="4" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    @error('form_cpl') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex items-center gap-3 border-t border-neutral-200 pt-4">
                        <button type="button" wire:click="closeCplModal" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">Batal</button>
                        <button type="submit" class="flex-1 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-neutral-800">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal CPMK --}}
    @if ($showCpmkModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-border-lg">
                <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-neutral-900">{{ $editingCpmkId ? 'Ubah CPMK' : 'Tambah CPMK' }}</h3>
                    <button type="button" wire:click="closeCpmkModal" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600">
                        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </div>
                <form wire:submit="saveCpmk" class="space-y-4 p-6">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">CPMK (Bahasa Indonesia)</label>
                        <textarea wire:model="form_cpmk" rows="4" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">CPMK (English)</label>
                        <textarea wire:model="form_cpmk_en" rows="4" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    @error('form_cpmk') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex items-center gap-3 border-t border-neutral-200 pt-4">
                        <button type="button" wire:click="closeCpmkModal" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">Batal</button>
                        <button type="submit" class="flex-1 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-neutral-800">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal Sub-CPMK --}}
    @if ($showSubcpmkModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-border-lg">
                <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-neutral-900">{{ $editingSubcpmkId ? 'Ubah Sub-CPMK' : 'Tambah Sub-CPMK' }}</h3>
                    <button type="button" wire:click="closeSubcpmkModal" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600">
                        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </div>
                <form wire:submit="saveSubcpmk" class="space-y-4 p-6">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Sub-CPMK (Bahasa Indonesia)</label>
                        <textarea wire:model="form_subcpmk" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Sub-CPMK (English)</label>
                        <textarea wire:model="form_subcpmk_en" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    @error('form_subcpmk') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                    <div class="flex items-center gap-3 border-t border-neutral-200 pt-4">
                        <button type="button" wire:click="closeSubcpmkModal" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">Batal</button>
                        <button type="submit" class="flex-1 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-neutral-800">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal Pembelajaran --}}
    @if ($showPembelajaranModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-border-lg">
                <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-neutral-900">{{ $editingPembelajaranId ? 'Ubah Rincian Pembelajaran' : 'Tambah Rincian Pembelajaran' }}</h3>
                    <button type="button" wire:click="closePembelajaranModal" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600">
                        <i data-lucide="x" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </div>
                <form wire:submit="savePembelajaran" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-5">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Pertemuan ke</label>
                            <input type="number" min="1" max="999" wire:model="form_urutan_pertemuan" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                            @error('form_urutan_pertemuan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Bobot (%)</label>
                            <input type="number" min="0" max="999.99" step="0.01" wire:model="form_bobot" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                            @error('form_bobot') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Sub-CPMK</label>
                        <textarea wire:model="form_sub_cpmk" rows="2" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Indikator Penilaian</label>
                        <textarea wire:model="form_indikator_penilaian" rows="2" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Bentuk / Kriteria Penilaian</label>
                        <textarea wire:model="form_bentuk_kriteria_penilaian" rows="2" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Pembelajaran Sinkron</label>
                            <textarea wire:model="form_pembelajaran_sinkron" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Pembelajaran Asinkron</label>
                            <textarea wire:model="form_pembelajaran_asinkron" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Materi</label>
                            <textarea wire:model="form_materi" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Materi (EN)</label>
                            <textarea wire:model="form_materi_en" rows="3" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                        </div>
                    </div>
                    <div class="flex items-center gap-3 border-t border-neutral-200 pt-4">
                        <button type="button" wire:click="closePembelajaranModal" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">Batal</button>
                        <button type="submit" class="flex-1 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-neutral-800">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
