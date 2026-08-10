@php
    $adminNavGroups = [
        [
            'label' => 'Akademik',
            'items' => [
                ['label' => 'Kurikulum', 'children' => [
                    ['route' => 'admin.akademik.kurikulum', 'label' => 'Kurikulum'],
                    ['route' => 'admin.akademik.matkul', 'label' => 'Mata Kuliah'],
                ]],
                ['label' => 'Perkuliahan', 'children' => [
                    ['route' => 'admin.akademik.kelas', 'label' => 'Kelas'],
                    ['route' => 'admin.akademik.jadwal', 'label' => 'Jadwal Kuliah'],
                    ['route' => 'admin.akademik.jadwal-ujian', 'label' => 'Jadwal Ujian'],
                    ['route' => 'admin.akademik.perkuliahan', 'label' => 'Perkuliahan'],
                ]],
                ['label' => 'Nilai', 'children' => [
                    ['route' => 'admin.akademik.nilai', 'label' => 'Nilai'],
                    ['route' => 'admin.akademik.konversi-nilai', 'label' => 'Konversi Nilai'],
                    ['route' => 'admin.akademik.jenis-penilaian', 'label' => 'Jenis Penilaian'],
                    ['route' => 'admin.akademik.rentang-nilai', 'label' => 'Rentang Nilai'],
                ]],
                ['route' => 'admin.akademik.krs', 'label' => 'KRS'],
                ['label' => 'Akhir Studi', 'children' => [
                    ['route' => 'admin.akademik.tugas-akhir', 'label' => 'Tugas Akhir'],
                    ['route' => 'admin.akademik.yudisium', 'label' => 'Yudisium'],
                    ['route' => 'admin.akademik.wisuda', 'label' => 'Wisuda'],
                ]],
            ],
        ],
        [
            'label' => 'Administrasi',
            'items' => [
                ['label' => 'Dosen', 'children' => [
                    ['route' => 'admin.administrasi.dosen', 'label' => 'Dosen'],
                    ['route' => 'admin.administrasi.dosen-wali', 'label' => 'Dosen Wali'],
                    ['route' => 'admin.administrasi.dosen.import', 'label' => 'Import Dosen'],
                ]],
                ['label' => 'Mahasiswa', 'children' => [
                    ['route' => 'admin.administrasi.mahasiswa', 'label' => 'Mahasiswa'],
                    ['route' => 'admin.administrasi.kelas-mahasiswa', 'label' => 'Kelas Mahasiswa'],
                    ['route' => 'admin.administrasi.mahasiswa.import', 'label' => 'Import Mahasiswa'],
                    ['route' => 'admin.administrasi.ktm', 'label' => 'KTM'],
                ]],
                ['label' => 'Institusi', 'children' => [
                    ['route' => 'admin.fakultas.index', 'label' => 'Fakultas'],
                    ['route' => 'admin.prodi.index', 'label' => 'Prodi'],
                    ['route' => 'admin.perguruan-tinggi', 'label' => 'Perguruan Tinggi'],
                ]],
                ['route' => 'admin.jenjang.index', 'label' => 'Jenjang'],
                ['route' => 'admin.administrasi.ruangan', 'label' => 'Ruangan'],
                ['route' => 'admin.administrasi.survey', 'label' => 'Survey'],
                ['route' => 'admin.administrasi.pengumuman', 'label' => 'Pengumuman'],
            ],
        ],
        [
            'label' => 'Keuangan',
            'items' => [
                ['label' => 'Tagihan', 'children' => [
                    ['route' => 'admin.keuangan.tagihan', 'label' => 'Tagihan'],
                    ['route' => 'admin.keuangan.tagihan.generate', 'label' => 'Generate Tagihan'],
                ]],
                ['label' => 'Pembayaran', 'children' => [
                    ['route' => 'admin.keuangan.pembayaran', 'label' => 'Pembayaran'],
                    ['route' => 'admin.keuangan.pembayaran.laporan-pelunasan', 'label' => 'Laporan Pelunasan'],
                ]],
                ['label' => 'Keringanan Biaya', 'children' => [
                    ['route' => 'admin.keuangan.keringanan-biaya', 'label' => 'Keringanan Biaya'],
                    ['route' => 'admin.keuangan.jenis-keringanan-biaya', 'label' => 'Jenis Keringanan Biaya'],
                ]],
                ['route' => 'admin.keuangan.struktur-biaya', 'label' => 'Struktur Biaya'],
                ['route' => 'admin.keuangan.komponen-biaya', 'label' => 'Komponen Biaya'],
                ['route' => 'admin.keuangan.kategori-biaya', 'label' => 'Kategori Biaya'],
                ['route' => 'admin.keuangan.aturan-akses-keuangan', 'label' => 'Aturan Akses Keuangan'],
            ],
        ],
        [
            'label' => 'Pengaturan',
            'items' => [
                ['label' => 'Akademik', 'children' => [
                    ['route' => 'admin.semester.index', 'label' => 'Semester'],
                    ['route' => 'admin.jalur-masuk.index', 'label' => 'Jalur Masuk'],
                    ['route' => 'admin.jenis-daftar.index', 'label' => 'Jenis Daftar'],
                    ['route' => 'admin.jenis-matkul.index', 'label' => 'Jenis Mata Kuliah'],
                    ['route' => 'admin.status-akademik.index', 'label' => 'Status Akademik'],
                ]],
                ['label' => 'Pengguna', 'children' => [
                    ['route' => 'admin.pengguna.index', 'label' => 'Pengguna'],
                    ['route' => 'admin.pengguna.role.index', 'label' => 'Role'],
                    ['route' => 'admin.pengguna.permission.index', 'label' => 'Permission'],
                ]],
                ['label' => 'Sistem', 'children' => [
                    ['route' => 'admin.sistem.pengaturan', 'label' => 'SMTP'],
                    ['route' => 'admin.sistem.lisensi', 'label' => 'License Key'],
                    ['route' => 'admin.sistem.plugin', 'label' => 'Plugin'],
                ]]
            ],
        ],
    ];

    // Sembunyikan menu yang rutenya memang akan ditolak middleware panel.permission, memakai
    // peta yang sama (config/panel_access.php) supaya tampilan dan fungsi tidak pernah beda.
    $navUser = auth()->user();

    // Grup navbar dari plugin (lihat app/Support/Plugins/AdminNavRegistry.php).
    // HANYA di-merge untuk superadmin, dan SEBELUM filter PanelAccess di bawah
    // — PanelAccess menganggap route tanpa entri di config/panel_access.php
    // sebagai "boleh diakses semua admin", dan route plugin (nama
    // "plugins.{slug}.*") memang sengaja tidak pernah punya entri di situ.
    // Gate isSuperadmin() di sini SATU-SATUNYA lapisan otorisasi untuk grup
    // nav plugin (keputusan produk: superadmin-only, tanpa override).
    if ($navUser && $navUser->isSuperadmin()) {
        $adminNavGroups = array_merge(
            $adminNavGroups,
            app(\App\Support\Plugins\AdminNavRegistry::class)->all()
        );
    }

    $adminNavGroups = collect($adminNavGroups)
        ->map(function (array $group) use ($navUser) {
            $group['items'] = collect($group['items'])
                ->map(function (array $item) use ($navUser) {
                    if (isset($item['children'])) {
                        $item['children'] = array_values(array_filter(
                            $item['children'],
                            fn ($child) => \App\Support\PanelAccess::allows($navUser, $child['route'])
                        ));
                    }

                    return $item;
                })
                ->filter(fn (array $item) => isset($item['children'])
                    ? $item['children'] !== []
                    : \App\Support\PanelAccess::allows($navUser, $item['route']))
                ->values()
                ->all();

            return $group;
        })
        ->filter(fn (array $group) => $group['items'] !== [])
        ->values()
        ->all();

    // Item aktif kalau route-nya sendiri cocok, atau (untuk item yang punya submenu) salah satu child-nya cocok.
    $isItemActive = function (array $item) {
        if (isset($item['children'])) {
            return collect($item['children'])->contains(fn ($child) => request()->routeIs($child['route'].'*'));
        }

        return request()->routeIs($item['route'].'*');
    };
@endphp

{{-- Desktop (md ke atas) — dropdown grup pakai CSS hover (bukan Alpine/JS) karena layout ini
     dipakai baik oleh halaman Livewire maupun Blade biasa (mis. dashboard), dan Livewire hanya
     menyuntikkan Alpine ke halaman yang benar-benar memuat komponen Livewire.
     Nested submenu (mis. Administrasi > Mahasiswa > Mahasiswa/Kelas Mahasiswa) pakai Tailwind
     named group (group/l1, group/l2) supaya hover level-2 tidak ikut memicu/ketiban level-1.
     Di bawah md disembunyikan total — hover tidak ada di layar sentuh, jadi submenu-nya tidak
     akan pernah bisa dibuka; versi mobile-nya ada di blok accordion setelah ini. --}}
<div class="hidden items-center gap-1 md:flex">
    <a
        href="{{ route('admin.dashboard') }}"
        class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition {{ request()->routeIs('admin.dashboard') ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
    >
        Dashboard
    </a>

    @foreach ($adminNavGroups as $group)
        @php
            $groupActive = collect($group['items'])->contains($isItemActive);
        @endphp
        <div class="group/l1 relative">
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium transition {{ $groupActive ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
            >
                {{ $group['label'] }}
                <i data-lucide="chevron-down" class="h-3.5 w-3.5" aria-hidden="true"></i>
            </button>
            <div class="invisible absolute left-0 top-full z-20 w-52 pt-1 opacity-0 transition-opacity duration-100 group-hover/l1:visible group-hover/l1:opacity-100">
                <div class="rounded-lg bg-white py-1 shadow-border-lg">
                    @foreach ($group['items'] as $item)
                        @if (isset($item['children']))
                            @php $itemActive = $isItemActive($item); @endphp
                            <div class="group/l2 relative">
                                <button
                                    type="button"
                                    class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition {{ $itemActive ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
                                >
                                    {{ $item['label'] }}
                                    <i data-lucide="chevron-right" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                </button>
                                <div class="invisible absolute left-full top-0 z-30 w-52 pl-1 opacity-0 transition-opacity duration-100 group-hover/l2:visible group-hover/l2:opacity-100">
                                    <div class="rounded-lg bg-white py-1 shadow-border-lg">
                                        @foreach ($item['children'] as $child)
                                            <a
                                                href="{{ route($child['route']) }}"
                                                class="block px-3 py-2 text-sm transition {{ request()->routeIs($child['route'].'*') ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
                                            >
                                                {{ $child['label'] }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @else
                            <a
                                href="{{ route($item['route']) }}"
                                class="block px-3 py-2 text-sm transition {{ request()->routeIs($item['route'].'*') ? 'bg-neutral-100 text-neutral-800' : 'text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900' }}"
                            >
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Mobile (di bawah md) — hamburger + panel accordion. Pakai <details>/<summary> native
     (bukan Alpine/x-data) dengan alasan sama seperti di atas: halaman Blade biasa tanpa
     komponen Livewire tidak menyuntikkan Alpine sama sekali. Panel di-posisikan absolute
     terhadap <header> (lihat class "relative" di layouts/web.blade.php) supaya lebar penuh
     tanpa terpengaruh sizing flex row header, dan togglenya cuma classList.toggle('hidden')
     lewat onclick inline — pola yang sama dipakai modal konfirmasi logout di layout ini. --}}
<div class="md:hidden">
    <button
        type="button"
        onclick="document.getElementById('admin-mobile-nav-panel').classList.toggle('hidden')"
        class="flex h-10 w-10 items-center justify-center rounded-lg text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-900"
        aria-label="Buka menu navigasi"
        aria-controls="admin-mobile-nav-panel"
    >
        <i data-lucide="menu" class="h-5 w-5" aria-hidden="true"></i>
    </button>
</div>

<div id="admin-mobile-nav-panel" class="absolute inset-x-0 top-full z-30 hidden border-t border-neutral-200 bg-white shadow-border-lg md:hidden">
    <div class="max-h-[calc(100vh-4rem)] divide-y divide-neutral-100 overflow-y-auto px-4 py-2 sm:px-6">
        <a
            href="{{ route('admin.dashboard') }}"
            class="block px-1 py-3 text-sm font-medium transition {{ request()->routeIs('admin.dashboard') ? 'text-neutral-900' : 'text-neutral-600' }}"
        >
            Dashboard
        </a>

        @foreach ($adminNavGroups as $group)
            <details class="group">
                <summary class="flex cursor-pointer list-none items-center justify-between px-1 py-3 text-sm font-medium text-neutral-900 [&::-webkit-details-marker]:hidden">
                    {{ $group['label'] }}
                    <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-neutral-400 transition group-open:rotate-180" aria-hidden="true"></i>
                </summary>
                <div class="space-y-1 pb-3 pl-3">
                    @foreach ($group['items'] as $item)
                        @if (isset($item['children']))
                            <details class="group/sub">
                                <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg px-2 py-2 text-sm text-neutral-600 [&::-webkit-details-marker]:hidden">
                                    {{ $item['label'] }}
                                    <i data-lucide="chevron-down" class="h-3.5 w-3.5 shrink-0 text-neutral-400 transition group-open/sub:rotate-180" aria-hidden="true"></i>
                                </summary>
                                <div class="space-y-1 py-1 pl-3">
                                    @foreach ($item['children'] as $child)
                                        <a
                                            href="{{ route($child['route']) }}"
                                            class="block rounded-lg px-2 py-2 text-sm transition {{ request()->routeIs($child['route'].'*') ? 'bg-neutral-100 text-neutral-900' : 'text-neutral-600' }}"
                                        >
                                            {{ $child['label'] }}
                                        </a>
                                    @endforeach
                                </div>
                            </details>
                        @else
                            <a
                                href="{{ route($item['route']) }}"
                                class="block rounded-lg px-2 py-2 text-sm transition {{ request()->routeIs($item['route'].'*') ? 'bg-neutral-100 text-neutral-900' : 'text-neutral-600' }}"
                            >
                                {{ $item['label'] }}
                            </a>
                        @endif
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>
</div>
