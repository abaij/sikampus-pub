@php
    $mahasiswa = $this->mahasiswa;

    $initials = collect(explode(' ', trim($mahasiswa->nama)))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode('') ?: '?';
    $avatarUrl = $mahasiswa->foto ? asset('storage/'.ltrim($mahasiswa->foto, '/')) : null;

    $statusBadgeClass = function (?string $nama) {
        $nama = mb_strtolower(trim((string) $nama));
        return match (true) {
            $nama === '' => 'bg-neutral-100 text-neutral-600',
            str_contains($nama, 'aktif') => 'bg-emerald-50 text-emerald-700',
            str_contains($nama, 'cuti') => 'bg-amber-50 text-amber-700',
            str_contains($nama, 'lulus') => 'bg-blue-50 text-blue-700',
            str_contains($nama, 'dropout') => 'bg-rose-50 text-rose-700',
            default => 'bg-neutral-100 text-neutral-600',
        };
    };
@endphp

@section('title', 'Detail Mahasiswa — ' . config('app.name'))
@section('header_title', 'Detail Mahasiswa')
@section('header_subtitle', $mahasiswa->nama)
@section('header_icon', 'graduation-cap')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Administrasi'],
        ['label' => 'Mahasiswa', 'route' => route('admin.administrasi.mahasiswa')],
        ['label' => $mahasiswa->nama],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ $backUrl }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
    @if (\App\Support\PanelAccess::can(auth()->user(), 'mahasiswa', 'update'))
        <a
            href="{{ route('admin.administrasi.mahasiswa.edit', $mahasiswa->id) }}{{ $returnQuery ? '?'.$returnQuery : '' }}"
            class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
        >
            <i data-lucide="pencil" class="h-4 w-4" aria-hidden="true"></i>
            Ubah
        </a>
    @endif
@endsection

<div>
    @if (session('status'))
        <div class="mb-4 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    {{-- Tab Navigation --}}
    <div class="mb-6 border-b border-neutral-200">
        <nav class="-mb-px flex flex-wrap gap-6">
            @foreach ([['key' => 'biodata', 'label' => 'Biodata'], ['key' => 'krs', 'label' => 'KRS'], ['key' => 'nilai', 'label' => 'Nilai'], ['key' => 'aktifitas', 'label' => 'Aktifitas']] as $tab)
                <button
                    type="button"
                    wire:click="setTab('{{ $tab['key'] }}')"
                    class="whitespace-nowrap border-b-2 px-1 py-3 text-sm font-semibold transition {{ $activeTab === $tab['key'] ? 'border-neutral-900 text-neutral-900' : 'border-transparent text-neutral-500 hover:border-neutral-300 hover:text-neutral-700' }}"
                >
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab: Biodata --}}
    @if ($activeTab === 'biodata')
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="flex flex-col items-center gap-6 border-b border-neutral-200 pb-6 sm:flex-row sm:items-start">
                @if ($avatarUrl)
                    <img src="{{ $avatarUrl }}" alt="{{ $mahasiswa->nama }}" class="h-28 w-28 shrink-0 rounded-full object-cover ring-2 ring-neutral-200" />
                @else
                    <div class="flex h-28 w-28 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-3xl font-semibold text-neutral-900 ring-2 ring-neutral-200">
                        {{ $initials }}
                    </div>
                @endif
                <div class="min-w-0 text-center sm:text-left">
                    <h2 class="text-xl font-semibold tracking-tight text-neutral-900">{{ $mahasiswa->nama }}</h2>
                    @if ($mahasiswa->nim)
                        <p class="mt-1 text-sm text-neutral-500">NIM: {{ $mahasiswa->nim }}</p>
                    @endif
                </div>
            </div>

            <div class="mt-6">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Informasi Personal</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Nama</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->nama }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">NIM</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->nim ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Email</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->email ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">No. WA</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->no_wa ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Handphone</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->handphone ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Jenis Kelamin</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">
                            {{ $mahasiswa->jenis_kelamin === 'L' ? 'Laki-laki' : ($mahasiswa->jenis_kelamin === 'P' ? 'Perempuan' : '—') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Tempat, Tanggal Lahir</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">
                            {{ $mahasiswa->id_tempat_lahir ?? '—' }}{{ $mahasiswa->tanggal_lahir ? ', '.$mahasiswa->tanggal_lahir->translatedFormat('d F Y') : '' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">No. KTP</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->no_ktp ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">NPWP</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->npwp ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 border-t border-neutral-200 pt-6">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Alamat</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <p class="text-xs font-semibold uppercase text-neutral-500">Alamat</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->alamat ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">RT</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->rt ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">RW</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->rw ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Dusun</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->dusun ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Kelurahan</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->kelurahan ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Kode Pos</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->kode_pos ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Kecamatan</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->id_kecamatan ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Kota</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->kota->nama ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Provinsi</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->provinsi->nama ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Negara</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->negara->nama ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 border-t border-neutral-200 pt-6">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Informasi Akademik</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Program Studi</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">
                            {{ $mahasiswa->prodi->nama ?? '—' }}
                            @if ($mahasiswa->prodi?->kode)
                                <span class="text-xs text-neutral-400">({{ $mahasiswa->prodi->kode }})</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Kelas Mahasiswa</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">
                            {{ $mahasiswa->kelompok_kelas->nama ?? '—' }}
                            @if ($mahasiswa->kelompok_kelas?->kode)
                                <span class="text-xs text-neutral-400">({{ $mahasiswa->kelompok_kelas->kode }})</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Semester Masuk</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->semester_masuk->nama ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Status Akademik</p>
                        <p class="mt-1">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusBadgeClass($mahasiswa->status_akademik->nama ?? null) }}">
                                {{ $mahasiswa->status_akademik->nama ?? '—' }}
                            </span>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Mulai Semester</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->mulai_semester ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Jalur Masuk</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->jalur_masuk->nama ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Jenis Daftar</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->jenis_daftar->nama ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">SKS Diakui</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->sks_diakui ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Sekolah Asal</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->sekolah_asal ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">NIS</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->nis ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">NISN</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->nisn ?? '—' }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-6 border-t border-neutral-200 pt-6">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Orang Tua & Wali</h3>

                <div class="mb-6">
                    <h4 class="mb-3 text-xs font-semibold uppercase text-neutral-500">Ayah</h4>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Nama Ayah</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->ayah ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">NIK Ayah</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->nik_ayah ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Tanggal Lahir Ayah</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->tgl_lahir_ayah?->translatedFormat('d F Y') ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Pendidikan Ayah</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->pendidikan_ayah->nama ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Pekerjaan Ayah</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->pekerjaan_ayah->nama ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Penghasilan Ayah</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->penghasilan_ayah->nama ?? '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="mb-6">
                    <h4 class="mb-3 text-xs font-semibold uppercase text-neutral-500">Ibu</h4>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Nama Ibu</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->ibu ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">NIK Ibu</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->nik_ibu ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Tanggal Lahir Ibu</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->tgl_lahir_ibu?->translatedFormat('d F Y') ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Pendidikan Ibu</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->pendidikan_ibu->nama ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Pekerjaan Ibu</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->pekerjaan_ibu->nama ?? '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase text-neutral-500">Penghasilan Ibu</p>
                            <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->penghasilan_ibu->nama ?? '—' }}</p>
                        </div>
                    </div>
                </div>

                @if ($mahasiswa->wali)
                    <div>
                        <h4 class="mb-3 text-xs font-semibold uppercase text-neutral-500">Wali</h4>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p class="text-xs font-semibold uppercase text-neutral-500">Nama Wali</p>
                                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->wali ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase text-neutral-500">NIK Wali</p>
                                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->nik_wali ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase text-neutral-500">Tanggal Lahir Wali</p>
                                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->tgl_lahir_wali?->translatedFormat('d F Y') ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase text-neutral-500">Pendidikan Wali</p>
                                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->pendidikan_wali->nama ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase text-neutral-500">Pekerjaan Wali</p>
                                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->pekerjaan_wali->nama ?? '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase text-neutral-500">Penghasilan Wali</p>
                                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->penghasilan_wali->nama ?? '—' }}</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="mt-6 border-t border-neutral-200 pt-6">
                <h3 class="mb-4 text-sm font-semibold text-neutral-900">Informasi Keuangan</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Jumlah Biaya Masuk</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">
                            {{ $mahasiswa->jml_biaya_masuk !== null ? 'Rp'.number_format((float) $mahasiswa->jml_biaya_masuk, 0, ',', '.') : '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">Penerima KPS</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->penerima_kps ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-neutral-500">No. KPS</p>
                        <p class="mt-1 text-sm font-medium text-neutral-900">{{ $mahasiswa->no_kps ?? '—' }}</p>
                    </div>
                </div>
            </div>

            @if (\App\Support\PanelAccess::can(auth()->user(), 'mahasiswa', 'delete'))
                <div class="mt-6 border-t border-neutral-200 pt-6">
                    <button
                        type="button"
                        wire:click="confirmDeleteMahasiswa"
                        class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-100"
                    >
                        <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                        Hapus Mahasiswa
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- Tab: KRS --}}
    @if ($activeTab === 'krs')
        <div class="rounded-2xl bg-white p-6 shadow-border">
            @php $krsBySemester = $this->krsBySemester; @endphp

            @if ($krsBySemester->isEmpty())
                <div class="py-12 text-center text-neutral-500">Belum ada data KRS.</div>
            @else
                <div class="space-y-8">
                    @foreach ($krsBySemester as $group)
                        <div>
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 pb-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-neutral-900">{{ $group['semester']->nama }}</h3>
                                    <p class="mt-0.5 text-xs text-neutral-500">Kode: {{ $group['semester']->kode }}</p>
                                </div>
                                <div class="flex items-center gap-4 text-sm">
                                    <div class="text-right">
                                        <p class="text-neutral-500">SKS Diajukan</p>
                                        <p class="font-semibold text-neutral-900">{{ $group['total_sks_diajukan'] }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-neutral-500">SKS Diacc</p>
                                        <p class="font-semibold text-emerald-600">{{ $group['total_sks_diacc'] }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                        <tr>
                                            <th class="px-4 py-3">Kode</th>
                                            <th class="px-4 py-3">Mata Kuliah</th>
                                            <th class="px-4 py-3">Kelas</th>
                                            <th class="px-4 py-3">Dosen</th>
                                            <th class="px-4 py-3 text-center">SKS</th>
                                            <th class="px-4 py-3 text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-100">
                                        @foreach ($group['krs'] as $krs)
                                            @php $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null; @endphp
                                            <tr wire:key="krs-{{ $krs->id }}">
                                                <td class="px-4 py-3 font-mono text-xs text-neutral-900">{{ $matkul->kode ?? '—' }}</td>
                                                <td class="px-4 py-3 text-neutral-900">{{ $matkul->nama ?? '—' }}</td>
                                                <td class="px-4 py-3 text-neutral-600">{{ $krs->kelas->nama ?? '—' }}</td>
                                                <td class="px-4 py-3 text-neutral-600">{{ $krs->kelas->dosenPic->nama ?? '—' }}</td>
                                                <td class="px-4 py-3 text-center font-semibold text-neutral-900">{{ $matkul->sks ?? 0 }}</td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $krs->approved_at ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                                        {{ $krs->approved_at ? 'Disetujui' : 'Menunggu' }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Tab: Nilai --}}
    @if ($activeTab === 'nilai')
        <div class="rounded-2xl bg-white p-6 shadow-border">
            @php $nilaiBySemester = $this->nilaiBySemester; @endphp

            @if ($nilaiBySemester->isEmpty())
                <div class="py-12 text-center text-neutral-500">Belum ada data nilai.</div>
            @else
                <div class="space-y-8">
                    @foreach ($nilaiBySemester as $group)
                        <div>
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 pb-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-neutral-900">{{ $group['semester']->nama }}</h3>
                                    <p class="mt-0.5 text-xs text-neutral-500">Kode: {{ $group['semester']->kode }}</p>
                                </div>
                                <div class="flex items-center gap-6 text-sm">
                                    <div class="text-right">
                                        <p class="text-neutral-500">Total SKS</p>
                                        <p class="font-semibold text-neutral-900">{{ $group['total_sks'] }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-neutral-500">SKS dengan Nilai</p>
                                        <p class="font-semibold text-neutral-900">{{ $group['total_sks_dengan_nilai'] }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-neutral-500">IP Semester</p>
                                        <p class="text-lg font-semibold text-neutral-900">{{ number_format($group['ip'], 2) }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                        <tr>
                                            <th class="px-4 py-3">Kode</th>
                                            <th class="px-4 py-3">Mata Kuliah</th>
                                            <th class="px-4 py-3">Kelas</th>
                                            <th class="px-4 py-3">Dosen</th>
                                            <th class="px-4 py-3 text-center">SKS</th>
                                            <th class="px-4 py-3 text-center">Angka Mutu</th>
                                            <th class="px-4 py-3 text-center">Huruf Mutu</th>
                                            <th class="px-4 py-3 text-center">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-100">
                                        @foreach ($group['nilai_list'] as $row)
                                            @php $matkul = $row['krs']->kelas->kurikulumMatkul->matkul ?? null; $nilai = $row['nilai']; @endphp
                                            <tr wire:key="nilai-{{ $row['krs']->id }}">
                                                <td class="px-4 py-3 font-mono text-xs text-neutral-900">{{ $matkul->kode ?? '—' }}</td>
                                                <td class="px-4 py-3 text-neutral-900">{{ $matkul->nama ?? '—' }}</td>
                                                <td class="px-4 py-3 text-neutral-600">{{ $row['krs']->kelas->nama ?? '—' }}</td>
                                                <td class="px-4 py-3 text-neutral-600">{{ $row['krs']->kelas->dosenPic->nama ?? '—' }}</td>
                                                <td class="px-4 py-3 text-center font-semibold text-neutral-900">{{ $row['sks'] }}</td>
                                                <td class="px-4 py-3 text-center font-semibold text-neutral-900">{{ $nilai?->angka_mutu !== null ? number_format((float) $nilai->angka_mutu, 2) : '—' }}</td>
                                                <td class="px-4 py-3 text-center font-semibold text-neutral-900">{{ $nilai->huruf_mutu ?? '—' }}</td>
                                                <td class="px-4 py-3 text-center">
                                                    @if ($nilai)
                                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $nilai->is_final ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                                            {{ $nilai->is_final ? 'Final' : 'Sementara' }}
                                                        </span>
                                                    @else
                                                        <span class="inline-flex items-center rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-semibold text-neutral-600">Belum Ada</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Tab: Aktifitas --}}
    @if ($activeTab === 'aktifitas')
        <div class="rounded-2xl bg-white p-6 shadow-border">
            @php $aktivitasList = $this->aktivitasList; @endphp

            @if ($aktivitasList->isEmpty())
                <div class="py-12 text-center text-neutral-500">Belum ada riwayat status akademik.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th class="px-4 py-3">Semester</th>
                                <th class="px-4 py-3">Kode</th>
                                <th class="px-4 py-3">Status Akademik</th>
                                <th class="px-4 py-3">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            @foreach ($aktivitasList as $item)
                                <tr wire:key="aktivitas-{{ $item->id }}">
                                    <td class="px-4 py-3 text-neutral-900">{{ $item->semester->nama ?? '—' }}</td>
                                    <td class="px-4 py-3 text-neutral-600">{{ $item->semester->kode ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusBadgeClass($item->status_akademik->nama ?? null) }}">
                                            {{ $item->status_akademik->nama ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-neutral-600">{{ $item->keterangan ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- Modal: Konfirmasi Hapus Mahasiswa --}}
    @if ($confirmingMahasiswaDelete)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Hapus mahasiswa ini?</h3>
                <p class="mt-2 text-sm text-neutral-600">Tindakan ini tidak dapat dibatalkan.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelDeleteMahasiswa" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                        Batal
                    </button>
                    <button type="button" wire:click="deleteMahasiswa" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700">
                        Hapus
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
