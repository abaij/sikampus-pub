# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

SIAK (Sistem Informasi Akademik) backend — a Laravel 12 API for a university academic information
system, plus an embedded PMB (Penerimaan Mahasiswa Baru / admissions) module. It is primarily a JSON
API consumed by a separate frontend (see `FRONTEND_URL` in `.env`), with a small Blade-based web panel
for a superadmin-only maintenance UI (env config, DB migration trigger, file upload test).

## Commands

```bash
# Install
composer install
npm install

# Run the app (server + queue worker + log tail + vite, concurrently)
composer dev

# Tests (Pest, run via artisan)
composer test
php artisan test
php artisan test --filter=NamaTest
vendor/bin/pest tests/Feature/KrsPengajuanApprovalTest.php

# Lint / format (Laravel Pint)
vendor/bin/pint          # fix
vendor/bin/pint --test   # check only, no changes

# Frontend assets (Tailwind v4 + Vite, only used for the minimal Blade panel)
npm run dev
npm run build
```

# Bangun artefak rilis siap pakai (zip berisi vendor/ + public/build/ yang sudah jadi)
./scripts/build-release.sh          # dari HEAD
./scripts/build-release.sh v1.1.0   # dari sebuah tag
```

Tests run against a real MySQL database named `siak_testing` (see `phpunit.xml`), not sqlite —
create that database locally before running the suite. Every Feature test uses
`RefreshDatabase` (configured globally in `tests/Pest.php`).

### Versi & rilis

Versi aplikasi berasal dari berkas **`VERSION`** di root project — satu sumber kebenaran yang
melayani kedua cara instalasi kampus: klon Git mendapatkannya lewat `git pull` (VERSION ikut
di-commit saat tagging), unduhan siap pakai lewat isi zip. Dibaca sekali di
[config/sikampus.php](config/sikampus.php) supaya `config:cache` bisa membekukannya; nilainya
jatuh ke `"dev"` kalau berkasnya tidak ada, dan itu keadaan normal untuk checkout pengembang —
jangan bikin fitur apa pun mati karenanya.

Alur rilis: tulis `VERSION` → commit → tag → `./scripts/build-release.sh <tag>` → unggah isi
`dist/` ke GitHub Releases. Skrip itu membangun dari `git archive`, **bukan** dari direktori
kerja, sehingga `.env` dan `plugins/` lokal mustahil ikut terbawa. Tiga artefak dihasilkan:
zip, `.sha256`, dan **manifest sha256 per berkas**.

Manifest itu yang nanti membuat update satu klik mungkin: mendeteksi berkas yang dimodifikasi
lokal oleh kampus, dan berkas yang dihapus upstream (yang tidak akan hilang kalau update cuma
menimpa). `vendor/` dan `public/build/` sengaja dikecualikan dari manifest — keduanya selalu
diganti utuh saat update, jadi mendeteksi perubahan di dalamnya tidak mengubah keputusan apa pun.

Dua jebakan saat menambah berkas ke daftar `PRUNE` di skrip build: `.gitignore` **tidak** melepas
berkas yang sudah terlacak lebih dulu (itu sebabnya `.claude/` harus disebut eksplisit), dan
`storage/**/.gitignore` justru **wajib** ikut karena itulah kerangka direktori yang dibutuhkan
instalasi baru.

Versi terpasang juga dikirim ke Sikampus Server oleh
[InstallationReporter](app/Services/InstallationReporter.php) setiap license key disimpan.

### Cek pembaruan

Halaman **Pengaturan > Sistem > Cek Pembaruan**
([Pembaruan](app/Livewire/Admin/Sistem/Pembaruan.php)) menampilkan versi terpasang vs versi
rilis, changelog, dan hasil preflight. Sengaja **read-only** untuk sekarang: belum ada aksi yang
mengubah berkas. Tombol update sungguhan menyusul setelah deteksi di sini terbukti melaporkan
keadaan server dengan benar di lapangan.

[ReleaseChecker](app/Services/Update/ReleaseChecker.php) mencoba Sikampus Server dulu
(`SIKAMPUS_SERVER_URL`), lalu jatuh ke GitHub Releases. Jalur portal ada supaya instalasi
sekalian melaporkan versinya; artefaknya sendiri tetap dari GitHub. Instalasi self-hosted yang
tidak pernah mengisi license key tetap dapat pemberitahuan lewat jalur GitHub — pengecekan
pembaruan tidak pernah dibatasi lisensi.

Tiga hal yang mudah dirusak tanpa sengaja saat menyentuh area ini:

- **Kegagalan jaringan ikut di-cache**, bukan hanya keberhasilan. Tanpa itu, sumber rilis yang
  mati membuat setiap pembukaan halaman menunggu timeout penuh.
- Perbandingan versi punya **tiga** hasil — ada update / sudah terbaru / **tidak diketahui**
  (`Release::isNewerThan()` mengembalikan `null`). Checkout `dev` masuk yang ketiga; menjawab
  "sudah terbaru" di situ sama menyesatkannya dengan menjawab "ada update".
- Changelog berasal dari body GitHub Release, yaitu teks yang ditulis di luar aplikasi ini.
  Dirender sebagai **teks biasa, tidak pernah sebagai HTML/Markdown** — ada test yang mengunci
  perilaku itu.

[InstallationInspector](app/Services/Update/InstallationInspector.php) melakukan preflight tanpa
menjalankan proses apa pun: binary dicari lewat `ExecutableFinder` (menelusuri PATH), bukan
dengan mengeksekusinya — justru server yang `proc_open`-nya dimatikan yang paling butuh
jawabannya.

**Pengaturan > Migrasi** (`/migrasi`, area superadmin web) menjalankan `migrate --force` dari
panel, untuk instalasi yang diperbarui dengan mengganti berkas manual dan tidak punya akses
shell. Sengaja hanya migrate maju — tidak pernah rollback/fresh/refresh.

### Pest test helpers (`tests/Pest.php`)

- `adminUser(string $legacyRole = 'admin')` — creates a `User` with the legacy `role` column set,
  and syncs the corresponding Spatie role (`admin_akademik` → `Akademik`, `admin_keuangan` →
  `Keuangan`, anything else → `Superadmin`). Use this instead of building admin users by hand.
- `scopeAdminToProdi(User $user, int $prodiId)` — inserts a `user_role_scopes` row
  (`scope_type = prodi`) for the user's Spatie role, for testing prodi-scoped access.

## Architecture

### Authn/authz model — two sources of truth, don't confuse them

- **Spatie Permission** (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`,
  `role_has_permissions`) is the single source of truth for *panel/module access*. Route
  middleware aliases (`role.admin`, `role.superadmin`, `role.admin.keuangan`, `role.admin.prodi`)
  all check `$user->hasAnyRole(...)` or `App\Models\Role`/`User::isSuperadmin()`, matching both the
  Spatie `name` (e.g. `Superadmin`, `Akademik`, `Keuangan`) and a lowercase `roles.code` column for
  legacy rows. See `SPATIE_PERMISSION_SETUP.md` for the full rationale and usage examples.
- The legacy `users.role` string column (`admin`, `admin_akademik`, `admin_keuangan`, `dosen`,
  `mahasiswa`, ...) is **only** used to distinguish account *type* (see `EnsureUserIsDosen` /
  `EnsureUserIsMahasiswa`, which check `$user->role` directly) — it is not consulted for admin-panel
  authorization decisions.
- **Scoping** (which fakultas/prodi data an admin may see) is separate from roles/permissions again:
  `user_role_scopes` (per user+Spatie-role) holds `scope_type` (`fakultas` or `prodi`) +
  `id_scope`. `App\Models\User` exposes this via `getAllowedProdiIds()`, `getFakultasScopeIds()`,
  `hasScopeRestriction()`, etc. Dosen who are Kepala Prodi/Sekretaris Prodi (via
  `prodi.id_kaprodi`/`id_sekprodi` pointing at their `dosen.id`) get prodi scope implicitly through
  `getKaprodiProdiIds()`/`getSekprodiProdiIds()`, independent of `user_role_scopes` rows.
- Controllers, not middleware, are responsible for filtering query results by scope — middleware
  only gates *whether* a route is reachable at all. When adding a new admin endpoint that returns
  fakultas/prodi-bound data, filter it using `$user->getAllowedProdiIds()` (or equivalent), mirroring
  existing controllers (see tests in `tests/Feature/ScopeEnforcementTest.php` and
  `tests/Feature/ProdiScopeTest.php` for the expected behavior).

### Route middleware aliases (registered in `bootstrap/app.php`)

| Alias | Middleware | Meaning |
|---|---|---|
| `role.admin` | `EnsureUserIsAdmin` | Any admin-panel role (superadmin/akademik/keuangan), including prodi-only-scoped admins |
| `role.superadmin` | `EnsureUserIsSuperadmin` | Superadmin only — used for privilege-granting endpoints (roles, scopes, other users' permissions) |
| `role.admin.keuangan` | `EnsureUserHasKeuanganAccess` | Superadmin or Keuangan — stacked after `role.admin` |
| `role.admin.prodi` | `EnsureUserIsAdminProdi` | Dosen acting as Kepala/Sekretaris Prodi — the `/api/prodi/*` portal |
| `role.mahasiswa` | `EnsureUserIsMahasiswa` | Checks legacy `users.role === 'mahasiswa'` |
| `role.dosen` | `EnsureUserIsDosen` | Checks legacy `users.role === 'dosen'` |
| `partner.api.key` | `EnsurePartnerApiKey` | External systems (e.g. Siska); header-based API key from `config/partner_api.php`, not Sanctum |
| `superadmin.web` | `EnsureUserIsSuperadminWeb` | Session-based, for the Blade `/dashboard` panel only |

### Request flow

- API auth is Laravel Sanctum (`auth:sanctum`), stateful for SPA cookie auth (`statefulApi()` in
  `bootstrap/app.php`) as well as bearer tokens. CSRF is exempted for `api/*`.
- `routes/api.php` is one large file organized as nested `Route::middleware(...)->group()` blocks in
  this order: public/unauthenticated routes → `partner.api.key` routes → `auth:sanctum` wrapper
  containing `role.mahasiswa` group → `role.dosen` group → `role.admin.prodi` (`/prodi/*` portal)
  group → `role.admin` (main admin panel) group. **Student and dosen route groups are declared
  before the admin group deliberately** — comments in the file call out that ordering matters for
  path matching.
- `routes/pmb.php` is a self-contained admissions module, `require_once`'d at the bottom of
  `routes/api.php`, all under an `/pmb` prefix. It has its own `AuthController`
  (`App\Http\Controllers\Pmb\AuthController`, aliased differently from the main one) and its own
  auth/session boundary — do not assume PMB routes share the main app's role middleware.
- Controllers under `app/Http/Controllers/Pmb/` are the admissions module; everything else in
  `app/Http/Controllers/` is the main SIAK academic system. `app/Http/Controllers/Web/` is the
  small superadmin Blade panel (env config editor, migration trigger, test upload).

### Domain shape

Core entities follow the Indonesian higher-ed domain: `Fakultas` → `Prodi` → `Kurikulum` →
`KurikulumMatkul`/`Matkul`; `Mahasiswa` enrolls via `Krs` per `Semester`/`Kelas`/`Jadwal`; grading
flows through `Nilai`/`NilaiRevisi`/`KonversiNilai`/`RentangNilai`; RPS (`Rps`, `RpsCpl`, `RpsCpmk`,
`RpsSubcpmk`, `RpsPembelajaran`) models course learning-outcome plans per dosen/kelas; billing runs
through `Tagihan`/`TagihanRinci`/`Pembayaran`/`KomponenBiaya`/`StrukturBiaya`/`KeringananBiaya`; final
studies flow through `TugasAkhir` → `UjianSidang` → `Yudisium` → `Wisuda`. Most models use soft
deletes; when a controller needs to check "does this still exist", filter `whereNull('deleted_at')`
rather than relying on default query scoping (see `User::activeRoleScopesQuery()` for the pattern).

`app/Services/` holds small pieces of extracted business logic reused across controllers (e.g.
`SemesterService::hitungSemesterDitempuh*` for computing semester-progress from semester codes like
`"20241"`; `KeuanganAksesMahasiswaService` for the finance-access check reused by both admin and
student-facing endpoints; `KtmImageGenerator` for student ID card image rendering).

### Frontend/exception handling notes

- `bootstrap/app.php` forces JSON error responses (including 401s) for any `api/*` request or
  `expectsJson()` request, and disables the guest redirect-to-login for API paths — don't rely on
  Laravel's default redirect-to-login behavior when testing/handling unauthenticated API requests.
- CORS is enabled on both `web` and `api` middleware groups (for SPA preflight requests).
