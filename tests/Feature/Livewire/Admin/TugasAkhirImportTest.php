<?php

use App\Livewire\Admin\TugasAkhir\Import;
use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\Semester;
use App\Models\TugasAkhir;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa diparse
 * PhpSpreadsheet) dengan urutan kolom persis TugasAkhirController::import. Dibungkus lewat
 * UploadedFile::fake()->createWithContent supaya hasilnya instance Illuminate\Http\Testing\File
 * — Livewire test harness butuh properti publik ->name.
 */
function makeTugasAkhirImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['header'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'tugas_akhir_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.tugas-akhir.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.akademik.tugas-akhir.template'));
});

it('shows download template and import links on the tugas akhir index page', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.tugas-akhir'))
        ->assertOk()
        ->assertSee(route('admin.akademik.tugas-akhir.template'))
        ->assertSee(route('admin.akademik.tugas-akhir.import'));
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.tugas-akhir.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('creates a tugas akhir row from a valid data row', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000001']);
    $semester = Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000001', '20251', 'Sistem Informasi Akademik', 'approved', 'Academic Information System', 'RPL', 'Software Engineering', 'Deskripsi lengkap', 'false'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.errors', []);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect($tugasAkhir->id_semester)->toBe($semester->id);
    expect($tugasAkhir->judul)->toBe('Sistem Informasi Akademik');
    expect($tugasAkhir->status)->toBe('approved');
    expect($tugasAkhir->judul_en)->toBe('Academic Information System');
    expect($tugasAkhir->topik)->toBe('RPL');
    expect($tugasAkhir->topik_en)->toBe('Software Engineering');
    expect($tugasAkhir->deskripsi)->toBe('Deskripsi lengkap');
    expect($tugasAkhir->is_proposal)->toBeFalse();
});

it('defaults status to submitted and is_proposal to true when left blank', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000002']);
    Semester::factory()->create(['kode' => '20252']);

    $file = makeTugasAkhirImportFile([
        ['2024000002', '20252', 'Judul Minimal', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $tugasAkhir = TugasAkhir::whereHas('mahasiswa', fn ($q) => $q->where('nim', '2024000002'))->firstOrFail();
    expect($tugasAkhir->status)->toBe('submitted');
    expect($tugasAkhir->is_proposal)->toBeTrue();
    expect($tugasAkhir->judul_en)->toBeNull();
    expect($tugasAkhir->deskripsi)->toBeNull();
});

it('records an error when the mahasiswa nim cannot be found and shows a copy-log button', function () {
    $admin = adminUser();
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['9999999999', '20251', 'Judul', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSee('Salin Log');

    expect($component->get('result')['errors'][0])->toContain("NIM '9999999999' tidak ditemukan");
});

it('records an error when the semester kode cannot be found', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000003']);

    $file = makeTugasAkhirImportFile([
        ['2024000003', '99999', 'Judul', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Semester dengan kode '99999' tidak ditemukan");
    expect(TugasAkhir::count())->toBe(0);
});

it('records an error when the status value is invalid', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000004']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000004', '20251', 'Judul', 'lulus_terbaik', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Status 'lulus_terbaik' tidak valid");
    expect(TugasAkhir::count())->toBe(0);
});

it('rejects a duplicate mahasiswa + semester combination instead of updating it', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000005']);
    $semester = Semester::factory()->create(['kode' => '20251']);
    TugasAkhir::create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'judul' => 'Judul lama',
        'status' => 'submitted',
    ]);

    $file = makeTugasAkhirImportFile([
        ['2024000005', '20251', 'Judul baru', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('sudah memiliki data tugas akhir');
    expect(TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->count())->toBe(1);
    expect(TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->first()->judul)->toBe('Judul lama');
});

it('enforces prodi scope: hides an out-of-scope mahasiswa behind an error row', function () {
    $admin = adminUser('admin_akademik');
    $allowedProdi = Prodi::factory()->create();
    scopeAdminToProdi($admin, $allowedProdi->id);

    $luarScope = Mahasiswa::factory()->create(['nim' => '2024000006', 'id_prodi' => Prodi::factory()->create()->id]);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000006', '20251', 'Judul', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('di luar scope');
    expect(TugasAkhir::where('id_mahasiswa', $luarScope->id)->count())->toBe(0);
});

it('processes multiple rows independently, isolating one bad row from the rest', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000007']);
    Mahasiswa::factory()->create(['nim' => '2024000008']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000007', '20251', 'Judul Satu', '', '', '', '', '', ''],
        ['0000000000', '20251', 'Judul Tidak Ada Mahasiswa', '', '', '', '', '', ''],
        ['2024000008', '20251', 'Judul Dua', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 2);

    expect(TugasAkhir::count())->toBe(2);
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.tugas-akhir.import'))->assertRedirect(route('login'));
});
