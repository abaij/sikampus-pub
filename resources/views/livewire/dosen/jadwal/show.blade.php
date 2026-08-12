@section('title', 'Jadwal per Kelas — ' . config('app.name'))
@section('header_title', 'Jadwal per Kelas')

@section('page_actions')
    <a
        href="{{ route('dosen.jadwal.jurnal-perkuliahan', ['kelasId' => $kelasId, 'id_semester' => $idSemester !== '' ? $idSemester : null]) }}"
        class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
    >
        <i data-lucide="printer" class="h-4 w-4" aria-hidden="true"></i>
        Cetak Jurnal Perkuliahan
    </a>
@endsection

@section('breadcrumb')
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <a href="{{ route('dosen.kelas') }}" class="inline-flex items-center gap-2 font-medium text-sky-600 hover:text-sky-700">
            <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
            Kelas mata kuliah
        </a>
        <span class="text-neutral-300">|</span>
        <a href="{{ route('dosen.jadwal') }}" class="font-medium text-neutral-600 hover:text-sky-700">
            Semua jadwal mengajar
        </a>
    </div>
@endsection

@php
    $kelas = $this->kelas;
    $km = $kelas->kurikulumMatkul;

    $sesiBadge = [
        'sedang_berlangsung' => 'bg-sky-100 text-sky-800',
        'selesai' => 'bg-emerald-100 text-emerald-800',
        'belum_mulai' => 'bg-neutral-100 text-neutral-700',
    ];

    $hariLabel = ['senin' => 'Senin', 'selasa' => 'Selasa', 'rabu' => 'Rabu', 'kamis' => 'Kamis', 'jumat' => 'Jumat', 'sabtu' => 'Sabtu', 'minggu' => 'Minggu'];
@endphp

<div class="space-y-6">
    <div class="overflow-hidden rounded-2xl bg-white shadow-border">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-100 bg-neutral-50 px-5 py-4">
            <div>
                <h2 class="flex items-center gap-2 text-lg font-semibold text-neutral-900">
                    <i data-lucide="book-open" class="h-5 w-5 text-sky-600" aria-hidden="true"></i>
                    Jadwal per kelas
                </h2>
                @if ($kelas->semester)
                    <p class="mt-1 text-sm text-neutral-500">{{ $kelas->semester->nama }} ({{ $kelas->semester->kode }})</p>
                @endif
            </div>
            @if ($isPic)
                <span class="inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800">Dosen Penanggung Jawab</span>
            @else
                <span class="inline-flex rounded-full bg-neutral-100 px-3 py-1 text-xs font-medium text-neutral-700">Tim dosen</span>
            @endif
        </div>
        <div class="space-y-3 p-5">
            <div>
                <span class="rounded bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">{{ $km?->kodeMatkulLabel() ?? '—' }}</span>
                <h3 class="mt-2 text-xl font-semibold text-neutral-900">{{ $km?->namaMatkulLabel() ?? '—' }}</h3>
                <p class="mt-1 text-sm text-neutral-500">Kode kelas: <span class="font-mono">{{ $kelas->kode }}</span></p>
            </div>
            <div class="flex flex-wrap gap-4 text-sm text-neutral-600">
                <span class="inline-flex items-center gap-1.5">
                    <i data-lucide="graduation-cap" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                    {{ $kelas->prodi?->nama ?? '-' }}{{ $kelas->prodi?->jenjang?->nama ? ' ('.$kelas->prodi->jenjang->nama.')' : '' }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <i data-lucide="users" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                    Kelompok: {{ $kelas->kelompokKelas?->nama ?? '-' }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <i data-lucide="users" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                    Peserta (KRS disetujui): <span class="font-semibold text-neutral-800">{{ $this->jumlahMahasiswa }}</span>
                </span>
            </div>
        </div>
    </div>

    <h3 class="text-base font-semibold text-neutral-900">Slot jadwal pertemuan</h3>

    @php $rows = $this->jadwalRows; @endphp

    @if (empty($rows))
        <div class="rounded-xl border border-dashed border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-600">
            Belum ada jadwal yang terdaftar untuk kelas ini.
        </div>
    @else
        <div class="overflow-hidden rounded-xl bg-white shadow-border">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                    <thead>
                        <tr class="bg-neutral-50 text-neutral-600">
                            <th class="px-4 py-3 font-semibold">Pertemuan ke</th>
                            <th class="px-4 py-3 font-semibold">Hari</th>
                            <th class="px-4 py-3 font-semibold">Jam</th>
                            <th class="px-4 py-3 font-semibold">Tanggal</th>
                            <th class="px-4 py-3 font-semibold">Ruangan</th>
                            <th class="px-4 py-3 font-semibold">Jenis</th>
                            <th class="px-4 py-3 font-semibold">Dosen</th>
                            <th class="px-4 py-3 font-semibold">Status sesi</th>
                            <th class="px-4 py-3 text-center font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        @foreach ($rows as $row)
                            @php $jadwal = $row['jadwal']; @endphp
                            <tr wire:key="jadwal-{{ $jadwal->id }}" class="hover:bg-neutral-50/70">
                                <td class="px-4 py-3 text-neutral-600">{{ $jadwal->urutan_pertemuan ?? '-' }}</td>
                                <td class="px-4 py-3 font-medium text-neutral-900">{{ $hariLabel[strtolower((string) $jadwal->hari)] ?? $jadwal->hari ?? '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-neutral-700">
                                    <span class="inline-flex items-center gap-1">
                                        <i data-lucide="clock" class="h-3.5 w-3.5 text-neutral-400" aria-hidden="true"></i>
                                        {{ substr((string) $jadwal->jam_mulai, 0, 5) }} – {{ substr((string) $jadwal->jam_selesai, 0, 5) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-neutral-600">
                                    @if ($jadwal->tanggal)
                                        <span class="inline-flex items-center gap-1">
                                            <i data-lucide="calendar" class="h-3.5 w-3.5 text-neutral-400" aria-hidden="true"></i>
                                            {{ $jadwal->tanggal->translatedFormat('d M Y') }}
                                        </span>
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-neutral-700">
                                    <span class="inline-flex items-center gap-1">
                                        <i data-lucide="map-pin" class="h-3.5 w-3.5 shrink-0 text-neutral-400" aria-hidden="true"></i>
                                        {{ $jadwal->ruangan?->nama ?? '-' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-neutral-600">{{ $jadwal->jenisKuliah?->nama ?? '-' }}</td>
                                <td class="px-4 py-3 text-neutral-600">
                                    {{ $jadwal->dosen->map(fn ($jd) => $jd->dosen?->nama)->filter()->implode(', ') ?: '-' }}
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $sesiBadge[$row['sesi_status']] ?? 'bg-neutral-50 text-neutral-500' }}">
                                        {{ $row['sesi_status_label'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <a
                                        href="{{ route('dosen.jadwal.detail', ['kelasId' => $kelasId, 'jadwalId' => $jadwal->id, 'id_semester' => $idSemester !== '' ? $idSemester : null]) }}"
                                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50"
                                    >
                                        <i data-lucide="eye" class="h-3.5 w-3.5 text-neutral-400" aria-hidden="true"></i>
                                        Detail
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
