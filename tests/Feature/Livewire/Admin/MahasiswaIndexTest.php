<?php

use App\Livewire\Admin\Mahasiswa\Index;
use App\Models\KelompokKelas;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Perkuliahan;
use App\Models\Prodi;
use App\Models\StatusAkademik;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('renders as a full page with searchable-select filters', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nama' => 'Budi Santoso']);

    $this->actingAs($admin)
        ->get(route('admin.administrasi.mahasiswa'))
        ->assertOk()
        ->assertSee('Budi Santoso')
        ->assertSee('x-data', false);
});

it('scopes the kelas mahasiswa filter options to the selected prodi', function () {
    $admin = adminUser();
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $kelasA = KelompokKelas::factory()->create(['nama' => 'Kelas A', 'id_prodi' => $prodiA->id]);
    $kelasB = KelompokKelas::factory()->create(['nama' => 'Kelas B', 'id_prodi' => $prodiB->id]);

    $component = Livewire::actingAs($admin)->test(Index::class);

    expect($component->instance()->kelompokKelasOptions->pluck('id'))
        ->toContain($kelasA->id, $kelasB->id);

    $component->set('filterProdi', (string) $prodiA->id);

    expect($component->instance()->kelompokKelasOptions->pluck('id'))
        ->toContain($kelasA->id)
        ->not->toContain($kelasB->id);
});

it('resets the kelas mahasiswa filter when the prodi filter changes', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kelas = KelompokKelas::factory()->create(['id_prodi' => $prodi->id]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('filterKelompokKelas', (string) $kelas->id)
        ->set('filterProdi', (string) $prodi->id)
        ->assertSet('filterKelompokKelas', '');
});

it('filters the mahasiswa list by prodi and kelas mahasiswa', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kelas = KelompokKelas::factory()->create(['id_prodi' => $prodi->id]);
    Mahasiswa::factory()->create(['nama' => 'Dalam Filter', 'id_prodi' => $prodi->id, 'id_kelompok_kelas' => $kelas->id]);
    Mahasiswa::factory()->create(['nama' => 'Luar Filter']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('filterStatusAkademik', '')
        ->set('filterProdi', (string) $prodi->id)
        ->set('filterKelompokKelas', (string) $kelas->id)
        ->assertSee('Dalam Filter')
        ->assertDontSee('Luar Filter');
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.administrasi.mahasiswa'))
        ->assertRedirect(route('login'));
});

it('hides soft-deleted mahasiswa by default and shows them with a restore/hapus-permanen action when toggled on', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nama' => 'Mahasiswa Terhapus']);
    $mahasiswa->delete();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertDontSee('Mahasiswa Terhapus');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->assertSee('Mahasiswa Terhapus')
        ->assertSee('Dihapus');
});

// mount() men-default-kan filterStatusAkademik ke "Aktif" — kalau tidak ikut dibuang saat toggle
// dinyalakan, baris soft-deleted (id_status_akademik-nya tetap apa adanya) akan tersaring habis
// dan toggle terlihat seperti tidak berfungsi.
it('clears the default status akademik filter when the trashed toggle is turned on', function () {
    $admin = adminUser();
    $aktif = StatusAkademik::factory()->create(['nama' => 'Aktif']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSet('filterStatusAkademik', (string) $aktif->id)
        ->set('showTrashed', true)
        ->assertSet('filterStatusAkademik', '');
});

it('restores a soft-deleted mahasiswa instead of trying to recreate it', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => 'MHS001']);
    $mahasiswa->delete();
    expect(Mahasiswa::find($mahasiswa->id))->toBeNull();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('restore', $mahasiswa->id);

    expect(Mahasiswa::find($mahasiswa->id))->not->toBeNull();
    expect(Mahasiswa::find($mahasiswa->id)->deleted_at)->toBeNull();
});

it('permanently deletes a soft-deleted mahasiswa that has no related records', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create();
    $mahasiswa->delete();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('confirmForceDelete', $mahasiswa->id)
        ->call('forceDeleteMahasiswa');

    expect(Mahasiswa::withTrashed()->find($mahasiswa->id))->toBeNull();
});

// krs (kolom id_mahasiswa) dan kehadiran (kolom id_mhs, satu-satunya yang beda nama) —
// keduanya constrained('mahasiswa')->restrictOnDelete() dan restrict itu tetap berlaku walau
// baris perujuknya sendiri sudah soft-deleted, jadi harus ditolak lebih dulu dengan pesan jelas.
it('refuses to permanently delete a mahasiswa still referenced by krs or kehadiran', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create();

    $krs = Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id]);
    $krs->delete();

    $perkuliahan = Perkuliahan::factory()->create();
    DB::table('kehadiran')->insert([
        'id_perkuliahan' => $perkuliahan->id,
        'id_mhs' => $mahasiswa->id,
        'status' => 'hadir',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mahasiswa->delete();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('confirmForceDelete', $mahasiswa->id)
        ->call('forceDeleteMahasiswa');

    expect(Mahasiswa::withTrashed()->find($mahasiswa->id)->trashed())->toBeTrue();
});

it('admin dengan scope prodi tidak bisa memulihkan atau menghapus permanen mahasiswa di luar scope-nya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $mahasiswaB = Mahasiswa::factory()->create(['id_prodi' => $prodiB->id]);
    $mahasiswaB->delete();

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('restore', $mahasiswaB->id)
        ->assertStatus(403);

    expect(Mahasiswa::withTrashed()->find($mahasiswaB->id)->trashed())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('confirmForceDelete', $mahasiswaB->id)
        ->call('forceDeleteMahasiswa')
        ->assertStatus(403);

    expect(Mahasiswa::withTrashed()->find($mahasiswaB->id)->trashed())->toBeTrue();
});
