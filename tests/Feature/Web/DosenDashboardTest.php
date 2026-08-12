<?php

use App\Models\User;

it('redirects a guest to the login page', function () {
    $this->get(route('dosen.dashboard'))->assertRedirect(route('login'));
});

it('shows the dashboard to a dosen', function () {
    $dosen = dosenUser();

    $this->actingAs($dosen)
        ->get(route('dosen.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard');
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);

    $this->actingAs($mahasiswa)
        ->get(route('dosen.dashboard'))
        ->assertForbidden();
});

it('does not show a standalone Kehadiran sidebar item — it lives under the Jadwal Mengajar detail tab instead', function () {
    $dosen = dosenUser();

    $html = $this->actingAs($dosen)->get(route('dosen.dashboard'))->getContent();

    expect($html)->not->toContain(route('dosen.kehadiran'));
});
