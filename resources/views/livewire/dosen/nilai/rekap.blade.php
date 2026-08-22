@section('title', 'Rincian Nilai — ' . config('app.name'))
@section('header_title', 'Rincian Nilai')

@section('breadcrumb')
    <a href="{{ route('dosen.nilai', ['id_semester' => $this->kelas->id_semester]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-sky-600 hover:text-sky-700">
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
@endsection

@php
    $kelas = $this->kelas;
    $km = $kelas->kurikulumMatkul;
    $data = $this->data;
    $rentangTersedia = ! empty($data['rentang_nilai']);
    // Kelas di luar semester aktif hanya bisa dilihat; guard sesungguhnya ada di
    // Dosen\Nilai\Rekap::pastikanBolehUbah(), ini sekadar menyembunyikan tombolnya.
    $bolehUbah = $this->bolehUbah;
@endphp

<div class="space-y-4">
    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4 rounded-2xl bg-white p-5 shadow-border">
        <div>
            <p class="text-sm font-semibold text-neutral-900">{{ $km?->kodeMatkulLabel() ?? '-' }} - {{ $km?->namaMatkulLabel() ?? '-' }}</p>
            <p class="mt-1 text-sm text-neutral-500">Kelas: {{ $kelas->kode }} | SKS: {{ $km?->sksLabel() ?? 0 }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            {{-- Ekspor berlaku untuk semua semester; yang dibatasi semester aktif hanya aksi
                 yang mengubah data (lihat Dosen\Nilai\Rekap::pastikanBolehUbah). --}}
            <button
                type="button"
                wire:click="exportExcel"
                wire:loading.attr="disabled"
                wire:target="exportExcel"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 disabled:opacity-50"
            >
                <i data-lucide="file-spreadsheet" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                <span wire:loading.remove wire:target="exportExcel">Ekspor Excel</span>
                <span wire:loading wire:target="exportExcel">Memproses...</span>
            </button>
            <button
                type="button"
                wire:click="exportPdf"
                wire:loading.attr="disabled"
                wire:target="exportPdf"
                class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 disabled:opacity-50"
            >
                <i data-lucide="file-down" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                <span wire:loading.remove wire:target="exportPdf">Ekspor PDF</span>
                <span wire:loading wire:target="exportPdf">Memproses...</span>
            </button>

            @if ($bolehUbah)
                <button
                    type="button"
                    wire:click="openRentangModal"
                    @if (! $rentangTersedia) disabled @endif
                    title="Kalkulasi dengan Custom Rentang Nilai"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <i data-lucide="sliders-horizontal" class="h-4 w-4" aria-hidden="true"></i>
                    Custom Rentang Nilai
                </button>
                <button
                    type="button"
                    wire:click="kalkulasiDenganRentangDefault"
                    wire:loading.attr="disabled"
                    wire:confirm="Apakah Anda yakin ingin melakukan kalkulasi nilai akhir?"
                    title="Kalkulasi dengan Rentang Default"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                >
                    <i data-lucide="calculator" class="h-4 w-4" aria-hidden="true"></i>
                    Kalkulasi
                </button>
                <button
                    type="button"
                    wire:click="finalisasi"
                    wire:loading.attr="disabled"
                    wire:confirm="Dengan memfinalisasi, nilai di kelas ini akan dianggap final dan akan tampil di akun mahasiswa. Apakah Anda yakin?"
                    class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 disabled:opacity-50"
                >
                    <i data-lucide="lock" class="h-4 w-4" aria-hidden="true"></i>
                    Finalisasi
                </button>
            @else
                <span class="inline-flex items-center gap-2 rounded-lg bg-neutral-100 px-3 py-2 text-xs font-medium text-neutral-600">
                    <i data-lucide="eye" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                    Hanya lihat — bukan semester aktif
                </span>
            @endif
        </div>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        @if (empty($data['mahasiswa']))
            <div class="p-10 text-center">
                <i data-lucide="graduation-cap" class="mx-auto mb-4 h-10 w-10 text-neutral-300" aria-hidden="true"></i>
                <p class="font-medium text-neutral-600">Tidak ada mahasiswa</p>
                <p class="mt-1 text-sm text-neutral-500">Belum ada mahasiswa yang mengambil mata kuliah ini.</p>
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
                            <th class="px-4 py-3 text-center">Jumlah Total Nilai</th>
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
                            <tr wire:key="mhs-{{ $mhs['id_krs'] }}" class="hover:bg-neutral-50/70">
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
                                    @if ($bolehUbah)
                                        <button type="button" wire:click="openEditModal({{ $mhs['id_krs'] }})" title="Input atau edit nilai" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                                            <i data-lucide="pencil" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                        </button>
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

    {{-- Modal Custom Rentang Nilai --}}
    @if ($showRentangModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-border-lg">
                <div class="border-b border-neutral-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-neutral-900">Custom Rentang Nilai</h3>
                    <p class="mt-1 text-sm text-neutral-500">Ubah rentang untuk setiap huruf mutu, lalu klik Kalkulasi untuk mengalkulasi ulang nilai akhir.</p>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-4">
                    @error('rentangForm') <p class="mb-3 text-sm text-red-600">{{ $message }}</p> @enderror
                    <table class="min-w-full divide-y divide-neutral-200 text-sm">
                        <thead>
                            <tr class="bg-neutral-50 text-xs font-semibold uppercase text-neutral-600">
                                <th class="px-3 py-2 text-left">Huruf</th>
                                <th class="px-3 py-2 text-left">Angka Mutu</th>
                                <th class="px-3 py-2 text-left">Nilai Rendah</th>
                                <th class="px-3 py-2 text-left">Nilai Tinggi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200">
                            @foreach ($rentangForm as $index => $r)
                                <tr>
                                    <td class="px-3 py-2">
                                        <span class="inline-block w-20 rounded bg-neutral-100 px-2 py-1.5 text-sm font-medium text-neutral-700">{{ $r['nilai_huruf'] }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="inline-block w-24 rounded bg-neutral-100 px-2 py-1.5 text-sm font-medium text-neutral-700">{{ $r['nilai_angka'] }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" min="0" step="0.01" wire:model="rentangForm.{{ $index }}.nilai_rendah" class="w-24 rounded border border-neutral-300 px-2 py-1.5 text-sm" />
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="number" min="0" step="0.01" wire:model="rentangForm.{{ $index }}.nilai_tinggi" class="w-24 rounded border border-neutral-300 px-2 py-1.5 text-sm" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if (empty($rentangForm))
                        <p class="py-4 text-sm text-neutral-500">Tidak ada rentang nilai default. Hubungi admin untuk mengatur rentang nilai jenjang.</p>
                    @endif
                </div>
                <div class="flex justify-end gap-2 border-t border-neutral-200 px-6 py-4">
                    <button type="button" wire:click="closeRentangModal" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                        Batal
                    </button>
                    <button
                        type="button"
                        wire:click="terapkanRentangCustom"
                        wire:loading.attr="disabled"
                        @if (empty($rentangForm)) disabled @endif
                        class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Kalkulasi
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal Edit / Revisi Nilai --}}
    @if ($showEditModal)
        @php $editRow = collect($data['mahasiswa'])->firstWhere('id_krs', $editKrsId); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="flex max-h-[90vh] w-full max-w-md flex-col rounded-2xl bg-white shadow-border-lg">
                <div class="border-b border-neutral-200 px-6 py-4">
                    <h3 class="text-base font-semibold text-neutral-900">Edit Nilai</h3>
                    @if ($editRow)
                        <p class="mt-1 text-sm text-neutral-500">{{ $editRow['nim'] }} – {{ $editRow['nama'] }}</p>
                    @endif
                </div>
                <div class="flex-1 space-y-4 overflow-y-auto px-6 py-4">
                    <label class="flex cursor-pointer items-center gap-2">
                        <input type="checkbox" wire:model="editRevisiChecked" class="rounded border-neutral-300 text-sky-600 focus:ring-sky-500" />
                        <span class="text-sm font-medium text-neutral-700">Revisi</span>
                    </label>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Huruf Mutu *</label>
                        @if ($rentangTersedia)
                            <x-searchable-select
                                model="editHurufMutu"
                                :options="collect($data['rentang_nilai'])->mapWithKeys(fn ($r) => [$r->nilai_huruf => $r->nilai_huruf.' (Angka: '.$r->nilai_angka.')'])->all()"
                                :live="true"
                                placeholder="Pilih huruf mutu"
                            />
                        @else
                            <input type="text" wire:model="editHurufMutu" maxlength="10" placeholder="Contoh: A, B+, C" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border" />
                        @endif
                        @error('editHurufMutu') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Angka Mutu</label>
                        <input
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model="editAngkaMutu"
                            placeholder="Contoh: 4.00"
                            @if ($rentangTersedia) readonly @endif
                            class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border {{ $rentangTersedia ? 'cursor-not-allowed bg-neutral-100' : '' }}"
                        />
                        @if ($rentangTersedia)
                            <p class="mt-1.5 text-xs text-neutral-500">Terisi otomatis sesuai huruf mutu yang dipilih.</p>
                        @endif
                        @error('editAngkaMutu') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if ($editRevisiChecked)
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Alasan revisi</label>
                            <textarea wire:model="editKeterangan" rows="3" maxlength="500" placeholder="Alasan revisi..." class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"></textarea>
                            @error('editKeterangan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="flex justify-end gap-2 border-t border-neutral-200 px-6 py-4">
                    <button type="button" wire:click="closeEditModal" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                        Batal
                    </button>
                    <button
                        type="button"
                        wire:click="saveEditNilai"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-50"
                    >
                        {{ $editRevisiChecked ? 'Simpan Revisi' : 'Simpan' }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
