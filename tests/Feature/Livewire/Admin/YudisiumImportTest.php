<?php

use App\Livewire\Admin\Yudisium\Import;
use App\Models\JenisKeluar;
use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\Yudisium;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa diparse
 * PhpSpreadsheet) dengan urutan kolom persis YudisiumController::import. Dibungkus lewat
 * UploadedFile::fake()->createWithContent supaya hasilnya instance Illuminate\Http\Testing\File
 * — Livewire test harness butuh properti publik ->name.
 */
function makeYudisiumImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['header'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'yudisium_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.yudisium.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.akademik.yudisium.template'));
});

it('shows download template and import links on the yudisium index page', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.yudisium'))
        ->assertOk()
        ->assertSee(route('admin.akademik.yudisium.template'))
        ->assertSee(route('admin.akademik.yudisium.import'));
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.yudisium.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('creates a yudisium row from a valid data row', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000001']);
    JenisKeluar::factory()->create(['nama' => 'Lulus']);

    $file = makeYudisiumImportFile([
        ['2024000001', 'Lulus', '2026-05-04', '009/IJZ/UNMI/V/2026', '0909/UNMI/VI/2026', '2026-04-15', '3.85', 'Sistem Informasi Akademik', 'Catatan'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.errors', []);

    $yudisium = Yudisium::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect($yudisium->tgl_keluar)->toBe('2026-05-04');
    expect($yudisium->no_ijazah)->toBe('009/IJZ/UNMI/V/2026');
    expect($yudisium->no_sk_yudisium)->toBe('0909/UNMI/VI/2026');
    expect($yudisium->tanggal_sk_yudisium)->toBe('2026-04-15');
    expect((float) $yudisium->ipk)->toBe(3.85);
    expect($yudisium->judul_skripsi)->toBe('Sistem Informasi Akademik');
    expect($yudisium->keterangan)->toBe('Catatan');
});

it('leaves optional columns null when left blank', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000002']);
    JenisKeluar::factory()->create(['nama' => 'Lulus']);

    $file = makeYudisiumImportFile([
        ['2024000002', 'Lulus', '', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $yudisium = Yudisium::whereHas('mahasiswa', fn ($q) => $q->where('nim', '2024000002'))->firstOrFail();
    expect($yudisium->ipk)->toBeNull();
    expect($yudisium->no_ijazah)->toBeNull();
    expect($yudisium->judul_skripsi)->toBeNull();
});

it('records an error when the mahasiswa nim cannot be found and shows a copy-log button', function () {
    $admin = adminUser();
    JenisKeluar::factory()->create(['nama' => 'Lulus']);

    $file = makeYudisiumImportFile([
        ['9999999999', 'Lulus', '', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSee('Salin Log');

    expect($component->get('result')['errors'])->not->toBeEmpty();
    expect($component->get('result')['errors'][0])->toContain("NIM '9999999999' tidak ditemukan");
});

it('records an error when the jenis keluar name cannot be found', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000003']);

    $file = makeYudisiumImportFile([
        ['2024000003', 'Jenis Yang Tidak Ada', '', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Jenis Keluar 'Jenis Yang Tidak Ada' tidak ditemukan");
    expect(Yudisium::count())->toBe(0);
});

it('rejects a duplicate mahasiswa + jenis keluar combination instead of updating it', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000004']);
    $jenisKeluar = JenisKeluar::factory()->create(['nama' => 'Lulus']);
    Yudisium::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_jenis_keluar' => $jenisKeluar->id]);

    $file = makeYudisiumImportFile([
        ['2024000004', 'Lulus', '', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('sudah memiliki data yudisium');
    expect(Yudisium::where('id_mahasiswa', $mahasiswa->id)->count())->toBe(1);
});

it('rejects an ipk value outside the 0-4 range', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000005']);
    JenisKeluar::factory()->create(['nama' => 'Lulus']);

    $file = makeYudisiumImportFile([
        ['2024000005', 'Lulus', '', '', '', '', '4.50', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('harus di antara 0 dan 4.00');
    expect(Yudisium::count())->toBe(0);
});

it('parses an excel serial date number into a Y-m-d string', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000006']);
    JenisKeluar::factory()->create(['nama' => 'Lulus']);

    // Serial Excel untuk 2026-05-04.
    $file = makeYudisiumImportFile([
        ['2024000006', 'Lulus', 46146, '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $yudisium = Yudisium::whereHas('mahasiswa', fn ($q) => $q->where('nim', '2024000006'))->firstOrFail();
    expect($yudisium->tgl_keluar)->toBe('2026-05-04');
});

it('enforces prodi scope: hides an out-of-scope mahasiswa behind an error row', function () {
    $admin = adminUser('admin_akademik');
    $allowedProdi = Prodi::factory()->create();
    scopeAdminToProdi($admin, $allowedProdi->id);

    $luarScope = Mahasiswa::factory()->create(['nim' => '2024000007', 'id_prodi' => Prodi::factory()->create()->id]);
    JenisKeluar::factory()->create(['nama' => 'Lulus']);

    $file = makeYudisiumImportFile([
        ['2024000007', 'Lulus', '', '', '', '', '', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('di luar scope');
    expect(Yudisium::where('id_mahasiswa', $luarScope->id)->count())->toBe(0);
});

it('processes multiple rows independently, isolating one bad row from the rest', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000008']);
    Mahasiswa::factory()->create(['nim' => '2024000009']);
    JenisKeluar::factory()->create(['nama' => 'Lulus']);

    $file = makeYudisiumImportFile([
        ['2024000008', 'Lulus', '', '', '', '', '', '', ''],
        ['0000000000', 'Lulus', '', '', '', '', '', '', ''],
        ['2024000009', 'Lulus', '', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 2);

    expect(Yudisium::count())->toBe(2);
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.yudisium.import'))->assertRedirect(route('login'));
});
