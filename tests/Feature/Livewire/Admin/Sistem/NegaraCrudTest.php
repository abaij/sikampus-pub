<?php

use App\Livewire\Admin\Sistem\Negara\Form;
use App\Livewire\Admin\Sistem\Negara\Import;
use App\Livewire\Admin\Sistem\Negara\Index;
use App\Models\Negara;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function makeNegaraImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Kode', 'Nama'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'negara_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.sistem.negara'))->assertRedirect(route('login'));
});

it('forbids an admin who is not superadmin', function () {
    $admin = adminUser('admin_akademik');

    $this->actingAs($admin)->get(route('admin.sistem.negara'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.negara.create'))->assertForbidden();
    $this->actingAs($admin)->get(route('admin.sistem.negara.import'))->assertForbidden();
});

it('renders index, create, and edit pages for a superadmin', function () {
    $admin = adminUser();
    $negara = Negara::factory()->create(['nama' => 'Indonesia']);

    $this->actingAs($admin)->get(route('admin.sistem.negara'))->assertOk()->assertSee('Indonesia');
    $this->actingAs($admin)->get(route('admin.sistem.negara.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.sistem.negara.edit', $negara->id))->assertOk();
});

it('creates, updates, and deletes a negara', function () {
    $admin = adminUser();

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('nama', 'Indonesia')
        ->set('kode', 'ID')
        ->call('save')
        ->assertRedirect(route('admin.sistem.negara'));

    $negara = Negara::where('kode', 'ID')->firstOrFail();
    expect($negara->nama)->toBe('Indonesia');

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $negara->id])
        ->set('nama', 'Republik Indonesia')
        ->call('save');

    expect($negara->fresh()->nama)->toBe('Republik Indonesia');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $negara->id)
        ->call('delete');

    expect(Negara::find($negara->id))->toBeNull();
});

it('searches negara by nama or kode', function () {
    $admin = adminUser();
    Negara::factory()->create(['nama' => 'Indonesia', 'kode' => 'ID']);
    Negara::factory()->create(['nama' => 'Malaysia', 'kode' => 'MY']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('search', 'Indonesia')
        ->assertSee('Indonesia')
        ->assertDontSee('Malaysia');
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.sistem.negara.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('imports negara rows and reports the success count', function () {
    $admin = adminUser();

    $file = makeNegaraImportFile([
        ['ID', 'Indonesia'],
        ['MY', 'Malaysia'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 2)
        ->assertSet('result.skip_count', 0);

    expect(Negara::where('kode', 'ID')->exists())->toBeTrue();
    expect(Negara::where('kode', 'MY')->exists())->toBeTrue();
});

it('skips a row whose kode already exists and records the reason', function () {
    $admin = adminUser();
    Negara::factory()->create(['kode' => 'ID']);

    $file = makeNegaraImportFile([
        ['ID', 'Indonesia'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 1);

    expect(Negara::where('kode', 'ID')->count())->toBe(1);
});
