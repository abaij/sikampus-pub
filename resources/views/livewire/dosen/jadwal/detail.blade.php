@section('title', 'Detail Jadwal — ' . config('app.name'))
@section('header_title', 'Detail Jadwal')

@section('breadcrumb')
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <a
            href="{{ route('dosen.jadwal.show', ['kelasId' => $kelasId, 'id_semester' => $idSemester !== '' ? $idSemester : null]) }}"
            class="inline-flex items-center gap-2 font-medium text-sky-600 hover:text-sky-700"
        >
            <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
            Jadwal per kelas
        </a>
        <span class="text-neutral-300">|</span>
        <a href="{{ route('dosen.jadwal') }}" class="font-medium text-neutral-600 hover:text-sky-700">
            Semua jadwal mengajar
        </a>
    </div>
@endsection

@php
    $jadwal = $this->jadwal;
    $kelas = $jadwal->kelas;
    $km = $kelas?->kurikulumMatkul;
    $hariLabel = ['senin' => 'Senin', 'selasa' => 'Selasa', 'rabu' => 'Rabu', 'kamis' => 'Kamis', 'jumat' => 'Jumat', 'sabtu' => 'Sabtu', 'minggu' => 'Minggu'];
@endphp

<div class="space-y-6">
    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif
    @if (session('status_bahasan'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status_bahasan') }}</span>
        </div>
    @endif
    @if (session('status_sesi'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status_sesi') }}</span>
        </div>
    @endif

    <div class="overflow-hidden rounded-2xl bg-white shadow-border">
        <div class="border-b border-neutral-100 bg-neutral-50 px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <span class="rounded bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">{{ $km?->kodeMatkulLabel() ?? '—' }}</span>
                    <h2 class="mt-2 text-lg font-semibold text-neutral-900">{{ $km?->namaMatkulLabel() ?? '—' }}</h2>
                    <p class="mt-1 text-sm text-neutral-500">
                        Kelas {{ $kelas?->kode ?? '-' }}
                        @if ($kelas?->semester)
                            · {{ $kelas->semester->nama }} ({{ $kelas->semester->kode }})
                        @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($this->sesiAktif)
                        <button
                            type="button"
                            wire:click="klikSelesaikanSesi"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-neutral-800 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-900"
                        >
                            <i data-lucide="square" class="h-3.5 w-3.5" aria-hidden="true"></i>
                            Selesaikan sesi
                        </button>
                    @elseif ($this->bisaTampilMulaiSesi)
                        <button
                            type="button"
                            wire:click="klikMulaiSesi"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-sky-700"
                        >
                            <i data-lucide="play-circle" class="h-3.5 w-3.5" aria-hidden="true"></i>
                            Mulai sesi
                        </button>
                    @endif
                </div>
            </div>
            @if ($submitError)
                <p class="mt-2 text-sm text-rose-600" role="alert">{{ $submitError }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-4 p-5 text-sm text-neutral-600">
            <span class="inline-flex items-center gap-1.5">
                <i data-lucide="graduation-cap" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                {{ $kelas?->prodi?->nama ?? '-' }}{{ $kelas?->prodi?->jenjang?->nama ? ' ('.$kelas->prodi->jenjang->nama.')' : '' }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <i data-lucide="users" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                Kelompok: {{ $kelas?->kelompokKelas?->nama ?? '-' }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <i data-lucide="user" class="h-4 w-4 text-neutral-400" aria-hidden="true"></i>
                Dosen: {{ $jadwal->dosen->map(fn ($jd) => $jd->dosen?->nama)->filter()->implode(', ') ?: '-' }}
            </span>
        </div>
    </div>

    <div class="flex gap-2 rounded-xl bg-neutral-100 p-1.5">
        <button type="button" wire:click="setTab('informasi')" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold transition {{ $tab === 'informasi' ? 'bg-white text-neutral-900 shadow-border' : 'text-neutral-600 hover:text-neutral-900' }}">
            Informasi Jadwal
        </button>
        <button type="button" wire:click="setTab('kehadiran')" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold transition {{ $tab === 'kehadiran' ? 'bg-white text-neutral-900 shadow-border' : 'text-neutral-600 hover:text-neutral-900' }}">
            Kehadiran
        </button>
        <button type="button" wire:click="setTab('materi')" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold transition {{ $tab === 'materi' ? 'bg-white text-neutral-900 shadow-border' : 'text-neutral-600 hover:text-neutral-900' }}">
            Materi
        </button>
        <button type="button" wire:click="setTab('tugas')" class="flex-1 rounded-lg px-4 py-2.5 text-sm font-semibold transition {{ $tab === 'tugas' ? 'bg-white text-neutral-900 shadow-border' : 'text-neutral-600 hover:text-neutral-900' }}">
            Tugas
        </button>
    </div>

    @if ($tab === 'informasi')
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-neutral-900">Informasi Jadwal</h3>
                @unless ($editing)
                    <button
                        type="button"
                        wire:click="startEdit"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-medium text-neutral-700 shadow-border hover:bg-neutral-50"
                    >
                        <i data-lucide="pencil" class="h-3.5 w-3.5 text-neutral-400" aria-hidden="true"></i>
                        Edit
                    </button>
                @endunless
            </div>

            @if (! $editing)
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <p class="text-sm font-medium text-neutral-500">Hari</p>
                        <p class="mt-1 text-sm text-neutral-900">{{ $hariLabel[strtolower((string) $jadwal->hari)] ?? $jadwal->hari ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-500">Tanggal (jika hanya sekali pertemuan)</p>
                        <p class="mt-1 text-sm text-neutral-900">{{ $jadwal->tanggal?->translatedFormat('d F Y') ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-500">Jam</p>
                        <p class="mt-1 text-sm text-neutral-900">{{ substr((string) $jadwal->jam_mulai, 0, 5) }} – {{ substr((string) $jadwal->jam_selesai, 0, 5) }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-500">Ruangan</p>
                        <p class="mt-1 text-sm text-neutral-900">{{ $jadwal->ruangan?->nama ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-neutral-500">Jenis Kuliah</p>
                        <p class="mt-1 text-sm text-neutral-900">{{ $jadwal->jenisKuliah?->nama ?? '—' }}</p>
                    </div>
                </div>
            @else
                <form wire:submit="saveJadwal" class="space-y-5">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Hari</label>
                            <x-searchable-select
                                model="hari"
                                :options="collect(\App\Models\Jadwal::HARI)->mapWithKeys(fn ($h) => [$h => ucfirst($h)])->all()"
                                placeholder="— Tidak berulang mingguan —"
                            />
                            @error('hari') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Tanggal (jika hanya sekali pertemuan)</label>
                            <input type="date" wire:model="tanggal" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tanggal') ring-2 ring-red-500 @enderror shadow-border" />
                            @error('tanggal') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Ruangan</label>
                            <x-searchable-select model="id_ruangan" :options="$this->ruanganOptions" placeholder="— Pilih ruangan —" />
                            @error('id_ruangan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jenis Kuliah</label>
                            <x-searchable-select model="id_jenis_kuliah" :options="$this->jenisKuliahOptions" placeholder="— Pilih jenis kuliah —" />
                            @error('id_jenis_kuliah') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-neutral-200 pt-4">
                        <button type="button" wire:click="cancelEdit" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border hover:bg-neutral-50">
                            Batal
                        </button>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                            <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                            Simpan
                        </button>
                    </div>
                </form>
            @endif
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h3 class="mb-4 text-sm font-semibold text-neutral-900">Bahasan Pertemuan</h3>
            <form wire:submit="saveBahasan" class="space-y-4">
                <textarea wire:model="bahasan" rows="4" placeholder="Ringkasan topik/bahasan pertemuan ini" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('bahasan') ring-2 ring-red-500 @enderror shadow-border"></textarea>
                @error('bahasan') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                <div class="flex justify-end border-t border-neutral-200 pt-4">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                        <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                        Simpan Bahasan
                    </button>
                </div>
            </form>
        </div>
    @endif

    @if ($tab === 'kehadiran')
        @php
            $perkuliahan = $this->perkuliahanUntukKehadiran;
            $sesiAktif = $perkuliahan && $perkuliahan->waktu_mulai && ! $perkuliahan->waktu_selesai;
            $mahasiswaRows = $this->kehadiranMahasiswa;
            $statusBadge = [
                'hadir' => 'bg-emerald-100 text-emerald-800',
                'izin' => 'bg-sky-100 text-sky-800',
                'sakit' => 'bg-amber-100 text-amber-800',
                'alfa' => 'bg-rose-100 text-rose-800',
            ];
            $statusLabel = ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alfa' => 'Alfa'];
        @endphp
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-neutral-900">Kehadiran</h3>
                @if ($perkuliahan)
                    <a
                        href="{{ route('dosen.kehadiran.detail', $perkuliahan->id) }}"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-neutral-900 px-3 py-2 text-xs font-medium text-white shadow-sm transition hover:bg-neutral-800"
                    >
                        <i data-lucide="clipboard-check" class="h-3.5 w-3.5" aria-hidden="true"></i>
                        Isi Kehadiran
                    </a>
                @endif
            </div>

            <p class="text-sm text-neutral-600">
                Daftar mahasiswa diambil dari <strong>KRS</strong> yang sudah disetujui untuk kelas ini. Status
                kehadiran mengacu pada <strong>rekaman perkuliahan</strong> untuk slot jadwal ini (sesi berlangsung
                atau sesi terakhir).
            </p>

            @if ($perkuliahan)
                <p class="mt-2 text-xs text-neutral-500">
                    {{ $sesiAktif ? 'Sesi sedang berlangsung' : 'Sesi terakhir untuk slot ini' }} — kehadiran
                    (perkuliahan #{{ $perkuliahan->id }}).
                </p>
            @else
                <div class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-100">
                    Belum ada rekaman perkuliahan untuk slot jadwal ini.
                </div>
            @endif

            @if ($perkuliahan)
                <div class="mt-4 overflow-x-auto rounded-xl border border-neutral-200">
                    <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                        <thead class="bg-neutral-50">
                            <tr>
                                <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">No</th>
                                <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">NIM</th>
                                <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">Nama</th>
                                <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">Prodi</th>
                                <th class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">Status Kehadiran</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 bg-white">
                            @forelse ($mahasiswaRows as $idx => $row)
                                <tr wire:key="kehadiran-tab-{{ $row['id_krs'] }}">
                                    <td class="whitespace-nowrap px-3 py-2 text-neutral-500">{{ $idx + 1 }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-neutral-800">{{ $row['mahasiswa']['nim'] }}</td>
                                    <td class="px-3 py-2 font-medium text-neutral-900">{{ $row['mahasiswa']['nama'] }}</td>
                                    <td class="px-3 py-2 text-neutral-600">{{ $row['mahasiswa']['prodi']['nama'] ?? '—' }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $statusBadge[$row['kehadiran']['status'] ?? ''] ?? 'bg-neutral-100 text-neutral-600' }}">
                                            {{ $statusLabel[$row['kehadiran']['status'] ?? ''] ?? '—' }}
                                        </span>
                                        @if ($row['kehadiran']['keterangan'] ?? null)
                                            <span class="mt-1 block text-xs text-neutral-500">{{ $row['kehadiran']['keterangan'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-6 text-center text-neutral-500">Tidak ada mahasiswa terdaftar di KRS untuk kelas ini.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    @if ($tab === 'materi')
        <div class="space-y-6">
            @if (session('status_materi'))
                <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
                    <span>{{ session('status_materi') }}</span>
                </div>
            @endif

            <div class="rounded-2xl bg-white p-6 shadow-border">
                <h3 class="text-sm font-semibold text-neutral-900">Unggah Materi Perkuliahan</h3>
                <p class="mt-1 text-xs text-neutral-500">Maks. 10 MB per file.</p>
                <form wire:submit="uploadMateri" class="mt-4 space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nama / judul materi (opsional)</label>
                        <input type="text" wire:model="materiNama" placeholder="Default: nama file asli" class="w-full max-w-md rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('materiNama') ring-2 ring-red-500 @enderror shadow-border" />
                        @error('materiNama') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">File</label>
                        <input type="file" wire:model="materiFile" class="block w-full max-w-md text-sm text-neutral-600" />
                        <div wire:loading wire:target="materiFile" class="mt-1 text-xs text-neutral-500">Mengunggah…</div>
                        @error('materiFile') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end border-t border-neutral-200 pt-4">
                        <button type="submit" wire:loading.attr="disabled" wire:target="uploadMateri" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800 disabled:opacity-50">
                            <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
                            Unggah Materi
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl bg-white p-6 shadow-border">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Daftar File Materi</h3>
                @if ($this->materiRows->isEmpty())
                    <p class="rounded-lg border border-dashed border-neutral-200 bg-neutral-50 px-4 py-8 text-center text-sm text-neutral-500">
                        Belum ada file materi untuk jadwal ini.
                    </p>
                @else
                    <ul class="divide-y divide-neutral-100 rounded-xl border border-neutral-200">
                        @foreach ($this->materiRows as $m)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <i data-lucide="file-text" class="mt-0.5 h-5 w-5 shrink-0 text-sky-600" aria-hidden="true"></i>
                                    <div class="min-w-0">
                                        <p class="font-medium text-neutral-900">{{ $m->nama ?: $m->file }}</p>
                                        <p class="text-xs text-neutral-500">Diunggah: {{ $m->created_at?->translatedFormat('d F Y, H:i') ?? '—' }}</p>
                                    </div>
                                </div>
                                <a href="{{ asset('storage/'.ltrim($m->file, '/')) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-sky-700 shadow-border hover:bg-neutral-50">
                                    <i data-lucide="external-link" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                    Buka / unduh
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @if ($tab === 'tugas')
        <div class="space-y-6">
            @if (session('status_tugas'))
                <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
                    <span>{{ session('status_tugas') }}</span>
                </div>
            @endif

            <div class="rounded-2xl bg-white p-6 shadow-border">
                <h3 class="text-sm font-semibold text-neutral-900">Tambah Tugas untuk Jadwal Ini</h3>
                <p class="mt-1 text-xs text-neutral-500">Lampiran: PDF, Word, Excel, PowerPoint (maks. 10 MB).</p>
                <form wire:submit="submitTugas" class="mt-4 space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Judul tugas</label>
                        <input type="text" wire:model="tugasNama" placeholder="Contoh: Laporan praktikum 1" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tugasNama') ring-2 ring-red-500 @enderror shadow-border" />
                        @error('tugasNama') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Deskripsi / instruksi (opsional)</label>
                        <textarea wire:model="tugasDeskripsi" rows="3" placeholder="Penjelasan singkat untuk mahasiswa…" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tugasDeskripsi') ring-2 ring-red-500 @enderror shadow-border"></textarea>
                        @error('tugasDeskripsi') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid max-w-lg gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Mulai (opsional)</label>
                            <input type="datetime-local" wire:model="tugasTanggalMulai" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tugasTanggalMulai') ring-2 ring-red-500 @enderror shadow-border" />
                            @error('tugasTanggalMulai') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Tenggat selesai (opsional)</label>
                            <input type="datetime-local" wire:model="tugasTanggalSelesai" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tugasTanggalSelesai') ring-2 ring-red-500 @enderror shadow-border" />
                            @error('tugasTanggalSelesai') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-neutral-700">Lampiran tugas (opsional)</label>
                        <input type="file" wire:model="tugasFile" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" class="block w-full max-w-lg text-sm text-neutral-600" />
                        <div wire:loading wire:target="tugasFile" class="mt-1 text-xs text-neutral-500">Mengunggah…</div>
                        @error('tugasFile') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end border-t border-neutral-200 pt-4">
                        <button type="submit" wire:loading.attr="disabled" wire:target="submitTugas" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800 disabled:opacity-50">
                            <i data-lucide="upload" class="h-4 w-4" aria-hidden="true"></i>
                            Simpan Tugas
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl bg-white p-6 shadow-border">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Daftar Tugas</h3>
                @if ($this->tugasRows->isEmpty())
                    <p class="py-6 text-center text-sm text-neutral-500">Belum ada tugas untuk slot jadwal ini.</p>
                @else
                    <ul class="space-y-3">
                        @foreach ($this->tugasRows as $t)
                            <li class="rounded-xl border border-neutral-200 bg-neutral-50/50 p-4">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <h4 class="font-semibold text-neutral-900">{{ $t->nama }}</h4>
                                    <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-neutral-600 shadow-border">{{ $t->jumlah_submit }} pengumpulan</span>
                                </div>
                                @if ($t->dosen?->nama)
                                    <p class="mt-1 text-xs text-neutral-500">Oleh: {{ $t->dosen->nama }}</p>
                                @endif
                                @if ($t->deskripsi)
                                    <p class="mt-2 whitespace-pre-wrap text-sm text-neutral-600">{{ $t->deskripsi }}</p>
                                @endif
                                <dl class="mt-3 grid gap-1 text-xs text-neutral-600 sm:grid-cols-2">
                                    <div>
                                        <dt class="text-neutral-500">Mulai</dt>
                                        <dd class="font-medium text-neutral-800">{{ $t->tanggal_mulai?->translatedFormat('d F Y, H:i') ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-neutral-500">Selesai</dt>
                                        <dd class="font-medium text-neutral-800">{{ $t->tanggal_selesai?->translatedFormat('d F Y, H:i') ?? '—' }}</dd>
                                    </div>
                                </dl>
                                @if ($t->file)
                                    <a href="{{ asset('storage/'.ltrim($t->file, '/')) }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-sky-700 hover:underline">
                                        <i data-lucide="external-link" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                        Lampiran tugas
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="overflow-hidden rounded-2xl bg-white shadow-border">
                <div class="border-b border-neutral-100 bg-neutral-50 px-5 py-4">
                    <h3 class="text-sm font-semibold text-neutral-900">Pengumpulan Tugas</h3>
                    <p class="mt-0.5 text-xs text-neutral-500">Berkas yang diunggah mahasiswa ke tugas pada slot jadwal ini.</p>
                </div>
                @if ($this->tugasPengumpulanRows->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-neutral-500">Belum ada pengumpulan tugas dari mahasiswa.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-left text-sm">
                            <thead class="bg-neutral-50">
                                <tr class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                    <th class="px-4 py-3">No</th>
                                    <th class="px-4 py-3">NIM</th>
                                    <th class="px-4 py-3">Nama</th>
                                    <th class="px-4 py-3">Tugas</th>
                                    <th class="px-4 py-3">Waktu kirim</th>
                                    <th class="px-4 py-3 text-center">Berkas</th>
                                    <th class="px-4 py-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-100">
                                @foreach ($this->tugasPengumpulanRows as $idx => $row)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 text-neutral-500">{{ $idx + 1 }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 font-mono text-neutral-800">{{ $row->mahasiswa?->nim ?? '—' }}</td>
                                        <td class="px-4 py-3 font-medium text-neutral-900">{{ $row->mahasiswa?->nama ?? '—' }}</td>
                                        <td class="px-4 py-3 text-neutral-600">{{ $row->tugas?->nama ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-neutral-600">{{ $row->tanggal_submit?->translatedFormat('d F Y, H:i') ?? '—' }}</td>
                                        <td class="px-4 py-3 text-center">
                                            @if ($row->file)
                                                <a href="{{ asset('storage/'.ltrim($row->file, '/')) }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-medium text-sky-700 hover:underline">
                                                    <i data-lucide="external-link" class="h-3.5 w-3.5 shrink-0" aria-hidden="true"></i>
                                                    Buka berkas
                                                </a>
                                            @else
                                                <span class="text-neutral-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if (strtolower((string) $row->status) === 'accepted')
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                                    <i data-lucide="check" class="h-3 w-3" aria-hidden="true"></i>
                                                    Diterima
                                                </span>
                                            @else
                                                <button type="button" wire:click="terimaPengumpulan({{ $row->id }})" class="inline-flex items-center gap-1.5 rounded-lg bg-neutral-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm transition hover:bg-neutral-800">
                                                    <i data-lucide="check" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                                    Tandai diterima
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @php
        $tanggalCekJadwal = $jadwal->tanggal ?? \Carbon\Carbon::now();
        $labelJadwalSingkat = $tanggalCekJadwal->translatedFormat('l, d F Y')
            .', pukul '.substr((string) $jadwal->jam_mulai, 0, 5).'–'.substr((string) $jadwal->jam_selesai, 0, 5);
    @endphp

    {{-- Modal 1/2 mulai sesi: isi materi. Sama persis dengan modal "konfirmasi_mulai_materi" di FE. --}}
    @if ($mulaiDialog === 'konfirmasi_mulai_materi')
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Mulai sesi perkuliahan?</h3>
                <p class="mt-2 text-sm text-neutral-600">
                    <strong>Waktu mulai</strong> di server akan dicatat otomatis dari waktu saat ini. Isian di bawah
                    awalnya diambil dari <strong>bahasan jadwal</strong> (tab Materi); Anda boleh mengubahnya — teks
                    akan disimpan ke kolom <strong>materi</strong> pada rekaman perkuliahan.
                </p>
                <label class="mt-4 block text-sm font-medium text-neutral-800">
                    Materi / ringkasan sesi
                    <textarea
                        wire:model="modalMateriSesi"
                        rows="5"
                        placeholder="Contoh: topik yang akan dibahas pada sesi ini…"
                        class="mt-1.5 w-full resize-y rounded-lg px-3 py-2 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                    ></textarea>
                </label>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelMulaiDialog" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                        Batal
                    </button>
                    <button type="button" wire:click="konfirmasiMulaiDariModal" class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-sky-700">
                        Mulai sekarang
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal 2/2 mulai sesi: peringatan di luar jendela jadwal. Sama persis dengan modal
         "luar_jadwal" di FE — tetap bisa dilanjutkan. --}}
    @if ($mulaiDialog === 'luar_jadwal')
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-border-lg ring-1 ring-amber-200">
                <h3 class="text-base font-semibold text-amber-900">Waktu tidak sesuai jadwal</h3>
                <p class="mt-2 text-sm text-neutral-700">
                    Waktu sekarang di luar jendela jadwal yang diizinkan (mulai paling cepat 30 menit sebelum jam
                    mulai, hingga jam selesai).
                </p>
                <p class="mt-2 text-sm font-medium text-neutral-800">Jadwal: {{ $labelJadwalSingkat }}</p>
                <p class="mt-2 text-sm text-neutral-600">Tetap lanjutkan? Waktu mulai sesi akan tetap dicatat sebagai waktu saat ini.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelMulaiDialog" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                        Batal
                    </button>
                    <button type="button" wire:click="konfirmasiMulaiLuarJadwal" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700">
                        Tetap mulai
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal selesaikan sesi: isi realisasi materi. Sama persis dengan modal "selesaiDialog" di FE. --}}
    @if ($selesaiDialogOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Selesaikan sesi?</h3>
                <p class="mt-2 text-sm text-neutral-600">
                    <strong>Waktu selesai</strong> akan dicatat otomatis dari waktu saat ini. Isi ringkasan apa yang
                    benar-benar dibahas pada sesi ini (disimpan ke <strong>realisasi materi</strong> pada rekaman
                    perkuliahan).
                </p>
                <label class="mt-4 block text-sm font-medium text-neutral-800">
                    Realisasi materi / pembahasan
                    <textarea
                        wire:model="formRealisasiMateriSelesai"
                        rows="5"
                        placeholder="Contoh: materi yang terealisasi, catatan tambahan…"
                        class="mt-1.5 w-full resize-y rounded-lg px-3 py-2 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border"
                    ></textarea>
                </label>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelSelesaiDialog" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                        Batal
                    </button>
                    <button type="button" wire:click="submitSelesaiSesi" class="inline-flex items-center gap-2 rounded-lg bg-neutral-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-neutral-900">
                        Simpan &amp; akhiri sesi
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
