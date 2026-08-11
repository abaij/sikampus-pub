<?php

use App\Livewire\Admin\Sistem\Kecamatan\Form;
use App\Livewire\Admin\Sistem\Kecamatan\Import;
use App\Livewire\Admin\Sistem\Kecamatan\Index;
use App\Models\Kecamatan;
use App\Models\Kota;
use App\Models\Negara;
use App\Models\Provinsi;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeKecamatanImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Kode', 'Nama', 'Kode Kota'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'kecamatan_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.sistem.kecamatan'))->assertRedirect(route('login'));
});

it('forbids an admin who is not superadmin', function () {
    $admin = adminUser('admin_akademik');

    $this->actingAs($admin)->get(route('admin.sistem.kecamatan'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.kecamatan.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.kecamatan.import'))->assertForbidden();
});

it('renders index, create, and edit pages for a superadmin', function () {
    $admin = adminUser();
    $kecamatan = Kecamatan::factory()->create(['nama' => 'Bandung Wetan']);

    $this->actingAs($admin)->get(route('admin.sistem.kecamatan'))->assertOk()->assertSee('Bandung Wetan');
    $this->actingAs($admin)->get(route('admin.sistem.kecamatan.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.sistem.kecamatan.edit', $kecamatan->id))->assertOk();
});

it('creates, updates, and deletes a kecamatan', function () {
    $admin = adminUser();
    $kota = Kota::factory()->create(['kode' => '3273']);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Bandung Wetan')
        ->set('kode', '327301')
        ->set('id_kota', $kota->id)
        ->call('save')
        ->assertRedirect(route('admin.sistem.kecamatan'));

    $kecamatan = Kecamatan::where('kode', '327301')->firstOrFail();
    expect($kecamatan->id_kota)->toBe($kota->id);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $kecamatan->id])
        ->set('nama', 'Bandung Wetan (Updated)')
        ->call('save');

    expect($kecamatan->fresh()->nama)->toBe('Bandung Wetan (Updated)');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $kecamatan->id)
        ->call('delete');

    expect(Kecamatan::find($kecamatan->id))->toBeNull();
});

it('rejects a duplicate kode', function () {
    $admin = adminUser();
    Kecamatan::factory()->create(['kode' => '327301']);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Duplikat')
        ->set('kode', '327301')
        ->call('save')
        ->assertHasErrors(['kode']);
});

it('the filterNegara/filterProvinsi aids narrow the kota dropdown without being persisted', function () {
    $admin = adminUser();
    $negara = Negara::factory()->create();
    $provinsiA = Provinsi::factory()->create(['id_negara' => $negara->id]);
    $provinsiB = Provinsi::factory()->create();
    $kotaA = Kota::factory()->create(['nama' => 'Kota A', 'id_provinsi' => $provinsiA->id]);
    Kota::factory()->create(['nama' => 'Kota B', 'id_provinsi' => $provinsiB->id]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('filterNegara', (string) $negara->id)
        ->set('filterProvinsi', (string) $provinsiA->id)
        ->assertSee('Kota A')
        ->assertDontSee('Kota B')
        ->set('nama', 'Kecamatan A')
        ->set('kode', '111101')
        ->set('id_kota', $kotaA->id)
        ->call('save');

    $kecamatan = Kecamatan::where('kode', '111101')->firstOrFail();
    expect($kecamatan->id_kota)->toBe($kotaA->id);
});

it('filters the index by kota', function () {
    $admin = adminUser();
    $kotaA = Kota::factory()->create();
    $kotaB = Kota::factory()->create();
    Kecamatan::factory()->create(['nama' => 'Kecamatan A', 'id_kota' => $kotaA->id]);
    Kecamatan::factory()->create(['nama' => 'Kecamatan B', 'id_kota' => $kotaB->id]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('filterKota', (string) $kotaA->id)
        ->assertSee('Kecamatan A')
        ->assertDontSee('Kecamatan B');
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.sistem.kecamatan.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('imports kecamatan rows and sets id_kota from the kode kota column', function () {
    $admin = adminUser();
    $kota = Kota::factory()->create(['kode' => '3273']);

    $file = makeKecamatanImportFile([
        ['327301', 'Bandung Wetan', '3273'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $kecamatan = Kecamatan::where('kode', '327301')->firstOrFail();
    expect($kecamatan->id_kota)->toBe($kota->id);
});

it('records an error when the kode kota cannot be found', function () {
    $admin = adminUser();

    $file = makeKecamatanImportFile([
        ['327301', 'Bandung Wetan', 'TIDAK-ADA'],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
    expect(Kecamatan::where('kode', '327301')->exists())->toBeFalse();
});
