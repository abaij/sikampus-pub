<?php

use App\Livewire\Admin\Perkuliahan\Import;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\KurikulumMatkul;
use App\Models\MateriPerkuliahan;
use App\Models\Perkuliahan;
use App\Models\Prodi;
use App\Models\Semester;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa diparse
 * PhpSpreadsheet) dengan urutan kolom persis PerkuliahanController::importSpreadsheet.
 */
function makePerkuliahanImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['header'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'perkuliahan_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.perkuliahan.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.akademik.perkuliahan.template'));
});

it('shows download template and import links on the perkuliahan index page', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.perkuliahan'))
        ->assertOk()
        ->assertSee(route('admin.akademik.perkuliahan.template'))
        ->assertSee(route('admin.akademik.perkuliahan.import'));
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.perkuliahan.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('attaches a realisasi perkuliahan to an existing jadwal found via id_jadwal', function () {
    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '2026-01-15 08:00', '2026-01-15 10:00', 'Pengenalan kuliah', 'Materi sesuai RPS', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.materi_perkuliahan_count', 0)
        ->assertSet('result.skip_count', 0);

    $perkuliahan = Perkuliahan::where('id_jadwal', $jadwal->id)->firstOrFail();
    expect($perkuliahan->materi)->toBe('Pengenalan kuliah');
    expect($perkuliahan->realisasi_materi)->toBe('Materi sesuai RPS');
});

it('resolves the jadwal via natural keys (semester, kode matkul, pertemuan ke-) when id_jadwal is blank', function () {
    $admin = adminUser();
    $semester = Semester::factory()->create(['kode' => '20241']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['kode_matkul' => 'MK001']);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_semester' => $semester->id,
        'id_kelompok_kelas' => null,
    ]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'urutan_pertemuan' => 1]);

    $file = makePerkuliahanImportFile([
        ['', $semester->kode, 'MK001', '', '1', '', '2026-01-15 08:00', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    expect(Perkuliahan::where('id_jadwal', $jadwal->id)->exists())->toBeTrue();
});

it('skips a row whose realisasi already exists for the same jadwal and waktu mulai', function () {
    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();
    Perkuliahan::factory()->create([
        'id_jadwal' => $jadwal->id,
        'waktu_mulai' => '2026-01-15 08:00:00',
    ]);

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '2026-01-15 08:00', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 1);

    expect(Perkuliahan::where('id_jadwal', $jadwal->id)->count())->toBe(1);
});

it('attaches a materi file that already exists in public storage', function () {
    Storage::fake('public');
    Storage::disk('public')->put('materi_perkuliahan/slide.pdf', 'dummy content');

    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '', '', '', '', 'Slide pertemuan 1', 'materi_perkuliahan/slide.pdf'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.materi_perkuliahan_count', 1);

    $materi = MateriPerkuliahan::where('id_jadwal', $jadwal->id)->firstOrFail();
    expect($materi->nama)->toBe('Slide pertemuan 1');
    expect($materi->file)->toBe('materi_perkuliahan/slide.pdf');
});

it('records an error when the materi file path does not exist in storage', function () {
    Storage::fake('public');

    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '', '', '', '', '', 'materi_perkuliahan/tidak-ada.pdf'],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.materi_perkuliahan_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
    expect(MateriPerkuliahan::where('id_jadwal', $jadwal->id)->exists())->toBeFalse();
});

it('records an error when neither waktu mulai nor path materi is filled', function () {
    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '', '', '', '', '', ''],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
});

it('records an error when id_jadwal does not exist', function () {
    $admin = adminUser();

    $file = makePerkuliahanImportFile([
        [999999, '', '', '', '', '', '2026-01-15 08:00', '', '', '', '', ''],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
});

it('scopes import by allowed prodi for a prodi-restricted admin', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $kelasA = Kelas::factory()->create(['id_prodi' => $prodiA->id]);
    $kelasB = Kelas::factory()->create(['id_prodi' => $prodiB->id]);
    $jadwalA = Jadwal::factory()->create(['id_kelas' => $kelasA->id]);
    $jadwalB = Jadwal::factory()->create(['id_kelas' => $kelasB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    $file = makePerkuliahanImportFile([
        [$jadwalA->id, '', '', '', '', '', '2026-01-15 08:00', '', '', '', '', ''],
        [$jadwalB->id, '', '', '', '', '', '2026-01-16 08:00', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.skip_count', 1);

    expect(Perkuliahan::where('id_jadwal', $jadwalA->id)->exists())->toBeTrue();
    expect(Perkuliahan::where('id_jadwal', $jadwalB->id)->exists())->toBeFalse();
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.perkuliahan.import'))
        ->assertRedirect(route('login'));
});
