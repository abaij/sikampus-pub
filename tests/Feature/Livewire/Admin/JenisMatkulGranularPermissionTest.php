<?php

use App\Livewire\Admin\JenisMatkul\Index;
use App\Models\JenisMatkul;
use Livewire\Livewire;

beforeEach(function () {
    config(['access.granular_permissions' => true]);
});

it('lets a fresh akademik admin view jenis mata kuliah but not reach create/edit routes', function () {
    $admin = adminUser('admin_akademik');
    $jenisMatkul = JenisMatkul::factory()->create();

    $this->actingAs($admin)->get(route('admin.jenis-matkul.index'))->assertOk();

    $this->actingAs($admin)->get(route('admin.jenis-matkul.create'))->assertStatus(403);
    $this->actingAs($admin)->get(route('admin.jenis-matkul.edit', $jenisMatkul->id))->assertStatus(403);
});

it('hides the tambah/ubah/hapus buttons from a view-only akademik admin', function () {
    $admin = adminUser('admin_akademik');
    $jenisMatkul = JenisMatkul::factory()->create();

    $this->actingAs($admin)->get(route('admin.jenis-matkul.index'))
        ->assertOk()
        ->assertDontSee('Tambah Jenis Mata Kuliah')
        ->assertDontSee(route('admin.jenis-matkul.edit', $jenisMatkul->id));
});

it('blocks a view-only akademik admin from deleting via the livewire method directly', function () {
    $admin = adminUser('admin_akademik');
    $jenisMatkul = JenisMatkul::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $jenisMatkul->id)
        ->assertStatus(403);

    expect(JenisMatkul::find($jenisMatkul->id))->not->toBeNull();
});

it('lets an akademik admin create, edit, and delete jenis mata kuliah once granted the specific permissions', function () {
    $admin = adminUser('admin_akademik');
    $admin->givePermissionTo(['create jenis mata kuliah', 'update jenis mata kuliah', 'delete jenis mata kuliah']);
    $jenisMatkul = JenisMatkul::factory()->create();

    $this->actingAs($admin)->get(route('admin.jenis-matkul.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.jenis-matkul.edit', $jenisMatkul->id))->assertOk();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $jenisMatkul->id)
        ->call('delete');

    expect(JenisMatkul::find($jenisMatkul->id))->toBeNull();
});

it('still lets superadmin do everything on jenis mata kuliah regardless of granular mode', function () {
    $admin = adminUser();
    $jenisMatkul = JenisMatkul::factory()->create();

    $this->actingAs($admin)->get(route('admin.jenis-matkul.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.jenis-matkul.edit', $jenisMatkul->id))->assertOk();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $jenisMatkul->id)
        ->call('delete');

    expect(JenisMatkul::find($jenisMatkul->id))->toBeNull();
});

it('still blocks keuangan-only admins from jenis mata kuliah entirely in granular mode', function () {
    $admin = adminUser('admin_keuangan');

    $this->actingAs($admin)->get(route('admin.jenis-matkul.index'))->assertStatus(403);
});
