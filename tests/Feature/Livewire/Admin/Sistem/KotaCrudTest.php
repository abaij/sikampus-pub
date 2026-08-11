<?php

use App\Livewire\Admin\Sistem\Kota\Form;
use App\Livewire\Admin\Sistem\Kota\Import;
use App\Livewire\Admin\Sistem\Kota\Index;
use App\Models\Kota;
use App\Models\Negara;
use App\Models\Provinsi;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeKotaImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Kode', 'Nama', 'Kode Provinsi'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'kota_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.sistem.kota'))->assertRedirect(route('login'));
});

it('forbids an admin who is not superadmin', function () {
    $admin = adminUser('admin_akademik');

    $this->actingAs($admin)->get(route('admin.sistem.kota'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.kota.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.kota.import'))->assertForbidden();
});

it('renders index, create, and edit pages for a superadmin', function () {
    $admin = adminUser();
    $kota = Kota::factory()->create(['nama' => 'Bandung']);

    $this->actingAs($admin)->get(route('admin.sistem.kota'))->assertOk()->assertSee('Bandung');
    $this->actingAs($admin)->get(route('admin.sistem.kota.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.sistem.kota.edit', $kota->id))->assertOk();
});

it('creates, updates, and deletes a kota', function () {
    $admin = adminUser();
    $provinsi = Provinsi::factory()->create(['kode' => '32']);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Bandung')
        ->set('kode', '3273')
        ->set('id_provinsi', $provinsi->id)
        ->call('save')
        ->assertRedirect(route('admin.sistem.kota'));

    $kota = Kota::where('kode', '3273')->firstOrFail();
    expect($kota->id_provinsi)->toBe($provinsi->id);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $kota->id])
        ->set('nama', 'Kota Bandung')
        ->call('save');

    expect($kota->fresh()->nama)->toBe('Kota Bandung');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $kota->id)
        ->call('delete');

    expect(Kota::find($kota->id))->toBeNull();
});

it('allows id_provinsi to be left blank since the column is nullable', function () {
    $admin = adminUser();

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Kota Tanpa Provinsi')
        ->set('kode', '9999')
        ->call('save')
        ->assertRedirect(route('admin.sistem.kota'));

    $kota = Kota::where('kode', '9999')->firstOrFail();
    expect($kota->id_provinsi)->toBeNull();
});

it('rejects a duplicate kode', function () {
    $admin = adminUser();
    Kota::factory()->create(['kode' => '3273']);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Duplikat')
        ->set('kode', '3273')
        ->call('save')
        ->assertHasErrors(['kode']);
});

it('the filterNegara aid narrows the provinsi dropdown without being persisted', function () {
    $admin = adminUser();
    $negaraA = Negara::factory()->create();
    $negaraB = Negara::factory()->create();
    $provinsiA = Provinsi::factory()->create(['nama' => 'Provinsi A', 'id_negara' => $negaraA->id]);
    Provinsi::factory()->create(['nama' => 'Provinsi B', 'id_negara' => $negaraB->id]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('filterNegara', (string) $negaraA->id)
        ->assertSee('Provinsi A')
        ->assertDontSee('Provinsi B')
        ->set('nama', 'Kota A')
        ->set('kode', '1111')
        ->set('id_provinsi', $provinsiA->id)
        ->call('save');

    $kota = Kota::where('kode', '1111')->firstOrFail();
    expect($kota->id_provinsi)->toBe($provinsiA->id);
});

it('filters the index by provinsi', function () {
    $admin = adminUser();
    $provinsiA = Provinsi::factory()->create();
    $provinsiB = Provinsi::factory()->create();
    Kota::factory()->create(['nama' => 'Kota A', 'id_provinsi' => $provinsiA->id]);
    Kota::factory()->create(['nama' => 'Kota B', 'id_provinsi' => $provinsiB->id]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('filterProvinsi', (string) $provinsiA->id)
        ->assertSee('Kota A')
        ->assertDontSee('Kota B');
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.sistem.kota.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('imports kota rows and sets id_provinsi from the kode provinsi column', function () {
    $admin = adminUser();
    $provinsi = Provinsi::factory()->create(['kode' => '32']);

    $file = makeKotaImportFile([
        ['3273', 'Bandung', '32'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $kota = Kota::where('kode', '3273')->firstOrFail();
    expect($kota->id_provinsi)->toBe($provinsi->id);
});

it('records an error when the kode provinsi cannot be found', function () {
    $admin = adminUser();

    $file = makeKotaImportFile([
        ['3273', 'Bandung', 'TIDAK-ADA'],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
    expect(Kota::where('kode', '3273')->exists())->toBeFalse();
});
