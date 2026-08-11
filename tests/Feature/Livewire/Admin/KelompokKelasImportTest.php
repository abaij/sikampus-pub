<?php

use App\Livewire\Admin\KelompokKelas\Import;
use App\Models\KelompokKelas;
use App\Models\Prodi;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa diparse
 * PhpSpreadsheet) dengan urutan kolom persis KelompokKelasController::import
 * (kolom A = nama, kolom B = kode prodi).
 */
function makeKelompokKelasImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Nama*', 'Kode Prodi'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'kelompok_kelas_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.administrasi.kelas-mahasiswa.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.administrasi.kelas-mahasiswa.template'));
});

it('shows download template and import links on the kelompok kelas index page', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.administrasi.kelas-mahasiswa'))
        ->assertOk()
        ->assertSee(route('admin.administrasi.kelas-mahasiswa.template'))
        ->assertSee(route('admin.administrasi.kelas-mahasiswa.import'));
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.administrasi.kelas-mahasiswa.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('imports kelompok kelas rows and reports the success count', function () {
    $admin = adminUser();

    $file = makeKelompokKelasImportFile([
        ['Kelompok A'],
        ['Kelompok B'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 2)
        ->assertSet('result.skip_count', 0);

    expect(KelompokKelas::where('nama', 'Kelompok A')->exists())->toBeTrue();
    expect(KelompokKelas::where('nama', 'Kelompok B')->exists())->toBeTrue();
});

it('imports a row with a kode prodi and sets id_prodi accordingly', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create(['kode' => 'TI']);

    $file = makeKelompokKelasImportFile([
        ['Kelompok TI', 'TI'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $kelompokKelas = KelompokKelas::where('nama', 'Kelompok TI')->firstOrFail();
    expect($kelompokKelas->id_prodi)->toBe($prodi->id);
});

it('leaves id_prodi null when the kode prodi column is left blank', function () {
    $admin = adminUser();

    $file = makeKelompokKelasImportFile([
        ['Kelompok Tanpa Prodi', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $kelompokKelas = KelompokKelas::where('nama', 'Kelompok Tanpa Prodi')->firstOrFail();
    expect($kelompokKelas->id_prodi)->toBeNull();
});

it('records an error when the kode prodi cannot be found and does not create the row', function () {
    $admin = adminUser();

    $file = makeKelompokKelasImportFile([
        ['Kelompok Salah Prodi', 'TIDAK-ADA'],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
    expect(KelompokKelas::where('nama', 'Kelompok Salah Prodi')->exists())->toBeFalse();
});

it('includes the kode prodi column in the downloaded template', function () {
    $admin = adminUser();

    $response = $this->actingAs($admin)->get(route('admin.administrasi.kelas-mahasiswa.template'));
    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'kelompok_kelas_template_').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $rows = (new PhpOffice\PhpSpreadsheet\Reader\Xlsx)->load($path)->getActiveSheet()->toArray();
    unlink($path);

    expect($rows[0])->toBe(['Nama*', 'Kode Prodi']);
});

it('skips a row whose nama already exists and records the reason', function () {
    $admin = adminUser();
    KelompokKelas::factory()->create(['nama' => 'Kelompok Existing']);

    $file = makeKelompokKelasImportFile([
        ['Kelompok Existing'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 1);

    expect(KelompokKelas::where('nama', 'Kelompok Existing')->count())->toBe(1);
});

it('records an error when nama is blank in the row', function () {
    $admin = adminUser();

    // Baris ' ' (bukan '' murni) supaya lolos filter baris-kosong controller (array_filter
    // menganggap ' ' truthy) tapi tetap gagal validasi nama setelah di-trim.
    $file = makeKelompokKelasImportFile([
        [' '],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
});

it('rejects duplicate nama rows within the same file', function () {
    $admin = adminUser();

    $file = makeKelompokKelasImportFile([
        ['Kelompok Sama'],
        ['Kelompok Sama'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    expect(KelompokKelas::where('nama', 'Kelompok Sama')->count())->toBe(1);
});

it('blocks a view-only akademik admin from importing in granular permission mode', function () {
    config(['access.granular_permissions' => true]);
    $admin = adminUser('admin_akademik');

    $this->actingAs($admin)
        ->get(route('admin.administrasi.kelas-mahasiswa.import'))
        ->assertStatus(403);
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.administrasi.kelas-mahasiswa.import'))
        ->assertRedirect(route('login'));
});
