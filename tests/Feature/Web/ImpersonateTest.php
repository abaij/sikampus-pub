<?php

use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('lets a superadmin login as a dosen and back', function () {
    $admin = adminUser();
    $dosen = dosenUser();

    $this->actingAs($admin)
        ->post(route('admin.pengguna.impersonate.start', $dosen->id))
        ->assertRedirect(route('dosen.dashboard'));

    expect(Auth::id())->toBe($dosen->id);
    expect(session('impersonator_id'))->toBe($admin->id);

    $log = ImpersonationLog::firstOrFail();
    expect($log->id_admin)->toBe($admin->id)
        ->and($log->id_target_user)->toBe($dosen->id)
        ->and($log->ended_at)->toBeNull();

    $this->post(route('impersonate.stop'))
        ->assertRedirect(route('admin.pengguna.show', $dosen->id));

    expect(Auth::id())->toBe($admin->id);
    expect(session('impersonator_id'))->toBeNull();
    expect($log->refresh()->ended_at)->not->toBeNull();
});

it('forbids a non-superadmin admin from starting impersonation', function () {
    $akademik = adminUser('admin_akademik');
    $dosen = dosenUser();

    $this->actingAs($akademik)
        ->post(route('admin.pengguna.impersonate.start', $dosen->id))
        ->assertForbidden();

    expect(Auth::id())->toBe($akademik->id);
});

it('refuses to impersonate an account that is not dosen/mahasiswa', function () {
    $admin = adminUser();
    $otherAdmin = adminUser('admin_akademik');

    $this->actingAs($admin)
        ->post(route('admin.pengguna.impersonate.start', $otherAdmin->id))
        ->assertStatus(422);
});

it('refuses nested impersonation because the acting session is no longer superadmin', function () {
    $admin = adminUser();
    $dosen = dosenUser();
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);

    $this->actingAs($admin)
        ->post(route('admin.pengguna.impersonate.start', $dosen->id))
        ->assertRedirect(route('dosen.dashboard'));

    // Auth::user() sekarang $dosen, bukan superadmin — role.admin.superadmin menolak sebelum
    // request ini pernah masuk ImpersonateController::start().
    $this->post(route('admin.pengguna.impersonate.start', $mahasiswa->id))
        ->assertForbidden();

    expect(Auth::id())->toBe($dosen->id);
});

it('redirects a guest away from impersonate routes', function () {
    $dosen = dosenUser();

    $this->post(route('admin.pengguna.impersonate.start', $dosen->id))
        ->assertRedirect(route('login'));

    $this->post(route('impersonate.stop'))
        ->assertRedirect(route('login'));
});
