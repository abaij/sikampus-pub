@section('title', 'Arsip Nilai — ' . config('app.name'))
@section('header_title', 'Arsip nilai')

@section('breadcrumb')
    <a href="{{ route('dosen.arsip', ['id_semester' => $this->kelas->id_semester]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-sky-600 hover:text-sky-700">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali ke arsip perkuliahan
    </a>
@endsection

@php
    $kelas = $this->kelas;
    $km = $kelas->kurikulumMatkul;
    $data = $this->data;
    $rentangTersedia = ! empty($data['rentang_nilai']);
@endphp

<div class="space-y-4">
    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="rounded-2xl bg-white p-5 shadow-border">
        <p class="text-sm font-semibold text-neutral-900">{{ $km?->kodeMatkulLabel() ?? '-' }} - {{ $km?->namaMatkulLabel() ?? '-' }}</p>
        <p class="mt-1 text-sm text-neutral-500">Kelas: {{ $kelas->kode }} | SKS: {{ $km?->sksLabel() ?? 0 }}</p>
        @if ($kelas->prodi)
            <p class="mt-1 text-sm text-neutral-500">{{ $kelas->prodi->nama }}</p>
        @endif
        <p class="mt-3 text-sm text-neutral-500">
            Halaman ini khusus arsip. Untuk kalkulasi dan finalisasi nilai, gunakan menu
            <span class="font-medium text-neutral-700">Perkuliahan → Nilai</span> pada semester aktif.
        </p>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        @if (empty($data['mahasiswa']))
            <div class="p-10 text-center">
                <i data-lucide="graduation-cap" class="mx-auto mb-4 h-10 w-10 text-neutral-300" aria-hidden="true"></i>
                <p class="font-medium text-neutral-600">Tidak ada mahasiswa</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">NIM</th>
                            <th class="px-4 py-3">Nama</th>
                            @foreach ($data['jenis_penilaian'] as $jp)
                                <th class="cursor-help px-4 py-3 text-center" title="{{ $jp['nama'] }}">
                                    {{ $jp['kode'] }}<br>
                                    <span class="text-xs font-normal text-neutral-400">(Bobot: {{ $jp['bobot'] }}%)</span>
                                </th>
                            @endforeach
                            <th class="px-4 py-3 text-center">Jumlah Total</th>
                            <th class="px-4 py-3 text-center">Nilai Akhir</th>
                            <th class="px-4 py-3 text-center">Huruf Mutu</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Revisi</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($data['mahasiswa'] as $idx => $mhs)
                            @php $jumlahTotal = $this->jumlahTotalNilai($mhs['nilai_komponen']); @endphp
                            <tr wire:key="arsip-mhs-{{ $mhs['id_krs'] }}" class="hover:bg-neutral-50/70">
                                <td class="px-4 py-3 text-neutral-900">{{ $idx + 1 }}</td>
                                <td class="px-4 py-3 font-medium text-neutral-900">{{ $mhs['nim'] }}</td>
                                <td class="px-4 py-3 text-neutral-900">{{ $mhs['nama'] }}</td>
                                @foreach ($data['jenis_penilaian'] as $jp)
                                    @php $komponen = $mhs['nilai_komponen']->get($jp['id']); @endphp
                                    <td class="px-4 py-3 text-center text-neutral-900">
                                        @if ($komponen)
                                            <span class="font-medium">{{ $komponen->nilai }}{{ $jp['status'] === 'otomatis' ? '%' : '' }}</span>
                                        @else
                                            <span class="text-neutral-400">-</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-4 py-3 text-center text-neutral-900">
                                    {{ $jumlahTotal !== null ? $jumlahTotal : '-' }}
                                </td>
                                <td class="px-4 py-3 text-center font-semibold text-neutral-900">
                                    {{ $mhs['nilai']?->angka_mutu ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-center font-semibold text-emerald-600">
                                    {{ $mhs['nilai']?->huruf_mutu ?? '-' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if ($mhs['nilai'])
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $mhs['nilai']->is_final ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                            {{ $mhs['nilai']->is_final ? 'Final' : 'Belum Final' }}
                                        </span>
                                    @else
                                        <span class="text-neutral-400">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center text-neutral-900">{{ $mhs['nilai']?->revisi ?? 0 }}</td>
                                <td class="px-4 py-3 text-center">
                                    <button type="button" wire:click="openRevisiModal({{ $mhs['id_krs'] }})" title="Revisi nilai" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                                        <i data-lucide="file-pen-line" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                        Revisi nilai
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Modal Revisi Nilai --}}
    @if ($showRevisiModal)
        @php $revisiRow = collect($data['mahasiswa'])->firstWhere('id_krs', $revisiKrsId); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="flex max-h-[90vh] w-full max-w-md flex-col rounded-2xl bg-white shadow-border-lg">
                <div class="border-b border-neutral-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-neutral-900">Revisi Nilai</h3>
                    @if ($revisiRow)
                        <p class="mt-1 text-sm text-neutral-500">{{ $revisiRow['nim'] }} – {{ $revisiRow['nama'] }}</p>
                    @endif
                </div>
                <div class="flex-1 space-y-4 overflow-y-auto px-6 py-4">
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 ring-1 ring-amber-100">
                        Revisi akan dicatat di riwayat dan menambah nomor revisi pada nilai mahasiswa.
                    </p>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Huruf Mutu *</label>
                        @if ($rentangTersedia)
                            <x-searchable-select
                                model="revisiHurufMutu"
                                :options="collect($data['rentang_nilai'])->mapWithKeys(fn ($r) => [$r->nilai_huruf => $r->nilai_huruf.' (Angka: '.$r->nilai_angka.')'])->all()"
                                :live="true"
                                placeholder="Pilih huruf mutu"
                            />
                        @else
                            <input type="text" wire:model="revisiHurufMutu" maxlength="10" placeholder="Contoh: A, B+, C" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                        @endif
                        @error('revisiHurufMutu') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Angka Mutu</label>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model="revisiAngkaMutu"
                            placeholder="Contoh: 4.00"
                            @if ($rentangTersedia) readonly @endif
                            class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border {{ $rentangTersedia ? 'cursor-not-allowed bg-neutral-100' : '' }}"
                        />
                        @if ($rentangTersedia)
                            <p class="mt-1.5 text-xs text-neutral-500">Terisi otomatis sesuai huruf mutu yang dipilih.</p>
                        @endif
                        @error('revisiAngkaMutu') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Alasan revisi</label>
                        <textarea wire:model="revisiKeterangan" rows="3" maxlength="500" placeholder="Jelaskan alasan revisi nilai..." class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                        @error('revisiKeterangan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-neutral-200 px-6 py-4">
                    <button type="button" wire:click="closeRevisiModal" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                        Batal
                    </button>
                    <button
                        type="button"
                        wire:click="saveRevisi"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-50"
                    >
                        Simpan revisi
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
