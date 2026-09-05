<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function superadminWebUser(): User
{
    return adminUser();
}

it('redirects non-superadmin away from the migration page', function () {
    $this->actingAs(adminUser('admin_akademik'))
        ->get(route('superadmin.migrasi'))
        ->assertRedirect(route('login'));
});

it('shows that nothing is pending on a fully migrated database', function () {
    // RefreshDatabase sudah menjalankan seluruh migrasi sebelum test ini berjalan.
    $this->actingAs(superadminWebUser())
        ->get(route('superadmin.migrasi'))
        ->assertOk()
        ->assertSee('Tidak ada migrasi tertunda')
        ->assertDontSee('Jalankan Migrasi');
});

it('reports the ran and total migration counts', function () {
    $response = $this->actingAs(superadminWebUser())->get(route('superadmin.migrasi'));

    $response->assertOk();
    expect($response->viewData('ranCount'))->toBeGreaterThan(0);
    expect($response->viewData('ranCount'))->toBe($response->viewData('totalCount'));
    expect($response->viewData('pending'))->toBe([]);
});

// Menekan tombol saat tidak ada yang tertunda tidak boleh menjalankan artisan sama sekali —
// halaman ini dibuka orang yang tidak yakin apakah perlu migrasi, dan jawaban "tidak perlu"
// harus benar-benar berarti tidak ada yang dijalankan.
it('does not invoke artisan when there is nothing pending', function () {
    Artisan::shouldReceive('call')->never();
    Artisan::shouldReceive('output')->andReturn('');

    $this->actingAs(superadminWebUser())
        ->post(route('superadmin.migrasi.run'))
        ->assertRedirect(route('superadmin.migrasi'))
        ->assertSessionHas('status', 'Tidak ada migrasi yang tertunda. Database sudah mutakhir.');
});

it('rejects an unauthenticated attempt to run migrations', function () {
    $this->post(route('superadmin.migrasi.run'))->assertRedirect(route('login'));
});
