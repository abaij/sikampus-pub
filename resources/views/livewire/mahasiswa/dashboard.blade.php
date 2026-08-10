@php
    $mhs = $this->mahasiswa;
    $dosenWali = $this->dosenWaliAktif;
    $pengumumanList = $this->pengumumanList;
    $ipData = $this->ipPerSemester;
    $ktm = $this->ktm;
    $selected = $this->selectedPengumuman;

    $prioritasBadge = [
        'high' => ['label' => 'Penting', 'class' => 'border-rose-200 bg-rose-100 text-rose-700'],
        'medium' => ['label' => 'Sedang', 'class' => 'border-amber-200 bg-amber-100 text-amber-700'],
        'low' => ['label' => 'Rendah', 'class' => 'border-sky-200 bg-sky-100 text-sky-700'],
    ];

    $ipBarColor = function (?float $ip) {
        if ($ip === null) return 'bg-neutral-300';
        if ($ip >= 3.5) return 'bg-emerald-500';
        if ($ip >= 3.0) return 'bg-sky-500';
        if ($ip >= 2.5) return 'bg-amber-500';
        return 'bg-rose-500';
    };
@endphp

@section('title', 'Dashboard Mahasiswa — ' . config('app.name'))
@section('header_title', 'Dashboard')
@section('header_subtitle', 'Selamat datang, ' . (auth()->user()->name ?? 'Mahasiswa'))

<div class="space-y-6">
    {{-- Quick actions --}}
    <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('mahasiswa.krs') }}" class="rounded-2xl bg-white p-6 shadow-border transition hover:shadow-border-lg">
            <div class="flex items-center gap-4">
                <div class="rounded-xl bg-sky-100 p-3">
                    <i data-lucide="book-open" class="h-6 w-6 text-sky-600" aria-hidden="true"></i>
                </div>
                <div>
                    <p class="text-sm text-neutral-500">KRS</p>
                    <p class="text-lg font-semibold text-neutral-900">Kartu Rencana Studi</p>
                </div>
            </div>
        </a>
        <a href="{{ route('mahasiswa.nilai.transkrip') }}" class="rounded-2xl bg-white p-6 shadow-border transition hover:shadow-border-lg">
            <div class="flex items-center gap-4">
                <div class="rounded-xl bg-emerald-100 p-3">
                    <i data-lucide="file-text" class="h-6 w-6 text-emerald-600" aria-hidden="true"></i>
                </div>
                <div>
                    <p class="text-sm text-neutral-500">Nilai</p>
                    <p class="text-lg font-semibold text-neutral-900">Transkrip Nilai</p>
                </div>
            </div>
        </a>
        <a href="{{ route('mahasiswa.jadwal') }}" class="rounded-2xl bg-white p-6 shadow-border transition hover:shadow-border-lg">
            <div class="flex items-center gap-4">
                <div class="rounded-xl bg-amber-100 p-3">
                    <i data-lucide="calendar" class="h-6 w-6 text-amber-600" aria-hidden="true"></i>
                </div>
                <div>
                    <p class="text-sm text-neutral-500">Jadwal</p>
                    <p class="text-lg font-semibold text-neutral-900">Jadwal Kuliah</p>
                </div>
            </div>
        </a>
        <a href="{{ route('mahasiswa.profil') }}" class="rounded-2xl bg-white p-6 shadow-border transition hover:shadow-border-lg">
            <div class="flex items-center gap-4">
                <div class="rounded-xl bg-pink-100 p-3">
                    <i data-lucide="graduation-cap" class="h-6 w-6 text-pink-600" aria-hidden="true"></i>
                </div>
                <div>
                    <p class="text-sm text-neutral-500">Profil</p>
                    <p class="text-lg font-semibold text-neutral-900">Biodata</p>
                </div>
            </div>
        </a>
    </div>

    <div class="grid gap-6 md:grid-cols-2">
        {{-- Informasi akademik --}}
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h3 class="mb-4 text-lg font-semibold text-neutral-900">Informasi Akademik</h3>
            <div class="space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">NIM:</span>
                    <span class="font-semibold text-neutral-900">{{ $mhs->nim ?: '-' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">Status:</span>
                    <span class="font-semibold text-neutral-900">{{ $mhs->status_akademik?->nama ?: '-' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">Email:</span>
                    <span class="font-semibold text-neutral-900">{{ auth()->user()->email ?? '-' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">Angkatan:</span>
                    <span class="font-semibold text-neutral-900">{{ $mhs->semester_masuk?->nama ?: '-' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">Prodi:</span>
                    <span class="font-semibold text-neutral-900">{{ $mhs->prodi?->nama ?: '-' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">Jenjang:</span>
                    <span class="font-semibold text-neutral-900">{{ $mhs->prodi?->jenjang?->nama ?: '-' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">Kelompok Kelas:</span>
                    <span class="font-semibold text-neutral-900">{{ $mhs->grup_mahasiswa?->nama ?: '-' }}</span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-neutral-500">Dosen Wali:</span>
                    <span class="font-semibold text-neutral-900">{{ trim(($dosenWali?->dosen?->gelar_depan ?? '') . ' ' . ($dosenWali?->dosen?->nama ?? '') . ' ' . ($dosenWali?->dosen?->gelar_belakang ?? '')) ?: '-' }}</span>
                </div>
                @if ($mhs->prodi?->fakultas?->nama)
                    <div class="flex justify-between text-sm">
                        <span class="text-neutral-500">Fakultas:</span>
                        <span class="font-semibold text-neutral-900">{{ $mhs->prodi->fakultas->nama }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Ringkasan KTM --}}
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="mb-4 flex items-center gap-2">
                <i data-lucide="id-card" class="h-5 w-5 text-emerald-600" aria-hidden="true"></i>
                <h3 class="text-lg font-semibold text-neutral-900">Kartu Tanda Mahasiswa</h3>
            </div>
            @if ($ktm && $ktm->file)
                <div class="space-y-4">
                    <div class="overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 p-2">
                        <img
                            src="{{ asset('storage/'.ltrim($ktm->file, '/')) }}"
                            alt="Kartu Tanda Mahasiswa"
                            class="mx-auto max-h-56 w-full max-w-md rounded-lg object-contain"
                        />
                    </div>
                    <a
                        href="{{ route('mahasiswa.ktm') }}"
                        class="inline-flex items-center gap-2 rounded-xl border border-neutral-200 bg-white px-4 py-2 text-sm font-semibold text-neutral-700 shadow-sm transition hover:-translate-y-0.5 hover:shadow"
                    >
                        <i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i>
                        Kelola KTM
                    </a>
                </div>
            @else
                <div class="space-y-3">
                    <p class="text-sm text-neutral-600">Anda belum memiliki KTM digital. Buat KTM jika pihak kampus sudah mengunggah template.</p>
                    <a
                        href="{{ route('mahasiswa.ktm') }}"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                    >
                        <i data-lucide="id-card" class="h-4 w-4" aria-hidden="true"></i>
                        Buat KTM
                    </a>
                </div>
            @endif
        </div>

        {{-- Indeks Prestasi per semester --}}
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="mb-4 flex items-center gap-2">
                <i data-lucide="trending-up" class="h-5 w-5 text-emerald-600" aria-hidden="true"></i>
                <h3 class="text-lg font-semibold text-neutral-900">Indeks Prestasi per Semester</h3>
            </div>
            @if (empty($ipData))
                <p class="py-8 text-center text-sm text-neutral-500">Belum ada data IP</p>
            @else
                <div class="overflow-x-auto">
                    <div class="min-w-[400px]">
                        <div class="mb-2 flex items-end justify-between border-b border-neutral-200 pb-2">
                            @foreach ($ipData as $item)
                                <div class="flex-1 text-center" style="min-width:60px">
                                    <p class="truncate text-xs font-medium text-neutral-600">{{ $item['semester']->kode }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex items-end gap-2">
                            @foreach ($ipData as $item)
                                @php
                                    $value = $item['ip'] ?? 0;
                                    $height = max(($value / 4.0) * 100, $value > 0 ? 4 : 0);
                                @endphp
                                <div class="flex flex-1 flex-col items-center" style="min-width:60px">
                                    <div class="relative w-full">
                                        <div
                                            class="w-full rounded-t transition-all duration-500 hover:opacity-80 {{ $ipBarColor($item['ip']) }}"
                                            style="height: {{ $height }}px"
                                            title="IP: {{ $item['ip'] !== null ? number_format($item['ip'], 2) : '-' }}"
                                        ></div>
                                    </div>
                                    <p class="mt-2 text-xs font-semibold text-neutral-700">{{ $item['ip'] !== null ? number_format($item['ip'], 2) : '-' }}</p>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-xs text-neutral-500">
                            <div class="h-3 w-3 rounded bg-emerald-500"></div><span>&ge; 3.5</span>
                            <div class="ml-2 h-3 w-3 rounded bg-sky-500"></div><span>3.0 - 3.49</span>
                            <div class="ml-2 h-3 w-3 rounded bg-amber-500"></div><span>2.5 - 2.99</span>
                            <div class="ml-2 h-3 w-3 rounded bg-rose-500"></div><span>&lt; 2.5</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Pengumuman --}}
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="mb-4 flex items-center gap-2">
                <i data-lucide="bell" class="h-5 w-5 text-sky-600" aria-hidden="true"></i>
                <h3 class="text-lg font-semibold text-neutral-900">Pengumuman</h3>
            </div>
            @if ($pengumumanList->isEmpty())
                <p class="text-sm text-neutral-500">Tidak ada pengumuman baru</p>
            @else
                <div class="space-y-3">
                    @foreach ($pengumumanList as $item)
                        @php
                            $truncated = strlen($item->isi) > 50 ? substr($item->isi, 0, 50).'...' : $item->isi;
                            $badge = $prioritasBadge[$item->prioritas] ?? ['label' => 'Umum', 'class' => 'border-neutral-200 bg-neutral-100 text-neutral-700'];
                        @endphp
                        <button
                            type="button"
                            wire:click="showPengumuman({{ $item->id }})"
                            onclick="document.getElementById('pengumuman-modal').showModal()"
                            class="block w-full rounded-xl border border-neutral-200 p-4 text-left transition hover:shadow-border"
                        >
                            <div class="mb-2 flex items-start justify-between gap-3">
                                <h4 class="flex-1 text-sm font-semibold text-neutral-900">{{ $item->judul }}</h4>
                                @if ($item->prioritas)
                                    <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-medium {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                @endif
                            </div>
                            <p class="mb-2 text-sm text-neutral-600">{{ $truncated }}</p>
                            @if ($item->tanggal_selesai)
                                <p class="text-xs text-neutral-500">Berlaku hingga: {{ $item->tanggal_selesai->translatedFormat('d F Y') }}</p>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Modal detail pengumuman --}}
    <dialog id="pengumuman-modal" class="fixed top-1/2 left-1/2 m-0 w-full max-w-2xl -translate-x-1/2 -translate-y-1/2 rounded-2xl p-0 shadow-border-lg backdrop:bg-neutral-900/40">
        @if ($selected)
            <div class="flex max-h-[90vh] flex-col">
                <div class="flex items-start justify-between gap-4 border-b border-neutral-200 px-6 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="mb-2 flex items-center gap-3">
                            <h3 class="text-lg font-semibold text-neutral-900">{{ $selected->judul }}</h3>
                            @if ($selected->prioritas)
                                @php $badge = $prioritasBadge[$selected->prioritas] ?? ['label' => 'Umum', 'class' => 'border-neutral-200 bg-neutral-100 text-neutral-700']; @endphp
                                <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-medium {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-4 text-xs text-neutral-500">
                            @if ($selected->tanggal_mulai)
                                <span>Mulai: {{ $selected->tanggal_mulai->translatedFormat('d F Y') }}</span>
                            @endif
                            @if ($selected->tanggal_selesai)
                                <span>Berakhir: {{ $selected->tanggal_selesai->translatedFormat('d F Y') }}</span>
                            @endif
                        </div>
                    </div>
                    <button
                        type="button"
                        wire:click="closePengumuman"
                        onclick="document.getElementById('pengumuman-modal').close()"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-600"
                    >
                        <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-6 py-4">
                    <p class="text-sm leading-relaxed whitespace-pre-wrap text-neutral-700">{{ $selected->isi }}</p>
                </div>
                <div class="border-t border-neutral-200 px-6 py-4">
                    <button
                        type="button"
                        wire:click="closePengumuman"
                        onclick="document.getElementById('pengumuman-modal').close()"
                        class="w-full rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        @endif
    </dialog>
</div>
