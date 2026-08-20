<?php

use App\Livewire\Admin\TugasAkhir\Import;
use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\Semester;
use App\Models\TugasAkhir;
use App\Models\TugasAkhirPembimbing;
use App\Models\UjianSidang;
use App\Models\UjianSidangPenguji;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

it('updates an existing mahasiswa + semester combination instead of rejecting it', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000005']);
    $semester = Semester::factory()->create(['kode' => '20251']);
    $existing = TugasAkhir::create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'judul' => 'Judul lama',
        'status' => 'submitted',
        'is_proposal' => true,
    ]);

    $file = makeTugasAkhirImportFile([
        ['2024000005', '20251', 'Judul baru', 'approved', '', '', '', '', 'false'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.updated_count', 1)
        ->assertSet('result.errors', []);

    expect(TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->count())->toBe(1);
    $existing->refresh();
    expect($existing->judul)->toBe('Judul baru');
    expect($existing->status)->toBe('approved');
    expect($existing->is_proposal)->toBeFalse();
});

it('preserves existing optional field values when the corresponding cell is left blank on update', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000018']);
    $semester = Semester::factory()->create(['kode' => '20251']);
    $existing = TugasAkhir::create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'judul' => 'Judul lama',
        'judul_en' => 'Old English Title',
        'topik' => 'Topik Lama',
        'deskripsi' => 'Deskripsi lama',
        'status' => 'approved',
        'is_proposal' => false,
        'file' => 'tugas-akhir/lama.pdf',
    ]);

    // Semua kolom opsional dikosongkan — hanya Judul yang diubah.
    $file = makeTugasAkhirImportFile([
        ['2024000018', '20251', 'Judul baru saja', '', '', '', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.updated_count', 1);

    $existing->refresh();
    expect($existing->judul)->toBe('Judul baru saja');
    expect($existing->judul_en)->toBe('Old English Title');
    expect($existing->topik)->toBe('Topik Lama');
    expect($existing->deskripsi)->toBe('Deskripsi lama');
    expect($existing->status)->toBe('approved');
    expect($existing->is_proposal)->toBeFalse();
    expect($existing->file)->toBe('tugas-akhir/lama.pdf');
});

it('syncs pembimbing on update when the column is filled, without touching it when blank', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000019']);
    $semester = Semester::factory()->create(['kode' => '20251']);
    $existingTugasAkhir = TugasAkhir::create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'judul' => 'Judul',
        'status' => 'submitted',
    ]);
    $dosenLama = Dosen::factory()->create(['kode_dosen' => 'DSN020']);
    TugasAkhirPembimbing::create([
        'id_tugas_akhir' => $existingTugasAkhir->id,
        'id_dosen' => $dosenLama->id,
        'peran' => 'pembimbing',
    ]);

    // Re-import pertama: kolom pembimbing dikosongkan — pembimbing lama harus tetap ada.
    $fileBlank = makeTugasAkhirImportFile([
        ['2024000019', '20251', 'Judul', '', '', '', '', '', '', '', '', ''],
    ]);
    Livewire::actingAs($admin)->test(Import::class)->set('file', $fileBlank)->call('import');
    expect(TugasAkhirPembimbing::where('id_tugas_akhir', $existingTugasAkhir->id)->where('peran', 'pembimbing')->pluck('id_dosen')->all())
        ->toBe([$dosenLama->id]);

    // Re-import kedua: kolom pembimbing diisi dengan dosen lain — daftar disinkronkan (dosen lama hilang).
    $dosenBaru = Dosen::factory()->create(['kode_dosen' => 'DSN021']);
    $fileFilled = makeTugasAkhirImportFile([
        ['2024000019', '20251', 'Judul', '', '', '', '', '', '', 'DSN021', ''],
    ]);
    Livewire::actingAs($admin)->test(Import::class)->set('file', $fileFilled)->call('import');

    $pembimbingSekarang = TugasAkhirPembimbing::where('id_tugas_akhir', $existingTugasAkhir->id)
        ->where('peran', 'pembimbing')
        ->pluck('id_dosen')
        ->all();
    expect($pembimbingSekarang)->toBe([$dosenBaru->id]);
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

it('creates pembimbing and penguji rows from comma-separated dosen codes', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000009']);
    Semester::factory()->create(['kode' => '20251']);
    $pembimbing1 = Dosen::factory()->create(['kode_dosen' => 'DSN001']);
    $pembimbing2 = Dosen::factory()->create(['kode_dosen' => 'DSN002']);
    $penguji1 = Dosen::factory()->create(['kode_dosen' => 'DSN003']);

    $file = makeTugasAkhirImportFile([
        ['2024000009', '20251', 'Judul', '', '', '', '', '', '', 'DSN001, DSN002', 'DSN003'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.errors', []);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();

    $pembimbingRows = TugasAkhirPembimbing::where('id_tugas_akhir', $tugasAkhir->id)
        ->where('peran', 'pembimbing')
        ->pluck('id_dosen');
    expect($pembimbingRows->sort()->values()->all())
        ->toBe(collect([$pembimbing1->id, $pembimbing2->id])->sort()->values()->all());

    $pengujiRows = TugasAkhirPembimbing::where('id_tugas_akhir', $tugasAkhir->id)
        ->where('peran', 'penguji')
        ->pluck('id_dosen');
    expect($pengujiRows->all())->toBe([$penguji1->id]);
});

it('allows the same dosen to be both pembimbing and penguji on one tugas akhir', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000010']);
    Semester::factory()->create(['kode' => '20251']);
    $dosen = Dosen::factory()->create(['kode_dosen' => 'DSN010']);

    $file = makeTugasAkhirImportFile([
        ['2024000010', '20251', 'Judul', '', '', '', '', '', '', 'DSN010', 'DSN010'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect(TugasAkhirPembimbing::where('id_tugas_akhir', $tugasAkhir->id)->where('id_dosen', $dosen->id)->count())->toBe(2);
    expect(TugasAkhirPembimbing::where('id_tugas_akhir', $tugasAkhir->id)->pluck('peran')->sort()->values()->all())->toBe(['pembimbing', 'penguji']);
});

it('dedupes a repeated dosen code within the same pembimbing cell', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000011']);
    Semester::factory()->create(['kode' => '20251']);
    Dosen::factory()->create(['kode_dosen' => 'DSN011']);

    $file = makeTugasAkhirImportFile([
        ['2024000011', '20251', 'Judul', '', '', '', '', '', '', 'DSN011, DSN011', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect(TugasAkhirPembimbing::where('id_tugas_akhir', $tugasAkhir->id)->count())->toBe(1);
});

it('fails the whole row when a pembimbing dosen code is not found, creating nothing', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000012']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000012', '20251', 'Judul', '', '', '', '', '', '', 'TIDAK-ADA', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Dosen pembimbing — kode 'TIDAK-ADA' tidak ditemukan");
    expect(TugasAkhir::count())->toBe(0);
    expect(TugasAkhirPembimbing::count())->toBe(0);
});

it('fails the whole row when a penguji dosen code is not found, creating nothing', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000013']);
    Semester::factory()->create(['kode' => '20251']);
    Dosen::factory()->create(['kode_dosen' => 'DSN013']);

    $file = makeTugasAkhirImportFile([
        ['2024000013', '20251', 'Judul', '', '', '', '', '', '', 'DSN013', 'TIDAK-ADA'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Dosen penguji — kode 'TIDAK-ADA' tidak ditemukan");
    expect(TugasAkhir::count())->toBe(0);
    expect(TugasAkhirPembimbing::count())->toBe(0);
});

it('leaves pembimbing empty when the columns are blank', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000014']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000014', '20251', 'Judul', '', '', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect(TugasAkhirPembimbing::where('id_tugas_akhir', $tugasAkhir->id)->count())->toBe(0);
});

it('links a file path that already exists on the public disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('tugas-akhir/existing.pdf', 'isi-file-dummy');

    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000015']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000015', '20251', 'Judul', '', '', '', '', '', '', '', '', 'tugas-akhir/existing.pdf'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.errors', []);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect($tugasAkhir->file)->toBe('tugas-akhir/existing.pdf');
});

it('stores the file path as-is even when it does not exist on the public disk yet', function () {
    // Sengaja tidak melakukan Storage::disk('public')->exists() check — path boleh menunjuk ke
    // berkas yang belum diunggah saat import berjalan (mis. migrasi bertahap).
    Storage::fake('public');

    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000016']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000016', '20251', 'Judul', '', '', '', '', '', '', '', '', 'tugas-akhir/belum-diunggah.pdf'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.errors', []);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect($tugasAkhir->file)->toBe('tugas-akhir/belum-diunggah.pdf');
});

it('leaves file null when the column is blank', function () {
    Storage::fake('public');

    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000017']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000017', '20251', 'Judul', '', '', '', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.errors', []);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect($tugasAkhir->file)->toBeNull();
});

it('creates an ujian sidang row alongside tugas akhir when the sidang semester column is filled', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000020']);
    Semester::factory()->create(['kode' => '20251']);
    $semesterSidang = Semester::factory()->create(['kode' => '20252']);

    $file = makeTugasAkhirImportFile([
        ['2024000020', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '2026-01-10', '2026-01-11', 'submitted', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.ujian_sidang_success_count', 1)
        ->assertSet('result.errors', []);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    $ujianSidang = UjianSidang::where('id_tugas_akhir', $tugasAkhir->id)->firstOrFail();
    expect($ujianSidang->id_semester)->toBe($semesterSidang->id);
    expect($ujianSidang->status)->toBe('submitted');
    expect($ujianSidang->tanggal_ujian_mulai->format('Y-m-d'))->toBe('2026-01-10');
    expect($ujianSidang->tanggal_ujian_selesai->format('Y-m-d'))->toBe('2026-01-11');
    expect(UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->count())->toBe(0);
});

it('does not touch ujian sidang data when the sidang semester column is blank', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000021']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000021', '20251', 'Judul', '', '', '', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.ujian_sidang_success_count', 0);

    $tugasAkhir = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect(UjianSidang::where('id_tugas_akhir', $tugasAkhir->id)->count())->toBe(0);
});

it('fails the whole row when the ujian sidang semester code is not found', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000022']);
    Semester::factory()->create(['kode' => '20251']);

    $file = makeTugasAkhirImportFile([
        ['2024000022', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '99999'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Semester ujian sidang dengan kode '99999' tidak ditemukan");
    expect(TugasAkhir::count())->toBe(0);
});

it('fails the whole row when the ujian sidang status value is invalid', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000023']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);

    // 'returned' valid untuk status tugas_akhir tapi TIDAK valid untuk ujian_sidang.
    $file = makeTugasAkhirImportFile([
        ['2024000023', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', 'returned', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Status ujian sidang 'returned' tidak valid");
    expect(TugasAkhir::count())->toBe(0);
});

it('fails the whole row when ujian sidang tanggal selesai is before tanggal mulai', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000024']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);

    $file = makeTugasAkhirImportFile([
        ['2024000024', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '2026-02-10', '2026-02-01', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('Tanggal selesai ujian sidang harus sama atau setelah tanggal mulai');
    expect(TugasAkhir::count())->toBe(0);
});

it('updates an existing ujian sidang and preserves blank optional fields', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000025']);
    $semesterTa = Semester::factory()->create(['kode' => '20251']);
    $semesterSidang = Semester::factory()->create(['kode' => '20252']);
    $tugasAkhir = TugasAkhir::create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semesterTa->id,
        'judul' => 'Judul',
        'status' => 'approved',
    ]);
    $existingUjianSidang = UjianSidang::create([
        'id_tugas_akhir' => $tugasAkhir->id,
        'id_semester' => $semesterSidang->id,
        'tanggal_daftar' => now(),
        'status' => 'approved',
        'tanggal_ujian_mulai' => '2026-01-01 08:00:00',
        'tanggal_ujian_selesai' => '2026-01-01 10:00:00',
    ]);

    // Hanya Status Ujian Sidang yang diisi; tanggal dikosongkan — harus tetap sama.
    $file = makeTugasAkhirImportFile([
        ['2024000025', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', 'submitted', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.ujian_sidang_updated_count', 1)
        ->assertSet('result.errors', []);

    $existingUjianSidang->refresh();
    expect($existingUjianSidang->status)->toBe('submitted');
    expect($existingUjianSidang->tanggal_ujian_mulai->format('Y-m-d H:i'))->toBe('2026-01-01 08:00');
    expect($existingUjianSidang->tanggal_ujian_selesai->format('Y-m-d H:i'))->toBe('2026-01-01 10:00');
});

it('leaves ujian sidang penguji empty when the penguji sidang column is blank', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000026']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);

    $file = makeTugasAkhirImportFile([
        ['2024000026', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.ujian_sidang_success_count', 1)
        ->assertSet('result.errors', []);

    $ujianSidang = UjianSidang::firstOrFail();
    expect(UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->count())->toBe(0);
});

it('creates ujian sidang penguji rows from comma-separated dosen codes', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000027']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);
    $penguji1 = Dosen::factory()->create(['kode_dosen' => 'DSN030']);
    $penguji2 = Dosen::factory()->create(['kode_dosen' => 'DSN031']);

    $file = makeTugasAkhirImportFile([
        ['2024000027', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN030, DSN031'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.errors', []);

    $ujianSidang = UjianSidang::firstOrFail();
    $pengujiIds = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->pluck('id_dosen');
    expect($pengujiIds->sort()->values()->all())
        ->toBe(collect([$penguji1->id, $penguji2->id])->sort()->values()->all());
});

it('fails the whole row when a penguji sidang dosen code is not found, creating nothing', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000028']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);

    $file = makeTugasAkhirImportFile([
        ['2024000028', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'TIDAK-ADA'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Dosen penguji sidang — kode 'TIDAK-ADA' tidak ditemukan");
    expect(TugasAkhir::count())->toBe(0);
    expect(UjianSidang::count())->toBe(0);
});

it('syncs ujian sidang penguji on update when the column is filled, without touching it when blank', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000029']);
    $semesterTa = Semester::factory()->create(['kode' => '20251']);
    $semesterSidang = Semester::factory()->create(['kode' => '20252']);
    $tugasAkhir = TugasAkhir::create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semesterTa->id,
        'judul' => 'Judul',
        'status' => 'approved',
    ]);
    $ujianSidang = UjianSidang::create([
        'id_tugas_akhir' => $tugasAkhir->id,
        'id_semester' => $semesterSidang->id,
        'tanggal_daftar' => now(),
        'status' => 'draft',
    ]);
    $pengujiLama = Dosen::factory()->create(['kode_dosen' => 'DSN040']);
    UjianSidangPenguji::create([
        'id_ujian_sidang' => $ujianSidang->id,
        'id_dosen' => $pengujiLama->id,
    ]);

    // Re-import pertama: kolom penguji sidang dikosongkan — penguji lama harus tetap ada.
    $fileBlank = makeTugasAkhirImportFile([
        ['2024000029', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252'],
    ]);
    Livewire::actingAs($admin)->test(Import::class)->set('file', $fileBlank)->call('import');
    expect(UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->pluck('id_dosen')->all())
        ->toBe([$pengujiLama->id]);

    // Re-import kedua: kolom penguji sidang diisi dengan dosen lain — daftar disinkronkan.
    $pengujiBaru = Dosen::factory()->create(['kode_dosen' => 'DSN041']);
    $fileFilled = makeTugasAkhirImportFile([
        ['2024000029', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN041'],
    ]);
    Livewire::actingAs($admin)->test(Import::class)->set('file', $fileFilled)->call('import');

    $pengujiSekarang = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->pluck('id_dosen')->all();
    expect($pengujiSekarang)->toBe([$pengujiBaru->id]);
});

it('imports nilai, catatan, and ketua for ujian sidang penguji aligned by position', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000030']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);
    $penguji1 = Dosen::factory()->create(['kode_dosen' => 'DSN050']);
    $penguji2 = Dosen::factory()->create(['kode_dosen' => 'DSN051']);

    $file = makeTugasAkhirImportFile([
        ['2024000030', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN050, DSN051', '85, 78', 'Penguasaan baik|Perlu revisi kecil', 'DSN050'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.errors', []);

    $ujianSidang = UjianSidang::firstOrFail();
    $row1 = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->where('id_dosen', $penguji1->id)->firstOrFail();
    $row2 = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->where('id_dosen', $penguji2->id)->firstOrFail();

    expect((float) $row1->nilai)->toBe(85.0);
    expect($row1->catatan)->toBe('Penguasaan baik');
    expect($row1->is_ketua)->toBeTrue();

    expect((float) $row2->nilai)->toBe(78.0);
    expect($row2->catatan)->toBe('Perlu revisi kecil');
    expect($row2->is_ketua)->toBeFalse();
});

it('leaves a penguji sidang slot null when its nilai/catatan position is blank', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000031']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);
    $penguji1 = Dosen::factory()->create(['kode_dosen' => 'DSN052']);
    $penguji2 = Dosen::factory()->create(['kode_dosen' => 'DSN053']);

    // Nilai hanya diisi untuk penguji pertama; slot kedua sengaja dikosongkan.
    $file = makeTugasAkhirImportFile([
        ['2024000031', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN052, DSN053', '85'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.errors', []);

    $ujianSidang = UjianSidang::firstOrFail();
    $row1 = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->where('id_dosen', $penguji1->id)->firstOrFail();
    $row2 = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->where('id_dosen', $penguji2->id)->firstOrFail();

    expect((float) $row1->nilai)->toBe(85.0);
    expect($row2->nilai)->toBeNull();
    expect($row1->is_ketua)->toBeFalse();
    expect($row2->is_ketua)->toBeFalse();
});

it('fails the whole row when a penguji sidang nilai is not a valid number', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000032']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);
    Dosen::factory()->create(['kode_dosen' => 'DSN054']);

    $file = makeTugasAkhirImportFile([
        ['2024000032', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN054', 'abc'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Nilai penguji sidang untuk kode 'DSN054' tidak valid");
    expect(TugasAkhir::count())->toBe(0);
    expect(UjianSidang::count())->toBe(0);
});

it('fails the whole row when the ketua sidang code is not among the penguji sidang codes', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000033']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);
    Dosen::factory()->create(['kode_dosen' => 'DSN055']);
    Dosen::factory()->create(['kode_dosen' => 'DSN056']);

    $file = makeTugasAkhirImportFile([
        ['2024000033', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN055', '', '', 'DSN056'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain("Kode Dosen Ketua Sidang 'DSN056' harus salah satu dari Kode Dosen Penguji Sidang");
    expect(TugasAkhir::count())->toBe(0);
});

it('fails the whole row when the ketua sidang code is filled but the penguji sidang column is blank', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000034']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);
    Dosen::factory()->create(['kode_dosen' => 'DSN057']);

    $file = makeTugasAkhirImportFile([
        ['2024000034', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', '', '', '', 'DSN057'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('Kode Dosen Ketua Sidang diisi tapi Kode Dosen Penguji Sidang kosong');
    expect(TugasAkhir::count())->toBe(0);
});

it('fails the whole row when the penguji sidang codes contain a duplicate', function () {
    $admin = adminUser();
    Mahasiswa::factory()->create(['nim' => '2024000035']);
    Semester::factory()->create(['kode' => '20251']);
    Semester::factory()->create(['kode' => '20252']);
    Dosen::factory()->create(['kode_dosen' => 'DSN058']);

    $file = makeTugasAkhirImportFile([
        ['2024000035', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN058, DSN058'],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0);

    expect($component->get('result')['errors'][0])->toContain('Kode Dosen Penguji Sidang mengandung kode yang duplikat');
    expect(TugasAkhir::count())->toBe(0);
});

it('preserves nilai, catatan, and is_ketua per dosen on update when their slots are left blank', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create(['nim' => '2024000036']);
    $semesterTa = Semester::factory()->create(['kode' => '20251']);
    $semesterSidang = Semester::factory()->create(['kode' => '20252']);
    $tugasAkhir = TugasAkhir::create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semesterTa->id,
        'judul' => 'Judul',
        'status' => 'approved',
    ]);
    $ujianSidang = UjianSidang::create([
        'id_tugas_akhir' => $tugasAkhir->id,
        'id_semester' => $semesterSidang->id,
        'tanggal_daftar' => now(),
        'status' => 'draft',
    ]);
    $penguji = Dosen::factory()->create(['kode_dosen' => 'DSN059']);
    UjianSidangPenguji::create([
        'id_ujian_sidang' => $ujianSidang->id,
        'id_dosen' => $penguji->id,
        'nilai' => 80,
        'catatan' => 'Catatan lama',
        'is_ketua' => true,
    ]);

    // Re-import pertama: kode penguji diisi (supaya sync jalan) tapi nilai/catatan/ketua dikosongkan.
    $fileBlank = makeTugasAkhirImportFile([
        ['2024000036', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN059'],
    ]);
    Livewire::actingAs($admin)->test(Import::class)->set('file', $fileBlank)->call('import');

    $rowAfterBlank = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->where('id_dosen', $penguji->id)->firstOrFail();
    expect((float) $rowAfterBlank->nilai)->toBe(80.0);
    expect($rowAfterBlank->catatan)->toBe('Catatan lama');
    expect($rowAfterBlank->is_ketua)->toBeTrue();

    // Re-import kedua: nilai & catatan diisi baru, tapi kolom ketua tetap dikosongkan -> is_ketua tidak berubah.
    $fileFilled = makeTugasAkhirImportFile([
        ['2024000036', '20251', 'Judul', '', '', '', '', '', '', '', '', '', '20252', '', '', '', 'DSN059', '95', 'Catatan baru'],
    ]);
    Livewire::actingAs($admin)->test(Import::class)->set('file', $fileFilled)->call('import');

    $rowAfterFilled = UjianSidangPenguji::where('id_ujian_sidang', $ujianSidang->id)->where('id_dosen', $penguji->id)->firstOrFail();
    expect((float) $rowAfterFilled->nilai)->toBe(95.0);
    expect($rowAfterFilled->catatan)->toBe('Catatan baru');
    expect($rowAfterFilled->is_ketua)->toBeTrue(); // tidak berubah karena kolom ketua dikosongkan
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.tugas-akhir.import'))->assertRedirect(route('login'));
});
