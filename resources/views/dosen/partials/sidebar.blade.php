@php
    // Struktur & urutan menu meniru DosenSidebar.tsx di siak-frontend (../siak-frontend/components/dosen/DosenSidebar.tsx).
    // Rute yang modulnya belum diport ('dosen.coming-soon' lewat Route::view) tetap didaftarkan
    // supaya sidebar sudah lengkap sejak awal — cukup ganti target rute saat modulnya dibangun.
    $dosenNavItems = [
        ['route' => 'dosen.dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard'],
        ['label' => 'Perkuliahan', 'icon' => 'calendar', 'children' => [
            ['route' => 'dosen.kelas', 'label' => 'Kelas'],
            ['route' => 'dosen.jadwal', 'label' => 'Jadwal Mengajar'],
            // Kehadiran sengaja tidak ada di sini, sama seperti FE (DosenSidebar.tsx) — diakses
            // lewat tab "Kehadiran" di halaman detail Jadwal Mengajar (App\Livewire\Dosen\Jadwal\
            // Detail), bukan menu tersendiri. Rute dosen.kehadiran (App\Livewire\Dosen\Kehadiran\
            // Index, daftar per kelas + rekap) tetap ada dan tetap bisa dibuka langsung lewat URL —
            // sama seperti app/dosen/kehadiran/page.tsx di FE yang juga tidak ditaut dari sidebar
            // mana pun tapi rute-nya tetap hidup.
            ['route' => 'dosen.nilai', 'label' => 'Nilai'],
            ['route' => 'dosen.rps', 'label' => 'RPS'],
        ]],
        ['route' => 'dosen.krs', 'label' => 'Persetujuan KRS', 'icon' => 'book-open'],
        ['route' => 'dosen.perwalian', 'label' => 'Perwalian', 'icon' => 'users'],
        ['label' => 'Tugas Akhir', 'icon' => 'book-open', 'children' => [
            ['route' => 'dosen.tugas-akhir', 'label' => 'Tugas Akhir'],
            ['route' => 'dosen.ujian-sidang', 'label' => 'Ujian Sidang'],
        ]],
        ['route' => 'dosen.arsip', 'label' => 'Arsip', 'icon' => 'archive'],
        ['route' => 'dosen.profil', 'label' => 'Akun', 'icon' => 'user'],
    ];

    $isChildActive = fn (array $child) => request()->routeIs($child['route'].'*');
    $isItemActive = function (array $item) use ($isChildActive) {
        if (isset($item['children'])) {
            return collect($item['children'])->contains($isChildActive);
        }

        return request()->routeIs($item['route'].'*');
    };
@endphp

@foreach ($dosenNavItems as $item)
    @if (isset($item['children']))
        @php $itemActive = $isItemActive($item); @endphp
        {{-- <details> native (bukan Alpine) — sidebar dirender di setiap halaman dosen, termasuk
             yang belum tentu memuat komponen Livewire, jadi tidak boleh bergantung padanya. --}}
        <details class="group" @if ($itemActive) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ $itemActive ? 'bg-sky-50 text-sky-700' : 'text-neutral-700 hover:bg-neutral-100' }}">
                <span class="flex items-center gap-3">
                    <i data-lucide="{{ $item['icon'] }}" class="h-5 w-5 {{ $itemActive ? 'text-sky-600' : 'text-neutral-500' }}" aria-hidden="true"></i>
                    {{ $item['label'] }}
                </span>
                <i data-lucide="chevron-right" class="h-4 w-4 text-neutral-400 transition group-open:rotate-90" aria-hidden="true"></i>
            </summary>
            <div class="mt-1 ml-4 space-y-1 border-l-2 border-neutral-200 pl-4">
                @foreach ($item['children'] as $child)
                    <a
                        href="{{ route($child['route']) }}"
                        class="block rounded-lg px-3 py-2 text-sm transition {{ $isChildActive($child) ? 'bg-sky-50 font-medium text-sky-700' : 'text-neutral-600 hover:bg-neutral-100' }}"
                    >
                        {{ $child['label'] }}
                    </a>
                @endforeach
            </div>
        </details>
    @else
        @php $itemActive = $isItemActive($item); @endphp
        <a
            href="{{ route($item['route']) }}"
            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ $itemActive ? 'bg-sky-50 text-sky-700' : 'text-neutral-700 hover:bg-neutral-100' }}"
        >
            <i data-lucide="{{ $item['icon'] }}" class="h-5 w-5 {{ $itemActive ? 'text-sky-600' : 'text-neutral-500' }}" aria-hidden="true"></i>
            {{ $item['label'] }}
        </a>
    @endif
@endforeach
