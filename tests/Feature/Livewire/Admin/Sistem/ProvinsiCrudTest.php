<?php

use App\Livewire\Admin\Sistem\Provinsi\Form;
use App\Livewire\Admin\Sistem\Provinsi\Import;
use App\Livewire\Admin\Sistem\Provinsi\Index;
use App\Models\Negara;
use App\Models\Provinsi;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeProvinsiImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Kode', 'Nama', 'Kode Negara'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'provinsi_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.sistem.provinsi'))->assertRedirect(route('login'));
});

it('forbids an admin who is not superadmin', function () {
    $admin = adminUser('admin_akademik');

    $this->actingAs($admin)->get(route('admin.sistem.provinsi'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.provinsi.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.provinsi.import'))->assertForbidden();
});

it('renders index, create, and edit pages for a superadmin', function () {
    $admin = adminUser();
    $provinsi = Provinsi::factory()->create(['nama' => 'Jawa Barat']);

    $this->actingAs($admin)->get(route('admin.sistem.provinsi'))->assertOk()->assertSee('Jawa Barat');
    $this->actingAs($admin)->get(route('admin.sistem.provinsi.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.sistem.provinsi.edit', $provinsi->id))->assertOk();
});

it('creates, updates, and deletes a provinsi', function () {
    $admin = adminUser();
    $negara = Negara::factory()->create(['kode' => 'ID']);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Jawa Barat')
        ->set('kode', '32')
        ->set('id_negara', $negara->id)
        ->call('save')
        ->assertRedirect(route('admin.sistem.provinsi'));

    $provinsi = Provinsi::where('kode', '32')->firstOrFail();
    expect($provinsi->id_negara)->toBe($negara->id);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $provinsi->id])
        ->set('nama', 'Jawa Barat (Updated)')
        ->call('save');

    expect($provinsi->fresh()->nama)->toBe('Jawa Barat (Updated)');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $provinsi->id)
        ->call('delete');

    expect(Provinsi::find($provinsi->id))->toBeNull();
});

it('rejects a duplicate kode', function () {
    $admin = adminUser();
    $negara = Negara::factory()->create();
    Provinsi::factory()->create(['kode' => '32', 'id_negara' => $negara->id]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Duplikat')
        ->set('kode', '32')
        ->set('id_negara', $negara->id)
        ->call('save')
        ->assertHasErrors(['kode']);
});

it('filters by negara', function () {
    $admin = adminUser();
    $negaraA = Negara::factory()->create();
    $negaraB = Negara::factory()->create();
    Provinsi::factory()->create(['nama' => 'Provinsi A', 'id_negara' => $negaraA->id]);
    Provinsi::factory()->create(['nama' => 'Provinsi B', 'id_negara' => $negaraB->id]);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('filterNegara', (string) $negaraA->id)
        ->assertSee('Provinsi A')
        ->assertDontSee('Provinsi B');
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.sistem.provinsi.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('imports provinsi rows and sets id_negara from the kode negara column', function () {
    $admin = adminUser();
    $negara = Negara::factory()->create(['kode' => 'ID']);

    $file = makeProvinsiImportFile([
        ['32', 'Jawa Barat', 'ID'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $provinsi = Provinsi::where('kode', '32')->firstOrFail();
    expect($provinsi->id_negara)->toBe($negara->id);
});

it('records an error when the kode negara cannot be found', function () {
    $admin = adminUser();

    $file = makeProvinsiImportFile([
        ['32', 'Jawa Barat', 'TIDAK-ADA'],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
    expect(Provinsi::where('kode', '32')->exists())->toBeFalse();
});
