<?php

use App\Livewire\Admin\Krs\Import;
use App\Models\Kelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Prodi;
use App\Models\Semester;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa
 * diparse PhpSpreadsheet) dengan urutan kolom persis KrsController::import.
 * Dibungkus lewat UploadedFile::fake()->createWithContent supaya hasilnya instance
 * Illuminate\Http\Testing\File — Livewire test harness butuh properti publik ->name.
 */
function makeKrsImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['header'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'krs_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

/**
 * @return array{mahasiswa: Mahasiswa, matkul: Matkul, semester: Semester, kelas: Kelas}
 */
function makeKrsImportFixture(Prodi $prodi, string $nim, string $kodeMatkul, string $kodeSemester, bool $activeSemester = false): array
{
    $mahasiswa = Mahasiswa::factory()->create(['nim' => $nim, 'id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => $kodeMatkul]);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $semester = Semester::factory()->create(['kode' => $kodeSemester, 'is_active' => $activeSemester]);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_prodi' => $prodi->id,
        'id_semester' => $semester->id,
    ]);

    return compact('mahasiswa', 'matkul', 'semester', 'kelas');
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.krs.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.akademik.krs.template'));
});

it('shows download template and import links on the krs index page', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.krs'))
        ->assertOk()
        ->assertSee(route('admin.akademik.krs.template'))
        ->assertSee(route('admin.akademik.krs.import'));
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.krs.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('creates a krs row with pending status by default', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $fixture = makeKrsImportFixture($prodi, '2024000001', 'MK001', '20241');

    $file = makeKrsImportFile([
        ['2024000001', 'MK001', '20241', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.skip_count', 0);

    $krs = Krs::where('id_mahasiswa', $fixture['mahasiswa']->id)->where('id_kelas', $fixture['kelas']->id)->firstOrFail();
    expect($krs->approved_at)->toBeNull();
    expect($krs->approved_by)->toBeNull();
});

it('creates an approved krs row when status is acc', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $fixture = makeKrsImportFixture($prodi, '2024000002', 'MK002', '20242');

    $file = makeKrsImportFile([
        ['2024000002', 'MK002', '20242', 'acc'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $krs = Krs::where('id_mahasiswa', $fixture['mahasiswa']->id)->where('id_kelas', $fixture['kelas']->id)->firstOrFail();
    expect($krs->approved_at)->not->toBeNull();
    expect($krs->approved_by)->not->toBeNull();
});

it('falls back to the active semester when kode semester is left blank', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $fixture = makeKrsImportFixture($prodi, '2024000003', 'MK003', '20243', activeSemester: true);

    $file = makeKrsImportFile([
        ['2024000003', 'MK003', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    expect(Krs::where('id_mahasiswa', $fixture['mahasiswa']->id)->where('id_kelas', $fixture['kelas']->id)->exists())->toBeTrue();
});

it('skips a row whose mahasiswa already has krs for that kelas without logging it as a warning', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $fixture = makeKrsImportFixture($prodi, '2024000004', 'MK004', '20244');
    Krs::factory()->create(['id_mahasiswa' => $fixture['mahasiswa']->id, 'id_kelas' => $fixture['kelas']->id]);

    $file = makeKrsImportFile([
        ['2024000004', 'MK004', '20244', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 1)
        ->assertDontSee('diabaikan');

    expect($component->get('result')['errors'])->toBe([]);
    expect(Krs::where('id_mahasiswa', $fixture['mahasiswa']->id)->where('id_kelas', $fixture['kelas']->id)->count())->toBe(1);
});

it('records an error when the mahasiswa nim cannot be found and shows a copy-log button', function () {
    $admin = adminUser();

    $file = makeKrsImportFile([
        ['9999999999', 'MK001', '20241', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSee('Salin Log');

    expect($component->get('result')['errors'])->not->toBeEmpty();
});

it('records an error for an invalid status value', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    makeKrsImportFixture($prodi, '2024000005', 'MK005', '20245');

    $file = makeKrsImportFile([
        ['2024000005', 'MK005', '20245', 'disetujui'],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
});

it('mentions the prodi of the matched kelas when it is out of the admin scope', function () {
    $prodiMahasiswa = Prodi::factory()->create();
    $prodiKelas = Prodi::factory()->create(['nama' => 'Prodi Lain']);

    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000006', 'id_prodi' => $prodiMahasiswa->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodiKelas->id, 'kode' => 'MK006']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $semester = Semester::factory()->create(['kode' => '20246']);
    // Kelas hanya ada di prodi lain (bukan prodi mahasiswa) — fallback query akan tetap
    // menemukannya walau tanpa filter prodi, tapi kelasnya di luar scope admin.
    Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_prodi' => $prodiKelas->id,
        'id_semester' => $semester->id,
    ]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiMahasiswa->id);

    $file = makeKrsImportFile([
        ['2024000006', 'MK006', '20246', ''],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->toHaveCount(1);
    expect($result['errors'][0])->toContain('Prodi Lain');
});

it('picks the matkul of the mahasiswa prodi when the same kode exists in several prodi', function () {
    $admin = adminUser();

    // Prodi lain sengaja dibuat lebih dulu supaya baris matkul-nya jadi kandidat ->first().
    $prodiLain = Prodi::factory()->create();
    $prodiMhs = Prodi::factory()->create();

    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000007', 'id_prodi' => $prodiMhs->id]);
    $semester = Semester::factory()->create(['kode' => '20247']);

    // Kode mata kuliah yang sama dipakai dua prodi — persis kondisi 'MKW201' di data produksi.
    $matkulLain = Matkul::factory()->create(['id_prodi' => $prodiLain->id, 'kode' => 'MKW777']);
    KurikulumMatkul::factory()->create(['id_matkul' => $matkulLain->id]);

    $matkulMhs = Matkul::factory()->create(['id_prodi' => $prodiMhs->id, 'kode' => 'MKW777']);
    $kmMhs = KurikulumMatkul::factory()->create(['id_matkul' => $matkulMhs->id]);
    // Kelas HANYA ada untuk prodi mahasiswa.
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kmMhs->id,
        'id_prodi' => $prodiMhs->id,
        'id_semester' => $semester->id,
    ]);

    $file = makeKrsImportFile([
        ['2024000007', 'MKW777', '20247', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    expect($component->get('result')['errors'])->toBe([]);
    expect(Krs::where('id_mahasiswa', $mahasiswa->id)->where('id_kelas', $kelas->id)->exists())->toBeTrue();
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.krs.import'))
        ->assertRedirect(route('login'));
});
