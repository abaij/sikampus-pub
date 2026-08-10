<?php

use App\Livewire\Admin\JenisMatkul\Form;
use App\Livewire\Admin\JenisMatkul\Index;
use App\Models\JenisMatkul;
use Livewire\Livewire;

it('renders index and create form as full pages', function () {
    $admin = adminUser();
    JenisMatkul::factory()->create(['nama' => 'Mata Kuliah Wajib']);

    $this->actingAs($admin)->get(route('admin.jenis-matkul.index'))->assertOk()->assertSee('Mata Kuliah Wajib');
    $this->actingAs($admin)->get(route('admin.jenis-matkul.create'))->assertOk()->assertSee('Tambah Jenis Mata Kuliah');
});

it('creates, updates, and deletes a jenis mata kuliah', function () {
    $admin = adminUser();

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Mata Kuliah Wajib')
        ->set('kode', 'MKW')
        ->set('deskripsi', 'Wajib ditempuh seluruh mahasiswa')
        ->call('save')
        ->assertRedirect(route('admin.jenis-matkul.index'));

    $jenisMatkul = JenisMatkul::where('nama', 'Mata Kuliah Wajib')->firstOrFail();
    expect($jenisMatkul->kode)->toBe('MKW');
    expect($jenisMatkul->deskripsi)->toBe('Wajib ditempuh seluruh mahasiswa');

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $jenisMatkul->id])
        ->assertSet('nama', 'Mata Kuliah Wajib')
        ->assertSet('kode', 'MKW')
        ->set('nama', 'Mata Kuliah Wajib Kurikulum')
        ->call('save');

    expect($jenisMatkul->fresh()->nama)->toBe('Mata Kuliah Wajib Kurikulum');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $jenisMatkul->id)
        ->call('delete');

    expect(JenisMatkul::find($jenisMatkul->id))->toBeNull();
});

it('rejects duplicate nama and duplicate kode', function () {
    $admin = adminUser();
    JenisMatkul::factory()->create(['nama' => 'Mata Kuliah Wajib', 'kode' => 'MKW']);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Mata Kuliah Wajib')
        ->set('kode', 'MKP')
        ->call('save')
        ->assertHasErrors(['nama']);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Mata Kuliah Pilihan')
        ->set('kode', 'MKW')
        ->call('save')
        ->assertHasErrors(['kode']);
});

it('requires kode to be filled', function () {
    $admin = adminUser();

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Mata Kuliah Pilihan')
        ->set('kode', '')
        ->call('save')
        ->assertHasErrors(['kode']);
});

it('redirects unauthenticated users to the admin login page', function () {
    $this->get(route('admin.jenis-matkul.index'))->assertRedirect(route('login'));
});
