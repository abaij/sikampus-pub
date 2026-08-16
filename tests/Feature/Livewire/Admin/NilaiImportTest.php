<?php

use App\Livewire\Admin\Nilai\Import;
use App\Models\Kelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Nilai;
use App\Models\Prodi;
use App\Models\Semester;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa
 * diparse PhpSpreadsheet) dengan urutan kolom persis NilaiController::import.
 * Dibungkus lewat UploadedFile::fake()->createWithContent supaya hasilnya instance
 * Illuminate\Http\Testing\File — Livewire test harness butuh properti publik ->name.
 */
function makeNilaiImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['header'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'nilai_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

function makeNilaiImportKrs(Prodi $prodi, string $kodeMatkul, string $kodeSemester, string $nim): Krs
{
    $mahasiswa = Mahasiswa::factory()->create(['nim' => $nim, 'id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => $kodeMatkul, 'sks' => 3]);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $semester = Semester::factory()->create(['kode' => $kodeSemester]);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_prodi' => $prodi->id,
        'id_semester' => $semester->id,
    ]);

    return Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id]);
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.akademik.nilai.template'));
});

it('shows download template and import links on the nilai index page', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai'))
        ->assertOk()
        ->assertSee(route('admin.akademik.nilai.template'))
        ->assertSee(route('admin.akademik.nilai.import'));
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('creates a new nilai row for a krs that has none yet', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $krs = makeNilaiImportKrs($prodi, 'MK001', '20241', '2024000001');

    $file = makeNilaiImportFile([
        ['2024000001', 'MK001', '20241', '85.5', 'A', 'true'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.updated_count', 0);

    $nilai = Nilai::where('id_krs', $krs->id)->firstOrFail();
    expect((float) $nilai->angka_mutu)->toBe(85.5);
    expect($nilai->huruf_mutu)->toBe('A');
    expect((bool) $nilai->is_final)->toBeTrue();
    expect($nilai->sks)->toBe(3);
});

it('updates an existing nilai row instead of creating a duplicate', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $krs = makeNilaiImportKrs($prodi, 'MK002', '20242', '2024000002');
    $existing = Nilai::factory()->create(['id_krs' => $krs->id, 'huruf_mutu' => 'C', 'angka_mutu' => 2]);

    $file = makeNilaiImportFile([
        ['2024000002', 'MK002', '20242', '90', 'A', 'true'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.updated_count', 1);

    expect(Nilai::where('id_krs', $krs->id)->count())->toBe(1);
    expect($existing->fresh()->huruf_mutu)->toBe('A');
    expect((float) $existing->fresh()->angka_mutu)->toBe(90.0);
});

it('records an error when the mahasiswa nim cannot be found', function () {
    $file = makeNilaiImportFile([
        ['9999999999', 'MK001', '20241', '', '', ''],
    ]);

    $admin = adminUser();

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
});

it('records an error when no krs exists for the mahasiswa and mata kuliah', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000003', 'id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK003']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $semester = Semester::factory()->create(['kode' => '20243']);
    // Kelas ada, tapi mahasiswa belum punya KRS untuknya.
    Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_prodi' => $prodi->id,
        'id_semester' => $semester->id,
    ]);

    $file = makeNilaiImportFile([
        ['2024000003', 'MK003', '20243', '', '', ''],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
    expect(Mahasiswa::where('nim', '2024000003')->exists())->toBeTrue();
});

it('mentions the prodi of the matched kelas when the krs lookup fails', function () {
    $admin = adminUser();
    $prodiMahasiswa = Prodi::factory()->create();
    $prodiKelas = Prodi::factory()->create(['nama' => 'Prodi Lain']);
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000004', 'id_prodi' => $prodiMahasiswa->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodiKelas->id, 'kode' => 'MK004']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $semester = Semester::factory()->create(['kode' => '20244']);
    // Kelas hanya ada di prodi lain (bukan prodi mahasiswa) — fallback query di controller/component
    // akan tetap menemukannya walau tanpa filter prodi, tapi mahasiswa tidak punya KRS untuknya.
    Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_prodi' => $prodiKelas->id,
        'id_semester' => $semester->id,
    ]);

    $file = makeNilaiImportFile([
        ['2024000004', 'MK004', '20244', '', '', ''],
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

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.nilai.import'))
        ->assertRedirect(route('login'));
});
