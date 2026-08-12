@section('title', 'Jadwal Mengajar — ' . config('app.name'))
@section('header_title', 'Jadwal Mengajar')
@section('header_subtitle', 'Jadwal mengajar Anda, dikelompokkan per kelas kuliah. Untuk daftar per mata kuliah, buka Kelas Mata Kuliah.')

<div class="space-y-4">
    <div class="flex justify-end">
        <div class="w-full sm:w-64">
            <x-searchable-select
                model="filterSemester"
                :options="$this->semesterOptions"
                :live="true"
                placeholder="Semua semester"
            />
        </div>
    </div>

    @php $groups = $this->kelasGroups; @endphp

    @if ($groups->isEmpty())
        <div class="rounded-2xl bg-white px-4 py-10 text-center text-sm text-neutral-500 shadow-border">
            Belum ada jadwal mengajar.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($groups as $group)
                @php
                    $kelas = $group['kelas'];
                    $km = $kelas->kurikulumMatkul;
                @endphp
                <details wire:key="kelas-group-{{ $kelas->id }}" open class="group overflow-hidden rounded-2xl bg-white shadow-border">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 transition hover:bg-neutral-50">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate font-semibold text-neutral-900">{{ $km?->namaMatkulLabel() ?? '—' }}</span>
                                @if ($km?->kodeMatkulLabel())
                                    <span class="rounded bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">{{ $km->kodeMatkulLabel() }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-neutral-500">
                                Kelas {{ $kelas->kode }}
                                @if ($kelas->kelompokKelas)
                                    · {{ $kelas->kelompokKelas->nama }}
                                @endif
                                · {{ $group['rows']->count() }} {{ Str::plural('slot', $group['rows']->count()) }} jadwal
                            </p>
                        </div>
                        <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-neutral-400 transition group-open:rotate-180" aria-hidden="true"></i>
                    </summary>

                    <div class="overflow-x-auto border-t border-neutral-100">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                <tr>
                                    <th class="px-4 py-3">Hari</th>
                                    <th class="px-4 py-3">Jam</th>
                                    <th class="px-4 py-3">Ruangan</th>
                                    <th class="px-4 py-3">Jenis</th>
                                    <th class="px-4 py-3 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100">
                                @foreach ($group['rows'] as $jadwalDosen)
                                    @php
                                        $jadwal = $jadwalDosen->jadwal;
                                        $jamMulai = $jadwal->jam_mulai ? substr($jadwal->jam_mulai, 0, 5) : '—';
                                        $jamSelesai = $jadwal->jam_selesai ? substr($jadwal->jam_selesai, 0, 5) : null;
                                    @endphp
                                    <tr wire:key="jadwal-{{ $jadwalDosen->id }}">
                                        <td class="px-4 py-3 font-medium text-neutral-900">
                                            {{ $jadwal->hari ? ucfirst($jadwal->hari) : '—' }}
                                            @if ($jadwal->tanggal)
                                                <div class="text-xs font-normal text-neutral-500">{{ $jadwal->tanggal->translatedFormat('j M Y') }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 tabular-nums text-neutral-700">
                                            {{ $jamMulai }}{{ $jamSelesai ? " – {$jamSelesai}" : '' }}
                                        </td>
                                        <td class="px-4 py-3 text-neutral-600">{{ $jadwal->ruangan?->nama ?? '—' }}</td>
                                        <td class="px-4 py-3 text-neutral-600">{{ $jadwal->jenisKuliah?->nama ?? '—' }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <a
                                                href="{{ route('dosen.jadwal.detail', ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id, 'id_semester' => $filterSemester !== '' ? $filterSemester : null]) }}"
                                                class="inline-flex items-center justify-center rounded-lg p-2 text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900"
                                                title="Lihat detail jadwal"
                                            >
                                                <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</div>
