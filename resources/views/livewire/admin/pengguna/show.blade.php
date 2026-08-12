@php
    $pengguna = $this->pengguna;
    $roleLabels = ['admin' => 'Admin / Operator', 'dosen' => 'Dosen', 'mahasiswa' => 'Mahasiswa'];
    $scopeTypeLabels = ['fakultas' => 'Fakultas', 'prodi' => 'Program Studi', 'kampus' => 'Kampus'];
    $isSuperadmin = $this->isSuperadminActor();
    $fakultasNameMap = $this->fakultasOptions->pluck('nama', 'id');
    $prodiNameMap = $this->prodiOptions->pluck('nama', 'id');
@endphp

@section('title', 'Detail Pengguna — ' . config('app.name'))
@section('header_title', 'Detail Pengguna')
@section('header_subtitle', $pengguna->name)
@section('header_icon', 'user')

@section('nav')
    @include('admin.partials.nav')
@endsection

@section('breadcrumb')
    @include('admin.partials.breadcrumb', ['items' => [
        ['label' => 'Pengaturan'],
        ['label' => 'Pengguna', 'route' => route('admin.pengguna.index')],
        ['label' => $pengguna->name],
    ]])
@endsection

@section('page_actions')
    <a
        href="{{ route('admin.pengguna.index') }}"
        class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
    >
        <i data-lucide="arrow-left" class="h-4 w-4" aria-hidden="true"></i>
        Kembali
    </a>
    {{-- "Login as" (App\Http\Controllers\Web\ImpersonateController) — hanya Superadmin, hanya
         untuk akun dosen/mahasiswa, dan bukan diri sendiri. Form POST biasa (bukan aksi Livewire)
         karena aksi ini mengganti identitas sesi — pola yang sama dengan logout/login. --}}
    @if ($isSuperadmin && in_array($pengguna->role, ['dosen', 'mahasiswa'], true) && $pengguna->id !== auth()->id())
        <form method="post" action="{{ route('admin.pengguna.impersonate.start', $pengguna->id) }}">
            @csrf
            <button
                type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-neutral-700 shadow-border transition hover:bg-neutral-50"
            >
                <i data-lucide="venetian-mask" class="h-4 w-4" aria-hidden="true"></i>
                Login sebagai {{ $roleLabels[$pengguna->role] ?? $pengguna->role }}
            </button>
        </form>
    @endif
    @if (\App\Support\PanelAccess::can(auth()->user(), 'pengguna', 'manage'))
        <a
            href="{{ route('admin.pengguna.edit', $pengguna->id) }}"
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
    @if (session('error'))
        <div class="mb-4 flex gap-3 rounded-lg border border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <i data-lucide="alert-circle" class="h-5 w-5 shrink-0 text-rose-600" aria-hidden="true"></i>
            <span>{{ session('error') }}</span>
        </div>
    @endif

    <div class="mb-6 rounded-2xl bg-white p-6 shadow-border">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs font-semibold uppercase text-neutral-500">Email</p>
                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $pengguna->email }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase text-neutral-500">Username</p>
                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $pengguna->username ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase text-neutral-500">Tipe Akun</p>
                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $roleLabels[$pengguna->role] ?? $pengguna->role }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase text-neutral-500">Status</p>
                <p class="mt-1">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $pengguna->status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-neutral-600' }}">
                        {{ $pengguna->status === 'active' ? 'Aktif' : 'Tidak Aktif' }}
                    </span>
                </p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase text-neutral-500">Telepon</p>
                <p class="mt-1 text-sm font-medium text-neutral-900">{{ $pengguna->phone ?? '—' }}</p>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <p class="text-xs font-semibold uppercase text-neutral-500">Alamat</p>
                <p class="mt-1 text-sm font-medium text-neutral-900">
                    {{ collect([$pengguna->address, $pengguna->city, $pengguna->state, $pengguna->zip, $pengguna->country])->filter()->implode(', ') ?: '—' }}
                </p>
            </div>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div class="mb-6 border-b border-neutral-200">
        <nav class="-mb-px flex flex-wrap gap-6">
            @foreach ([['key' => 'role', 'label' => 'Role dan Scope'], ['key' => 'permission', 'label' => 'Permission']] as $tab)
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

    {{-- Tab: Role dan Scope --}}
    @if ($activeTab === 'role')
        <div class="space-y-6">
            @if (! $isSuperadmin)
                <div class="flex gap-3 rounded-lg border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <i data-lucide="info" class="h-5 w-5 shrink-0 text-amber-600" aria-hidden="true"></i>
                    <span>Hanya Superadmin yang dapat mengubah role dan scope pengguna.</span>
                </div>
            @endif

            @if ($isSuperadmin && ! $showRoleForm)
                <div class="flex justify-end">
                    <button type="button" wire:click="openRoleForm" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                        <i data-lucide="plus" class="h-4 w-4" aria-hidden="true"></i>
                        Tambah Role dan Scope
                    </button>
                </div>
            @endif

            @if ($isSuperadmin && $showRoleForm)
                <div class="rounded-2xl bg-white p-6 shadow-border">
                    <div class="mb-4 flex items-center justify-between border-b border-neutral-200 pb-4">
                        <h3 class="text-base font-semibold text-neutral-900">Tambah Role dan Scope</h3>
                        <button type="button" wire:click="cancelRoleForm" class="text-neutral-400 transition hover:text-neutral-600">
                            <i data-lucide="x" class="h-5 w-5" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="space-y-5">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">Role *</label>
                            <div class="max-h-40 space-y-1 overflow-y-auto rounded-lg p-3 shadow-border">
                                @forelse ($this->roleOptions as $role)
                                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                                        <input type="checkbox" wire:model="selectedRoleIds" value="{{ $role->id }}" class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                                        {{ $role->name }} <span class="text-xs text-neutral-400">({{ $role->code }})</span>
                                    </label>
                                @empty
                                    <p class="text-sm text-neutral-500">Belum ada role. Buat role terlebih dahulu.</p>
                                @endforelse
                            </div>
                            @error('selectedRoleIds') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <p class="text-xs text-neutral-500">
                            Pilih salah satu tingkat akses: <strong>Fakultas</strong> (akses semua prodi di fakultas tsb) atau <strong>Program Studi</strong> (akses prodi tertentu saja). Kosongkan keduanya untuk akses tanpa batas (level universitas).
                        </p>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">
                                Fakultas
                                @if ($selectedProdiIds !== [])
                                    <span class="text-xs font-normal text-neutral-400">(nonaktif karena Program Studi sudah dipilih)</span>
                                @endif
                            </label>
                            <div class="max-h-40 space-y-1 overflow-y-auto rounded-lg p-3 shadow-border {{ $selectedProdiIds !== [] ? 'opacity-50' : '' }}">
                                @forelse ($this->fakultasOptions as $fakultas)
                                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                                        <input type="checkbox" wire:model.live="selectedFakultasIds" value="{{ $fakultas->id }}" {{ $selectedProdiIds !== [] ? 'disabled' : '' }} class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                                        {{ $fakultas->nama }}
                                    </label>
                                @empty
                                    <p class="text-sm text-neutral-500">Belum ada data fakultas.</p>
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-neutral-700">
                                Program Studi
                                @if ($selectedFakultasIds !== [])
                                    <span class="text-xs font-normal text-neutral-400">(nonaktif karena Fakultas sudah dipilih)</span>
                                @endif
                            </label>
                            <div class="max-h-56 space-y-1 overflow-y-auto rounded-lg p-3 shadow-border {{ $selectedFakultasIds !== [] ? 'opacity-50' : '' }}">
                                @forelse ($this->prodiOptions as $prodi)
                                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                                        <input type="checkbox" wire:model.live="selectedProdiIds" value="{{ $prodi->id }}" {{ $selectedFakultasIds !== [] ? 'disabled' : '' }} class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                                        {{ $prodi->nama }}
                                        <span class="text-xs text-neutral-400">({{ $fakultasNameMap[$prodi->id_fakultas] ?? '—' }})</span>
                                    </label>
                                @empty
                                    <p class="text-sm text-neutral-500">Belum ada data program studi.</p>
                                @endforelse
                            </div>
                            @error('selectedProdiIds') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-6 flex items-center gap-3 border-t border-neutral-200 pt-4">
                        <button type="button" wire:click="saveRoleScope" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                            <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                            Simpan
                        </button>
                        <button type="button" wire:click="cancelRoleForm" class="rounded-lg px-4 py-2.5 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                            Batal
                        </button>
                    </div>
                </div>
            @endif

            @php $rolesData = $this->rolesData; @endphp

            @if ($rolesData === [])
                <div class="rounded-2xl bg-white py-12 text-center text-neutral-500 shadow-border">
                    Pengguna ini belum memiliki role dan scope.
                </div>
            @else
                <div class="space-y-6">
                    @foreach ($rolesData as $roleCode => $row)
                        @php $scopes = is_array($row['scopes']) ? $row['scopes'] : []; @endphp
                        <div class="overflow-hidden rounded-2xl bg-white shadow-border">
                            <div class="flex items-center justify-between gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-3">
                                <h3 class="text-sm font-semibold text-neutral-900">{{ $roleLabels[$roleCode] ?? $roleCode }}</h3>
                                @if ($isSuperadmin)
                                    <button
                                        type="button"
                                        wire:click="deleteRole('{{ $roleCode }}')"
                                        wire:confirm="Hapus role {{ $roleLabels[$roleCode] ?? $roleCode }} dari pengguna ini?"
                                        class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50"
                                    >
                                        <i data-lucide="trash-2" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                        Hapus Role
                                    </button>
                                @endif
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                        <tr>
                                            <th class="px-4 py-3">Tipe Scope</th>
                                            <th class="px-4 py-3">Nilai</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-100">
                                        @forelse ($scopes as $scopeType => $scopeValue)
                                            <tr>
                                                <td class="px-4 py-3 font-medium text-neutral-900">{{ $scopeTypeLabels[$scopeType] ?? $scopeType }}</td>
                                                <td class="px-4 py-3 text-neutral-600">
                                                    @if ($scopeType === 'kampus' && $scopeValue === true)
                                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Semua Kampus</span>
                                                    @elseif (is_array($scopeValue) && $scopeValue !== [])
                                                        <div class="flex flex-wrap gap-2">
                                                            @foreach ($scopeValue as $scopeId)
                                                                @php
                                                                    $scopeName = $scopeType === 'fakultas' ? ($fakultasNameMap[$scopeId] ?? "ID: $scopeId") : ($scopeType === 'prodi' ? ($prodiNameMap[$scopeId] ?? "ID: $scopeId") : "ID: $scopeId");
                                                                @endphp
                                                                @if ($isSuperadmin && in_array($scopeType, ['fakultas', 'prodi']))
                                                                    <button
                                                                        type="button"
                                                                        wire:click="deleteScope('{{ $scopeType }}', {{ $scopeId }})"
                                                                        wire:confirm="Hapus scope {{ $scopeName }} dari pengguna ini?"
                                                                        class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700 transition hover:bg-sky-100"
                                                                    >
                                                                        {{ $scopeName }}
                                                                        <span>×</span>
                                                                    </button>
                                                                @else
                                                                    <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700">{{ $scopeName }}</span>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <span class="text-neutral-400">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="2" class="px-4 py-6 text-center text-sm text-neutral-500">Tidak ada scope yang ditetapkan (akses level universitas).</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Tab: Permission --}}
    @if ($activeTab === 'permission')
        <div class="rounded-2xl bg-white p-6 shadow-border">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-neutral-900">Permission Langsung</h3>
                    <p class="mt-1 text-xs text-neutral-500">Permission tambahan di luar yang didapat dari role. Hanya menampilkan permission direct, bukan bawaan role.</p>
                </div>
                @if ($isSuperadmin)
                    <button type="button" wire:click="savePermissions" class="inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-neutral-800">
                        <i data-lucide="save" class="h-4 w-4" aria-hidden="true"></i>
                        Simpan Permission
                    </button>
                @endif
            </div>

            @if ($permissionForm === [])
                <div class="py-8 text-center text-sm text-neutral-500">Tidak ada permission yang tersedia.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th class="px-4 py-3">Resource</th>
                                <th class="px-4 py-3 text-center">Read</th>
                                <th class="px-4 py-3 text-center">Write</th>
                                <th class="px-4 py-3 text-center">Delete</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            @foreach ($permissionForm as $index => $row)
                                <tr wire:key="perm-{{ $index }}">
                                    <td class="px-4 py-3 font-medium text-neutral-900">
                                        {{ $row['resource'] }}
                                        @if ($row['mode'] === 'single')
                                            <span class="ml-1 text-xs font-normal text-neutral-400">(akses penuh, belum granular)</span>
                                        @elseif ($row['mode'] === 'view_plus_manage')
                                            <span class="ml-1 text-xs font-normal text-neutral-400">(Read = lihat saja, Write = akses penuh)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <input type="checkbox" wire:model="permissionForm.{{ $index }}.read" {{ $isSuperadmin ? '' : 'disabled' }} class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if ($row['hasWrite'])
                                            <input type="checkbox" wire:model="permissionForm.{{ $index }}.write" {{ $isSuperadmin ? '' : 'disabled' }} class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                                        @else
                                            <span class="text-neutral-300">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if ($row['hasDelete'])
                                            <input type="checkbox" wire:model="permissionForm.{{ $index }}.delete" {{ $isSuperadmin ? '' : 'disabled' }} class="size-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-900/10" />
                                        @else
                                            <span class="text-neutral-300">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    @if (\App\Support\PanelAccess::can(auth()->user(), 'pengguna', 'manage'))
        <div class="mt-6">
            <button
                type="button"
                wire:click="confirmDeleteUser"
                class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-100"
            >
                <i data-lucide="trash-2" class="h-4 w-4" aria-hidden="true"></i>
                Hapus Pengguna
            </button>
        </div>
    @endif

    {{-- Modal: Konfirmasi Hapus Pengguna --}}
    @if ($confirmingDelete)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-border-lg">
                <h3 class="text-base font-semibold text-neutral-900">Hapus pengguna ini?</h3>
                <p class="mt-2 text-sm text-neutral-600">Tindakan ini tidak dapat dibatalkan.</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancelDeleteUser" class="rounded-lg px-4 py-2 text-sm font-medium text-neutral-700 transition hover:bg-neutral-50 shadow-border">
                        Batal
                    </button>
                    <button type="button" wire:click="deleteUser" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-rose-700">
                        Hapus
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
