@section('title', 'Penandatangan Transkrip — ' . config('app.name'))
@section('header_title', 'Penandatangan Transkrip')
@section('header_subtitle', 'Jabatan dan nama pejabat yang tercetak di blok tanda tangan transkrip nilai')
@section('header_icon', 'pen-line')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Pengaturan'],
        ['label' => 'Akademik'],
        ['label' => 'Penandatangan Transkrip'],
    ]])
@endsection

<div>
    @if (session('status'))
        <div class="mb-4 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <div class="mb-4 flex gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600">
        <i data-lucide="info" class="h-5 w-5 shrink-0 text-neutral-400" aria-hidden="true"></i>
        <span>
            Nilai di sini dipakai untuk <strong>semua</strong> transkrip nilai yang dicetak dari
            Akademik → Nilai → detail mahasiswa → Cetak → Transkrip Nilai. Kolom yang dikosongkan
            tercetak sebagai &ldquo;-&rdquo;, jadi transkrip tetap bisa dicetak sebelum halaman ini diisi.
        </span>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jabatan (Indonesia)</label>
                    <input type="text" wire:model="jabatan" placeholder="Rektor Universitas Contoh" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('jabatan') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('jabatan') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Jabatan (Inggris)</label>
                    <input type="text" wire:model="jabatanEn" placeholder="Rector Universitas Contoh" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('jabatanEn') ring-2 ring-red-500 @enderror shadow-border" />
                    <p class="mt-1.5 text-xs text-neutral-500">Opsional — kalau kosong, blok tanda tangan hanya menampilkan versi Indonesianya.</p>
                    @error('jabatanEn') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nama Pejabat</label>
                    <input type="text" wire:model="namaPejabat" placeholder="Nama Lengkap, S.E., M.M." class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('namaPejabat') ring-2 ring-red-500 @enderror shadow-border" />
                    <p class="mt-1.5 text-xs text-neutral-500">Tulis lengkap dengan gelar, persis seperti yang ingin tercetak.</p>
                    @error('namaPejabat') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">NIP</label>
                    <input type="text" wire:model="nip" placeholder="19800101 200501 1 001" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('nip') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('nip') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Kota Penerbitan</label>
                    <input type="text" wire:model="kotaTerbit" placeholder="Kab. Bogor" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('kotaTerbit') ring-2 ring-red-500 @enderror shadow-border" />
                    <p class="mt-1.5 text-xs text-neutral-500">
                        Tercetak sebagai &ldquo;Diterbitkan di … / Issued in …&rdquo;. Kalau dikosongkan, dipakai
                        kota perguruan tinggi dari Pengaturan → Perguruan Tinggi.
                    </p>
                    @error('kotaTerbit') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Tanggal Terbit</label>
                    {{-- :live supaya pratinjau di bawah ikut berubah saat tanggalnya diganti. --}}
                    <input type="date" wire:model.live="tanggalTerbit" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('tanggalTerbit') ring-2 ring-red-500 @enderror shadow-border" />
                    <p class="mt-1.5 text-xs text-neutral-500">
                        Tanggal resmi yang tercetak di seluruh transkrip. Kalau dikosongkan, dipakai
                        tanggal saat transkrip dicetak.
                    </p>
                    @error('tanggalTerbit') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="text-sm font-semibold text-neutral-900">Pratinjau blok tanda tangan</h2>
            <div class="mt-3 rounded-xl bg-neutral-50 p-6 font-serif text-sm leading-relaxed text-neutral-800">
                @php
                    // Cermin App\Services\TranskripPdfGenerator::payload(): kosong/tidak valid = hari ini.
                    try {
                        $tglPratinjau = trim($tanggalTerbit) !== '' ? new DateTimeImmutable($tanggalTerbit) : now();
                    } catch (Exception) {
                        $tglPratinjau = now();
                    }
                    $bulanId = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $tglId = $tglPratinjau->format('j').' '.$bulanId[(int) $tglPratinjau->format('n')].' '.$tglPratinjau->format('Y');
                    $tglEn = $tglPratinjau->format('j F Y');
                @endphp
                <p>Diterbitkan di {{ trim($kotaTerbit) !== '' ? $kotaTerbit : '…' }}, {{ $tglId }}</p>
                <p class="italic text-neutral-500">Issued in {{ trim($kotaTerbit) !== '' ? $kotaTerbit : '…' }}, {{ $tglEn }}</p>
                <p class="mt-4">{{ trim($jabatan) !== '' ? $jabatan : '(jabatan belum diisi)' }}</p>
                @if (trim($jabatanEn) !== '')
                    <p class="italic text-neutral-500">{{ $jabatanEn }}</p>
                @endif
                <div class="h-14"></div>
                <p class="font-semibold">{{ trim($namaPejabat) !== '' ? $namaPejabat : '(nama pejabat belum diisi)' }}</p>
                <p>NIP {{ trim($nip) !== '' ? $nip : '-' }}</p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                Simpan
            </button>
        </div>
    </form>
</div>
