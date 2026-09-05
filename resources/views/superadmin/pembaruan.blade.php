@extends('layouts.web')

@section('title', 'Pembaruan — ' . config('app.name'))

@section('header_title', 'Pembaruan Aplikasi')
@section('header_subtitle', 'Superadmin')
@section('header_icon', 'refresh-cw')

@section('header_actions')
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50">
            <i data-lucide="log-out" class="h-4 w-4" aria-hidden="true"></i>
            Keluar
        </button>
    </form>
@endsection

@section('content')
    @php
        $maintenance = file_exists(storage_path('framework/down'));
        $blockers = array_keys(array_filter($inspector->writablePaths(), fn ($ok) => ! $ok));
    @endphp

    <div class="space-y-6">
        @if (session('status'))
            <div class="flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
                <i data-lucide="circle-check" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
                <span>{{ session('status') }}</span>
            </div>
        @endif

        @if (session('error'))
            <div class="flex gap-3 rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
                <i data-lucide="circle-alert" class="h-5 w-5 shrink-0 text-red-600" aria-hidden="true"></i>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if ($maintenance)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-6">
                <div class="flex gap-3">
                    <i data-lucide="triangle-alert" class="h-5 w-5 shrink-0 text-amber-600" aria-hidden="true"></i>
                    <div class="text-sm text-amber-900">
                        <p class="font-semibold">Aplikasi sedang dalam mode pemeliharaan.</p>
                        <p class="mt-1">
                            Pengunjung lain tidak bisa mengakses aplikasi. Ini normal selama pembaruan berjalan.
                            Kalau pembaruan sudah selesai atau gagal dan mode ini masih menyala, matikan di sini.
                        </p>
                        <form method="post" action="{{ route('superadmin.pembaruan.angkat') }}" class="mt-3">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-amber-700">
                                <i data-lucide="power" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                Matikan mode pemeliharaan
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        {{-- Pembaruan yang sedang berjalan --}}
        @if ($run)
            <div class="rounded-2xl bg-white p-8 shadow-border">
                <h2 class="text-xl font-semibold tracking-tight text-neutral-900">
                    Memperbarui {{ $run->version_from }} → {{ $run->version_to }}
                </h2>
                <p class="mt-1 text-sm text-neutral-600">
                    Jalur {{ $run->path === 'git' ? 'Git (pull + composer + npm)' : 'berkas rilis' }}.
                    Jalankan langkah satu per satu; jangan menutup halaman selama satu langkah berjalan.
                </p>

                <ol class="mt-6 space-y-2">
                    @foreach ($run->steps() as $index => $stepName)
                        @php
                            $current = array_search($run->step, $run->steps(), true);
                            $state = $index < $current ? 'done' : ($index === $current ? 'current' : 'todo');
                        @endphp
                        <li class="flex items-center gap-3 rounded-lg px-3 py-2 {{ $state === 'current' ? 'bg-sky-50' : '' }}">
                            @if ($state === 'done')
                                <i data-lucide="circle-check" class="h-4 w-4 text-emerald-600" aria-hidden="true"></i>
                            @elseif ($state === 'current')
                                <i data-lucide="circle-dot" class="h-4 w-4 text-sky-600" aria-hidden="true"></i>
                            @else
                                <i data-lucide="circle" class="h-4 w-4 text-neutral-300" aria-hidden="true"></i>
                            @endif
                            <span class="text-sm {{ $state === 'todo' ? 'text-neutral-400' : 'text-neutral-800' }}">
                                {{ ucfirst($stepName) }}
                            </span>
                        </li>
                    @endforeach
                </ol>

                <div class="mt-6 flex flex-wrap gap-3">
                    <form method="post" action="{{ route('superadmin.pembaruan.langkah') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-neutral-800">
                            <i data-lucide="play" class="h-4 w-4" aria-hidden="true"></i>
                            Jalankan langkah "{{ $run->step }}"
                        </button>
                    </form>

                    @unless ($run->hasStartedMutating())
                        <form method="post" action="{{ route('superadmin.pembaruan.batal') }}"
                              onsubmit="return confirm('Batalkan pembaruan dan hapus berkas kerja?');">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50">
                                Batalkan
                            </button>
                        </form>
                    @endunless
                </div>
            </div>
        @else
            {{-- Layar mulai --}}
            <div class="rounded-2xl bg-white p-8 shadow-border">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-neutral-500">Versi terpasang</p>
                        <p class="mt-1 font-mono text-2xl font-semibold text-neutral-900">{{ $installed }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-neutral-500">Versi terbaru</p>
                        <p class="mt-1 font-mono text-2xl font-semibold text-neutral-900">{{ $release?->version ?? '—' }}</p>
                    </div>
                </div>

                @if ($error)
                    <p class="mt-5 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $error }}</p>
                @elseif (! $release || $release->isNewerThan($installed) !== true)
                    <p class="mt-5 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        Tidak ada pembaruan yang perlu dijalankan.
                    </p>
                @else
                    @if ($blockers)
                        <div class="mt-5 rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-900">
                            <p class="font-medium">Pembaruan otomatis tidak bisa dijalankan.</p>
                            <p class="mt-1">PHP tidak punya izin tulis ke: <span class="font-mono text-xs">{{ implode(', ', $blockers) }}</span></p>
                        </div>
                    @else
                        @if ($changes['available'] && ($changes['modified'] || $changes['missing']))
                            <div class="mt-5 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                <p class="font-medium">Terdeteksi perubahan lokal pada berkas aplikasi.</p>
                                <p class="mt-1">
                                    Berkas berikut berbeda dari rilis resmi dan <strong>akan tertimpa</strong> oleh
                                    pembaruan. Salin dulu kalau perubahannya masih dibutuhkan.
                                </p>
                                <ul class="mt-2 max-h-40 overflow-auto font-mono text-xs">
                                    @foreach (array_merge($changes['modified'], $changes['missing']) as $file)
                                        <li>{{ $file }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @elseif (! $changes['available'])
                            <p class="mt-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-700">
                                {{ $changes['reason'] }}
                            </p>
                        @endif

                        <div class="mt-5 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <strong>Backup database Anda sekarang.</strong> Pembaruan menjalankan migrasi yang
                            mengubah struktur tabel dan tidak bisa dibatalkan otomatis. Berkas aplikasi versi lama
                            disimpan dan bisa dikembalikan; isi database tidak.
                        </div>

                        <form method="post" action="{{ route('superadmin.pembaruan.mulai') }}" class="mt-5">
                            @csrf
                            <label class="flex items-start gap-2.5 text-sm text-neutral-700">
                                <input type="checkbox" name="confirm" value="1" required class="mt-0.5 rounded border-neutral-300">
                                <span>Saya sudah membackup database dan siap menjalankan pembaruan.</span>
                            </label>
                            @error('confirm') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror

                            <button type="submit" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-neutral-800">
                                <i data-lucide="download" class="h-4 w-4" aria-hidden="true"></i>
                                Mulai perbarui ke {{ $release->version }}
                            </button>
                        </form>
                    @endif
                @endif
            </div>
        @endif

        {{-- Jejak langkah --}}
        @if ($lastRun && filled($lastRun->log))
            <div class="rounded-2xl bg-white p-8 shadow-border">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-neutral-900">Catatan pembaruan</h3>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium
                        {{ $lastRun->status === 'success' ? 'bg-emerald-50 text-emerald-700' : ($lastRun->status === 'running' ? 'bg-sky-50 text-sky-700' : 'bg-red-50 text-red-700') }}">
                        {{ $lastRun->status }}
                    </span>
                </div>
                @if ($lastRun->error_message)
                    <p class="mt-3 rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $lastRun->error_message }}</p>
                @endif
                <pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-neutral-900 p-4 font-mono text-xs leading-relaxed text-neutral-100">{{ $lastRun->log }}</pre>
            </div>
        @endif
    </div>
@endsection
