<?php

use App\Livewire\Admin\Matkul\Index;
use App\Models\Matkul;
use Livewire\Livewire;

beforeEach(function () {
    config(['access.granular_permissions' => true]);
});

it('lets a fresh akademik admin view mata kuliah but not reach create/edit routes', function () {
    $admin = adminUser('admin_akademik');
    $matkul = Matkul::factory()->create();

    $this->actingAs($admin)->get(route('admin.akademik.matkul'))->assertOk();
    $this->actingAs($admin)->get(route('admin.akademik.matkul.show', $matkul->id))->assertOk();

    $this->actingAs($admin)->get(route('admin.akademik.matkul.create'))->assertStatus(403);
    $this->actingAs($admin)->get(route('admin.akademik.matkul.edit', $matkul->id))->assertStatus(403);
});

it('hides the tambah/ubah/hapus/pulihkan/hapus permanen buttons from a view-only akademik admin', function () {
    $admin = adminUser('admin_akademik');
    $matkul = Matkul::factory()->create();
    $trashedMatkul = Matkul::factory()->create();
    $trashedMatkul->delete();

    $this->actingAs($admin)->get(route('admin.akademik.matkul'))
        ->assertOk()
        ->assertDontSee('Tambah Mata Kuliah')
        ->assertDontSee(route('admin.akademik.matkul.edit', $matkul->id));

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->assertDontSee('Pulihkan')
        ->assertDontSee('Hapus Permanen');
});

it('blocks a view-only akademik admin from deleting mata kuliah via the livewire method directly', function () {
    $admin = adminUser('admin_akademik');
    $matkul = Matkul::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $matkul->id)
        ->assertStatus(403);

    expect(Matkul::find($matkul->id))->not->toBeNull();
});

it('blocks a view-only akademik admin from restoring or permanently deleting mata kuliah via the livewire methods directly', function () {
    $admin = adminUser('admin_akademik');
    $matkul = Matkul::factory()->create();
    $matkul->delete();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('restore', $matkul->id)
        ->assertStatus(403);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmForceDelete', $matkul->id)
        ->assertStatus(403);

    expect(Matkul::onlyTrashed()->find($matkul->id))->not->toBeNull();
});

it('lets an akademik admin create, edit, and delete mata kuliah once granted the specific permissions', function () {
    $admin = adminUser('admin_akademik');
    $admin->givePermissionTo(['create mata kuliah', 'update mata kuliah', 'delete mata kuliah']);
    $matkul = Matkul::factory()->create();

    $this->actingAs($admin)->get(route('admin.akademik.matkul.create'))
        ->assertOk()
        ->assertSee('Tambah Mata Kuliah');

    $this->actingAs($admin)->get(route('admin.akademik.matkul.edit', $matkul->id))->assertOk();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $matkul->id)
        ->call('delete');

    expect(Matkul::find($matkul->id))->toBeNull();
});

it('lets an akademik admin restore and permanently delete mata kuliah once granted delete', function () {
    $admin = adminUser('admin_akademik');
    $admin->givePermissionTo(['delete mata kuliah']);
    $matkul = Matkul::factory()->create();
    $matkul->delete();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('restore', $matkul->id);

    expect(Matkul::find($matkul->id))->not->toBeNull();

    $matkul->refresh()->delete();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmForceDelete', $matkul->id)
        ->call('forceDeleteMatkul');

    expect(Matkul::onlyTrashed()->find($matkul->id))->toBeNull();
});

it('still lets superadmin do everything on mata kuliah regardless of granular mode', function () {
    $admin = adminUser();
    $matkul = Matkul::factory()->create();

    $this->actingAs($admin)->get(route('admin.akademik.matkul.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.akademik.matkul.edit', $matkul->id))->assertOk();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $matkul->id)
        ->call('delete');

    expect(Matkul::find($matkul->id))->toBeNull();
});

it('still blocks keuangan-only admins from mata kuliah entirely in granular mode', function () {
    $admin = adminUser('admin_keuangan');

    $this->actingAs($admin)->get(route('admin.akademik.matkul'))->assertStatus(403);
});
