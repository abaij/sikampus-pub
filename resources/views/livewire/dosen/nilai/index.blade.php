@section('title', 'Nilai — ' . config('app.name'))
@section('header_title', 'Nilai')
@section('header_subtitle', 'Kelas yang Anda ampu. Input nilai hanya untuk semester aktif' . ($this->activeSemester ? ': '.$this->activeSemester->nama.' ('.$this->activeSemester->kode.')' : ' (belum ada semester aktif)'))

<div class="space-y-4">
    @php $rows = $this->rows; @endphp

    <div class="flex justify-end">
        <div class="w-full sm:w-64">
            <x-searchable-select model="filterSemester" :options="$this->semesterOptions" :live="true" placeholder="Semua semester" />
        </div>
    </div>

    <div class="rounded-2xl bg-white shadow-border">
        @if (empty($rows))
            <div class="p-10 text-center">
                <i data-lucide="book-open" class="mx-auto mb-4 h-10 w-10 text-neutral-300" aria-hidden="true"></i>
                <p class="font-medium text-neutral-600">Tidak ada mata kuliah</p>
                <p class="mt-1 text-sm text-neutral-500">
                    @if ($filterSemester !== '')
                        Anda tidak mengampu kelas pada semester yang dipilih.
                    @else
                        Anda belum tercatat mengampu kelas mana pun.
                    @endif
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Mata Kuliah</th>
                            <th class="px-4 py-3 text-center">SKS</th>
                            <th class="px-4 py-3">Kelas</th>
                            <th class="px-4 py-3">Semester</th>
                            <th class="px-4 py-3">Prodi</th>
                            <th class="px-4 py-3 text-center">Jumlah Mahasiswa</th>
                            <th class="px-4 py-3 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                        @foreach ($rows as $idx => $row)
                            @php $kelas = $row['kelas']; @endphp
                            <tr wire:key="kelas-{{ $kelas->id }}" class="hover:bg-neutral-50/70">
                                <td class="px-4 py-4 text-neutral-900">{{ $idx + 1 }}</td>
                                <td class="min-w-[200px] px-4 py-4">
                                    <div class="font-medium text-neutral-900">{{ $row['nama_matkul'] }}</div>
                                    <div class="text-xs text-neutral-500">{{ $row['kode_matkul'] }}</div>
                                </td>
                                <td class="px-4 py-4 text-center text-neutral-900">{{ $row['sks'] }}</td>
                                <td class="px-4 py-4 text-neutral-600">{{ $kelas->kode ?: '—' }}</td>
                                <td class="px-4 py-4">
                                    @if ($kelas->semester)
                                        <div class="text-neutral-800">{{ $kelas->semester->nama }}</div>
                                        <div class="text-xs text-neutral-500">
                                            {{ $kelas->semester->kode }}
                                            @if ($row['semester_aktif'])
                                                <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">Aktif</span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-neutral-500">—</span>
                                    @endif
                                </td>
                                <td class="min-w-[150px] px-4 py-4 text-neutral-600">
                                    @if ($kelas->prodi)
                                        <div>{{ $kelas->prodi->nama }}</div>
                                        @if ($kelas->prodi->jenjang)
                                            <div class="text-xs text-neutral-500">{{ $kelas->prodi->jenjang->nama }}</div>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex items-center justify-center gap-2 text-neutral-900">
                                        <i data-lucide="users" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                                        {{ $row['jumlah_mahasiswa'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="{{ route('dosen.nilai.rekap', $kelas->id) }}" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                                            <i data-lucide="eye" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                            Lihat Nilai
                                        </a>
                                        {{-- Input nilai hanya untuk semester aktif; aturan yang sama dikunci
                                             ulang di Dosen\Nilai\Input::mount, bukan cuma disembunyikan. --}}
                                        @if ($row['semester_aktif'])
                                            <a href="{{ route('dosen.nilai.input', $kelas->id) }}" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                                                <i data-lucide="file-edit" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                                Input Nilai
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
