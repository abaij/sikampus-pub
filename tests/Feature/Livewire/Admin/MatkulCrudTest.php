<?php

use App\Livewire\Admin\Matkul\Form;
use App\Livewire\Admin\Matkul\Index;
use App\Livewire\Admin\Matkul\Show;
use App\Models\Matkul;
use App\Models\MatkulPrasyarat;
use App\Models\Prodi;
use Livewire\Livewire;

it('renders index and create form as full pages', function () {
    $admin = adminUser();
    Matkul::factory()->create(['nama' => 'Algoritma dan Struktur Data']);

    $this->actingAs($admin)->get(route('admin.akademik.matkul'))->assertOk()->assertSee('Algoritma dan Struktur Data');
    $this->actingAs($admin)->get(route('admin.akademik.matkul.create'))->assertOk()->assertSee('Tambah Mata Kuliah');
});

it('creates, updates, and deletes a matkul', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('kode', 'MK001')
        ->set('nama', 'Pemrograman Web')
        ->set('id_prodi', $prodi->id)
        ->set('sks', '3')
        ->set('semester', '2')
        ->call('save')
        ->assertRedirect(route('admin.akademik.matkul'));

    $matkul = Matkul::where('kode', 'MK001')->firstOrFail();
    expect($matkul->sks)->toBe(3);
    expect($matkul->semester)->toBe(2);
    expect($matkul->id_prodi)->toBe($prodi->id);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $matkul->id])
        ->assertSet('nama', 'Pemrograman Web')
        ->assertSet('sks', '3')
        ->set('nama', 'Pemrograman Web Lanjut')
        ->call('save');

    expect($matkul->fresh()->nama)->toBe('Pemrograman Web Lanjut');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $matkul->id)
        ->call('delete');

    expect(Matkul::find($matkul->id))->toBeNull();
});

it('hides soft-deleted matkul by default and shows them with a restore action when toggled on', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $matkul = Matkul::factory()->create(['kode' => 'MK200', 'nama' => 'Matkul Terhapus', 'id_prodi' => $prodi->id]);
    $matkul->delete();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertDontSee('Matkul Terhapus');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->assertSee('Matkul Terhapus')
        ->assertSee('Dihapus');
});

it('restores a soft-deleted matkul instead of trying to recreate it', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $matkul = Matkul::factory()->create(['kode' => 'MK201', 'id_prodi' => $prodi->id]);
    $matkul->delete();
    expect(Matkul::find($matkul->id))->toBeNull();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('restore', $matkul->id);

    expect(Matkul::find($matkul->id))->not->toBeNull();
    expect(Matkul::find($matkul->id)->deleted_at)->toBeNull();
});

it('refuses to restore a matkul whose kode+prodi is already taken by an active matkul', function () {
    // Kombinasi kode+id_prodi lain (yang benar-benar prodi) tidak bisa dipakai untuk skenario ini
    // sekaligus: unique index DB-nya sendiri tidak mengecualikan baris soft-deleted, jadi baris
    // aktif kedua dengan kode+prodi yang sama pun tidak akan pernah bisa dibuat (ikut membuktikan
    // laporan awal soal kode "terkunci" oleh baris terhapus). Satu-satunya kombinasi yang MySQL
    // benar-benar biarkan duplikat adalah id_prodi NULL (NULL dianggap berbeda dari NULL lain di
    // unique index InnoDB) — jadi kasus konflik nyata cuma bisa terjadi di situ.
    $admin = adminUser();
    $trashed = Matkul::factory()->create(['kode' => 'MK202', 'id_prodi' => null]);
    $trashed->delete();
    Matkul::factory()->create(['kode' => 'MK202', 'id_prodi' => null]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('restore', $trashed->id);

    expect(Matkul::withTrashed()->find($trashed->id)->trashed())->toBeTrue();
});

it('admin dengan scope prodi tidak bisa memulihkan matkul di luar scope-nya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $matkulB = Matkul::factory()->create(['id_prodi' => $prodiB->id]);
    $matkulB->delete();

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('showTrashed', true)
        ->call('restore', $matkulB->id)
        ->assertStatus(403);

    expect(Matkul::withTrashed()->find($matkulB->id)->trashed())->toBeTrue();
});

// Regression yang jadi alasan tombol Pulihkan ada: unique index kode+id_prodi di DB (dan
// Rule::unique di MatkulController/Form yang meniru) tidak mengecualikan baris soft-deleted, jadi
// mengisi ulang form Tambah dengan kode yang sama selalu gagal — satu-satunya jalan adalah
// memulihkan baris lama lewat tabel index, bukan membuat baris baru.
it('still rejects creating a new matkul whose kode+prodi belongs to a soft-deleted one', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $trashed = Matkul::factory()->create(['kode' => 'MK203', 'id_prodi' => $prodi->id]);
    $trashed->delete();

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('kode', 'MK203')
        ->set('nama', 'Mata Kuliah Baru')
        ->set('id_prodi', $prodi->id)
        ->call('save')
        ->assertHasErrors('kode');

    expect(Matkul::where('kode', 'MK203')->where('id_prodi', $prodi->id)->count())->toBe(0);
});

it('allows the same kode across different prodi but not within the same prodi', function () {
    $admin = adminUser();
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    Matkul::factory()->create(['kode' => 'MK100', 'id_prodi' => $prodiA->id]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('kode', 'MK100')
        ->set('nama', 'Mata Kuliah Lain')
        ->set('id_prodi', $prodiB->id)
        ->call('save')
        ->assertRedirect(route('admin.akademik.matkul'));

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('kode', 'MK100')
        ->set('nama', 'Mata Kuliah Duplikat')
        ->set('id_prodi', $prodiA->id)
        ->call('save')
        ->assertHasErrors('kode');
});

it('carries the current page/filter state from index into the Lihat link', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    Matkul::factory()->count(15)->create(['id_prodi' => $prodi->id]);

    $expectedQuery = 'id_prodi='.$prodi->id.'&page=2';

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('filterProdi', (string) $prodi->id)
        ->set('perPage', 10)
        ->call('gotoPage', 2)
        ->assertSee($expectedQuery);
});

it('carries the current page/filter state from index into the Ubah link', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    foreach (range(1, 12) as $i) {
        Matkul::factory()->create(['kode' => sprintf('Z%02d', $i), 'id_prodi' => $prodi->id]);
    }
    $pageTwoFirst = Matkul::where('kode', 'Z11')->firstOrFail();

    $query = 'id_prodi='.$prodi->id.'&page=2';

    $this->actingAs($admin)
        ->get(route('admin.akademik.matkul').'?'.$query)
        ->assertOk()
        ->assertSee(route('admin.akademik.matkul.show', $pageTwoFirst->id).'?'.$query)
        ->assertSee(route('admin.akademik.matkul.edit', $pageTwoFirst->id).'?'.$query);
});

it('points the back button to the page/filter state carried in the query string', function () {
    $admin = adminUser();
    $matkul = Matkul::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.akademik.matkul.show', $matkul->id).'?page=2&search=algoritma&unexpected=1')
        ->assertOk()
        ->assertSee(route('admin.akademik.matkul').'?page=2&search=algoritma')
        ->assertDontSee('unexpected=1');
});

it('carries the forwarded state into the Ubah link on the detail page too', function () {
    $admin = adminUser();
    $matkul = Matkul::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.akademik.matkul.show', $matkul->id).'?page=2&search=algoritma')
        ->assertOk()
        ->assertSee(route('admin.akademik.matkul.edit', $matkul->id).'?page=2&search=algoritma');
});

it('carries the forwarded state through the edit form Batal link and the save redirect', function () {
    $admin = adminUser();
    $matkul = Matkul::factory()->create();

    $expectedBackUrl = route('admin.akademik.matkul').'?page=2&search=algoritma';

    $this->actingAs($admin)
        ->get(route('admin.akademik.matkul.edit', $matkul->id).'?page=2&search=algoritma&unexpected=1')
        ->assertOk()
        ->assertSee($expectedBackUrl)
        ->assertDontSee('unexpected=1');

    Livewire::withQueryParams(['page' => '2', 'search' => 'algoritma'])
        ->actingAs($admin)
        ->test(Form::class, ['id' => $matkul->id])
        ->set('nama', 'Nama Baru')
        ->call('save')
        ->assertRedirect($expectedBackUrl);
});

it('falls back to the plain index url when no state was carried over', function () {
    $admin = adminUser();
    $matkul = Matkul::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.akademik.matkul.show', $matkul->id))
        ->assertOk()
        ->assertSee(route('admin.akademik.matkul'), false);
});

it('shows matkul detail and manages prasyarat', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $matkul = Matkul::factory()->create(['nama' => 'Basis Data', 'id_prodi' => $prodi->id]);
    $calonPrasyarat = Matkul::factory()->create(['nama' => 'Algoritma Dasar', 'id_prodi' => $prodi->id]);
    $bedaProdi = Matkul::factory()->create(['nama' => 'Fisika Dasar']);

    $this->actingAs($admin)
        ->get(route('admin.akademik.matkul.show', $matkul->id))
        ->assertOk()
        ->assertSee('Basis Data');

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $matkul->id])
        ->set('prasyaratSearch', 'Algoritma')
        ->call('selectPrasyaratOption', $calonPrasyarat->id, "{$calonPrasyarat->kode} — {$calonPrasyarat->nama}")
        ->call('savePrasyarat');

    expect(MatkulPrasyarat::where('id_matkul', $matkul->id)->where('id_matkul_prasyarat', $calonPrasyarat->id)->exists())->toBeTrue();

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $matkul->id])
        ->call('selectPrasyaratOption', $bedaProdi->id, "{$bedaProdi->kode} — {$bedaProdi->nama}")
        ->call('savePrasyarat')
        ->assertHasErrors('selectedPrasyaratId');

    $row = MatkulPrasyarat::where('id_matkul', $matkul->id)->first();

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $matkul->id])
        ->call('confirmDeletePrasyarat', $row->id)
        ->call('deletePrasyarat');

    expect(MatkulPrasyarat::find($row->id))->toBeNull();
});

it('prevents a cyclic prasyarat relationship', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $matkulA = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $matkulB = Matkul::factory()->create(['id_prodi' => $prodi->id]);

    MatkulPrasyarat::create(['id_matkul' => $matkulA->id, 'id_matkul_prasyarat' => $matkulB->id]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $matkulB->id])
        ->call('selectPrasyaratOption', $matkulA->id, "{$matkulA->kode} — {$matkulA->nama}")
        ->call('savePrasyarat')
        ->assertHasErrors('selectedPrasyaratId');

    expect(MatkulPrasyarat::where('id_matkul', $matkulB->id)->where('id_matkul_prasyarat', $matkulA->id)->exists())->toBeFalse();
});

it('admin dengan scope prodi hanya melihat matkul miliknya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    Matkul::factory()->create(['nama' => 'Matkul Prodi A', 'id_prodi' => $prodiA->id]);
    Matkul::factory()->create(['nama' => 'Matkul Prodi B', 'id_prodi' => $prodiB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Matkul Prodi A')
        ->assertDontSee('Matkul Prodi B');
});

it('admin dengan scope prodi tidak bisa menghapus matkul di luar scope-nya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $matkulB = Matkul::factory()->create(['id_prodi' => $prodiB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $matkulB->id)
        ->call('delete')
        ->assertStatus(403);

    expect(Matkul::find($matkulB->id))->not->toBeNull();
});

it('admin dengan scope prodi tidak bisa membuka detail matkul di luar scope-nya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $matkulB = Matkul::factory()->create(['id_prodi' => $prodiB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    $this->actingAs($admin)
        ->get(route('admin.akademik.matkul.show', $matkulB->id))
        ->assertStatus(403);
});

it('redirects unauthenticated users to the admin login page', function () {
    $this->get(route('admin.akademik.matkul'))->assertRedirect(route('login'));
});

// Regression: layouts.web me-render @section('page_actions') di luar root <div> komponen, jadi
// tombol wire:click yang diletakkan di sana tidak pernah terikat Livewire dan diam saja saat diklik.
it('keeps the delete button inside the livewire root so wire:click stays bound', function () {
    $admin = adminUser();
    $matkul = Matkul::factory()->create();

    $html = $this->actingAs($admin)->get(route('admin.akademik.matkul.show', $matkul->id))->getContent();

    $rootStart = strpos($html, 'wire:id=');
    expect($rootStart)->not->toBeFalse();
    expect(strpos($html, 'wire:click="confirmDeleteMatkul"'))->toBeGreaterThan($rootStart);
});
