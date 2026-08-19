@section('title', 'Pengaturan SMTP — ' . config('app.name'))
@section('header_title', 'Pengaturan SMTP')
@section('header_subtitle', 'Konfigurasi server pengirim email, disimpan di database')
@section('header_icon', 'mail')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Pengaturan'],
        ['label' => 'Sistem'],
        ['label' => 'SMTP'],
    ]])
@endsection

<div>
    @if (session('status'))
        <div class="mb-4 flex gap-3 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <i data-lucide="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" aria-hidden="true"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 flex gap-3 whitespace-pre-line rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
            <i data-lucide="circle-alert" class="h-5 w-5 shrink-0 text-red-600" aria-hidden="true"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Host SMTP *</label>
                    <input type="text" wire:model="host" placeholder="mail.contoh.com" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('host') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('host') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Port *</label>
                    <input type="text" inputmode="numeric" wire:model="port" placeholder="587" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('port') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('port') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Enkripsi</label>
                    <select wire:model="encryption" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 shadow-border">
                        <option value="">Otomatis (STARTTLS jika didukung server)</option>
                        <option value="smtps">SSL/TLS langsung (umumnya port 465)</option>
                    </select>
                    @error('encryption') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Username SMTP *</label>
                    <input type="text" wire:model="username" placeholder="pengirim@contoh.com" autocomplete="off" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('username') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('username') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Password SMTP</label>
                    <input type="password" wire:model="password" placeholder="{{ $hasStoredPassword ? '••••••••  (biarkan kosong jika tidak diubah)' : 'Password / app password' }}" autocomplete="new-password" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('password') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('password') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Email Pengirim *</label>
                    <input type="email" wire:model="fromAddress" placeholder="noreply@contoh.com" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('fromAddress') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('fromAddress') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-neutral-700">Nama Pengirim *</label>
                    <input type="text" wire:model="fromName" placeholder="Nama Perguruan Tinggi" class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('fromName') ring-2 ring-red-500 @enderror shadow-border" />
                    @error('fromName') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                Simpan
            </button>
        </div>
    </form>

    <form wire:submit="sendTestEmail" class="mt-6 rounded-2xl bg-white p-6 shadow-border">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-neutral-900">Uji Koneksi SMTP</h2>
            <p class="mt-1 text-sm text-neutral-500">
                Kirim email tes memakai pengaturan di atas — termasuk perubahan yang belum disimpan — untuk memastikan koneksi SMTP berhasil.
            </p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <input
                    type="email"
                    wire:model="testEmail"
                    placeholder="tujuan@contoh.com"
                    class="w-full rounded-lg px-3 py-2.5 text-sm outline-none focus:border-neutral-900 focus:ring-2 focus:ring-neutral-900/10 @error('testEmail') ring-2 ring-red-500 @enderror shadow-border"
                />
                @error('testEmail') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="sendTestEmail"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-neutral-200 bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 shadow-sm transition hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <i data-lucide="send" class="h-4 w-4" aria-hidden="true"></i>
                <span wire:loading.remove wire:target="sendTestEmail">Kirim Email Tes</span>
                <span wire:loading wire:target="sendTestEmail">Mengirim...</span>
            </button>
        </div>
    </form>
</div>
