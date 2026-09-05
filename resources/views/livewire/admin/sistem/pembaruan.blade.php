@section('title', 'Cek Pembaruan — ' . config('app.name'))
@section('header_title', 'Cek Pembaruan')
@section('header_subtitle', 'Versi terpasang dan kesiapan server untuk update')
@section('header_icon', 'refresh-cw')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Pengaturan'],
        ['label' => 'Sistem'],
        ['label' => 'Cek Pembaruan'],
    ]])
@endsection

@php
    $check = $this->check();
    $release = $check['release'];
    $available = $this->updateAvailable();
    $inspector = $this->inspector();
    $binaries = $inspector->binaries();
    $writable = $inspector->writablePaths();
@endphp

<div class="space-y-6">
    @if (session('status'))
        <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    {{-- Ringkasan versi --}}
    <div class="rounded-2xl bg-white p-6 shadow-border">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-neutral-500">Versi terpasang</p>
                <p class="mt-1 font-mono text-2xl font-semibold text-neutral-900">{{ $this->installedVersion() }}</p>
            </div>

            <div class="text-right">
                <p class="text-sm text-neutral-500">Versi terbaru</p>
                <p class="mt-1 font-mono text-2xl font-semibold text-neutral-900">
                    {{ $release?->version ?? '—' }}
                </p>
                @if ($release?->source)
                    <p class="mt-0.5 text-xs text-neutral-400">
                        sumber: {{ $release->source === 'portal' ? 'Sikampus Server' : 'GitHub Releases' }}
                    </p>
                @endif
            </div>
        </div>

        <div class="mt-5 border-t border-neutral-100 pt-5">
            @if ($check['error'])
                <div class="flex gap-3 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <i data-lucide="alert-triangle" class="h-5 w-5 shrink-0 text-amber-600" aria-hidden="true"></i>
                    <span>{{ $check['error'] }}</span>
                </div>
            @elseif ($available === true)
                <div class="flex gap-3 rounded-lg border border-sky-100 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                    <i data-lucide="arrow-up-circle" class="h-5 w-5 shrink-0 text-sky-600" aria-hidden="true"></i>
                    <span>Versi baru tersedia. Ikuti langkah di bawah untuk memperbarui.</span>
                </div>
            @elseif ($available === false)
                <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
                    <span>Instalasi Anda sudah memakai versi terbaru.</span>
                </div>
            @else
                {{-- available === null: versi tidak bisa dibandingkan (mis. checkout "dev"). --}}
                <div class="flex gap-3 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-700">
                    <i data-lucide="help-circle" class="h-5 w-5 shrink-0 text-neutral-500" aria-hidden="true"></i>
                    <span>
                        Versi terpasang tidak bisa dibandingkan dengan versi rilis
                        @if ($this->installedVersion() === 'dev')
                            — berkas <code class="rounded bg-neutral-200 px-1 py-0.5 text-xs">VERSION</code> tidak ada,
                            yang normal untuk checkout pengembang.
                        @endif
                    </span>
                </div>
            @endif
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button
                type="button"
                wire:click="refreshCheck"
                wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800 disabled:opacity-60"
            >
                <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
                <span wire:loading.remove wire:target="refreshCheck">Cek ulang sekarang</span>
                <span wire:loading wire:target="refreshCheck">Memeriksa…</span>
            </button>

            @if ($release?->htmlUrl)
                <a
                    href="{{ $release->htmlUrl }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
                >
                    <i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i>
                    Lihat rilis
                </a>
            @endif
        </div>
    </div>

    {{-- Changelog --}}
    @if ($release && filled($release->changelog))
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="text-sm font-semibold text-neutral-900">Catatan rilis {{ $release->version }}</h2>
            @if ($release->publishedAt)
                <p class="mt-0.5 text-xs text-neutral-400">Terbit {{ $release->publishedAt->translatedFormat('d F Y') }}</p>
            @endif
            {{-- Isi changelog berasal dari body GitHub Release, yaitu teks yang ditulis orang
                 lain di luar aplikasi ini. Ditampilkan sebagai TEKS BIASA, tidak pernah
                 dirender sebagai HTML/Markdown, supaya tidak ada jalan masuk untuk markup. --}}
            <pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-neutral-50 p-4 font-sans text-sm leading-relaxed text-neutral-700">{{ $release->changelog }}</pre>
        </div>
    @endif

    {{-- Preflight --}}
    <div class="rounded-2xl bg-white p-6 shadow-border">
        <h2 class="text-sm font-semibold text-neutral-900">Kesiapan server</h2>
        <p class="mt-1 text-sm text-neutral-500">
            Menentukan jalur update mana yang bisa dipakai instalasi ini.
        </p>

        <dl class="mt-4 grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
                <dt class="text-sm text-neutral-600">Tipe instalasi</dt>
                <dd class="text-sm font-medium text-neutral-900">{{ $inspector->typeLabel() }}</dd>
            </div>

            <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
                <dt class="text-sm text-neutral-600">Versi PHP</dt>
                <dd class="font-mono text-sm font-medium text-neutral-900">{{ $inspector->phpVersion() }}</dd>
            </div>

            <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
                <dt class="text-sm text-neutral-600">Menjalankan proses (proc_open)</dt>
                <dd>
                    @include('admin.partials.status-pill', ['ok' => $inspector->canRunProcesses(), 'yes' => 'Aktif', 'no' => 'Dimatikan'])
                </dd>
            </div>

            <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
                <dt class="text-sm text-neutral-600">Izin tulis direktori aplikasi</dt>
                <dd>
                    @include('admin.partials.status-pill', ['ok' => $inspector->isFullyWritable(), 'yes' => 'Bisa ditulis', 'no' => 'Terbatas'])
                </dd>
            </div>

            @foreach ($binaries as $name => $path)
                <div class="flex items-center justify-between gap-3 border-b border-neutral-100 pb-2">
                    <dt class="text-sm text-neutral-600">Binary <code class="text-xs">{{ $name }}</code></dt>
                    <dd>
                        @include('admin.partials.status-pill', ['ok' => filled($path), 'yes' => 'Tersedia', 'no' => 'Tidak ada'])
                    </dd>
                </div>
            @endforeach
        </dl>

        @unless ($inspector->isFullyWritable())
            <div class="mt-4 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <p class="font-medium">Direktori berikut tidak bisa ditulis oleh PHP:</p>
                <p class="mt-1 font-mono text-xs">
                    {{ implode(', ', array_keys(array_filter($writable, fn ($ok) => ! $ok))) }}
                </p>
                <p class="mt-2">
                    Update otomatis membutuhkan izin tulis ke direktori ini. Minta administrator server
                    menjadikan berkas aplikasi milik user yang menjalankan PHP.
                </p>
            </div>
        @endunless
    </div>

    {{-- Langkah update sesuai tipe instalasi --}}
    @if ($available === true)
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <h2 class="text-sm font-semibold text-neutral-900">Cara memperbarui ke {{ $release->version }}</h2>

            @if ($inspector->type() === \App\Services\Update\InstallationInspector::TYPE_MANAGED)
                <p class="mt-3 text-sm text-neutral-600">
                    Instalasi ini dikelola Sikampus Cloud. Pembaruan dijalankan dari portal Sikampus,
                    bukan dari halaman ini — menjalankannya sendiri dari sini akan bertabrakan dengan
                    proses deploy di sisi portal.
                </p>
            @elseif ($inspector->canUseGitPath())
                <p class="mt-3 text-sm text-neutral-600">
                    Instalasi ini berasal dari klon Git dan server memiliki git, composer, serta npm.
                    Jalankan perintah berikut di direktori aplikasi:
                </p>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-neutral-900 p-4 text-xs leading-relaxed text-neutral-100">git pull origin {{ $release->version }}
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:clear && php artisan route:clear && php artisan view:clear</pre>
                <p class="mt-3 text-xs text-neutral-500">
                    Jangan menjalankan <code>php artisan optimize</code> atau <code>route:cache</code> —
                    keduanya membuat route milik plugin berhenti terdaftar.
                </p>
            @else
                <p class="mt-3 text-sm text-neutral-600">
                    Unduh source siap pakai, lalu ganti berkas aplikasi. <strong>Jangan menimpa</strong>
                    berkas <code>.env</code>, direktori <code>storage/</code>, dan direktori
                    <code>plugins/</code> — ketiganya milik instalasi Anda, bukan bagian dari rilis.
                    Setelah berkas diganti, jalankan migrasi dari halaman Migrasi.
                </p>

                @if ($release->downloadUrl)
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a
                            href="{{ $release->downloadUrl }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800"
                        >
                            <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
                            Unduh {{ $release->version }}
                        </a>
                        @if ($release->checksumUrl)
                            <a
                                href="{{ $release->checksumUrl }}"
                                class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
                            >
                                <i data-lucide="shield-check" class="h-4 w-4" aria-hidden="true"></i>
                                Checksum
                            </a>
                        @endif
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
