@section('title', 'Input Nilai — ' . config('app.name'))
@section('header_title', 'Input Nilai')

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
    $jenisManual = collect($data['jenis_penilaian'])->where('status', 'manual')->values();
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
    </div>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <div class="w-full sm:w-72">
            <x-searchable-select
                model="selectedJenisPenilaianId"
                :options="$jenisManual->mapWithKeys(fn ($jp) => [$jp['id'] => $jp['nama'].' ('.$jp['bobot'].'%)'])->all()"
                :live="true"
                :clearable="false"
                placeholder="Pilih jenis penilaian"
            />
            @error('selectedJenisPenilaianId') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <button
            type="button"
            wire:click="save"
            wire:loading.attr="disabled"
            class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700 disabled:opacity-50"
        >
            <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
            Simpan Nilai
        </button>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        @if (empty($data['mahasiswa']))
            <div class="p-10 text-center">
                <i data-lucide="users" class="mx-auto mb-4 h-10 w-10 text-neutral-300" aria-hidden="true"></i>
                <p class="font-medium text-neutral-600">Tidak ada mahasiswa</p>
                <p class="mt-1 text-sm text-neutral-500">Tidak ada mahasiswa yang mengontrak mata kuliah ini.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <th class="px-6 py-3">No</th>
                            <th class="px-6 py-3">NIM</th>
                            <th class="px-6 py-3">Nama</th>
                            <th class="px-6 py-3">Prodi</th>
                            <th class="px-6 py-3 text-center">Nilai (0-100)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($data['mahasiswa'] as $idx => $mhs)
                            <tr wire:key="mhs-{{ $mhs['id_krs'] }}" class="hover:bg-neutral-50/70">
                                <td class="px-6 py-4 text-neutral-900">{{ $idx + 1 }}</td>
                                <td class="px-6 py-4 font-medium text-neutral-900">{{ $mhs['nim'] }}</td>
                                <td class="px-6 py-4 text-neutral-900">{{ $mhs['nama'] }}</td>
                                <td class="px-6 py-4 text-neutral-600">{{ $mhs['prodi']?->nama ?? '-' }}</td>
                                <td class="px-6 py-4 text-center">
                                    <input
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        wire:model="nilaiInputs.{{ $mhs['id_krs'] }}"
                                        placeholder="0-100"
                                        class="w-24 rounded-lg px-3 py-2 text-center text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                                    />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
