<?php

use App\Livewire\Admin\Jadwal\Import;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\JenisKuliah;
use App\Models\Kelas;
use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;
use App\Models\Prodi;
use App\Models\Ruangan;
use App\Models\Semester;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa diparse
 * PhpSpreadsheet) dengan urutan kolom persis JadwalController::import.
 */
function makeJadwalImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['header'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'jadwal_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.jadwal.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.akademik.jadwal.template'));
});

it('shows download template and import links on the jadwal index page', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.jadwal'))
        ->assertOk()
        ->assertSee(route('admin.akademik.jadwal.template'))
        ->assertSee(route('admin.akademik.jadwal.import'));
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.jadwal.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('imports a jadwal row and reports the success count', function () {
    $admin = adminUser();
    $semester = Semester::factory()->create(['kode' => '20241']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['kode_matkul' => 'MK001']);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_semester' => $semester->id,
        'id_kelompok_kelas' => null,
    ]);
    $ruangan = Ruangan::factory()->create(['nama' => 'A101']);
    $jenisKuliah = JenisKuliah::factory()->create(['nama' => 'Teori']);
    $dosen = Dosen::factory()->create(['kode_dosen' => 'DSN001']);

    $file = makeJadwalImportFile([
        [$semester->kode, 'MK001', '', '1', '2026-01-01', $jenisKuliah->nama, 'ya', 'senin', '08:00', '10:00', $ruangan->nama, $dosen->kode_dosen],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.skip_count', 0);

    $jadwal = Jadwal::where('id_kelas', $kelas->id)->firstOrFail();
    expect($jadwal->id_ruangan)->toBe($ruangan->id);
    expect($jadwal->id_jenis_kuliah)->toBe($jenisKuliah->id);
    expect($jadwal->hari)->toBe('senin');
    expect((bool) $jadwal->is_active)->toBeTrue();
    expect(JadwalDosen::where('id_jadwal', $jadwal->id)->where('id_dosen', $dosen->id)->exists())->toBeTrue();
});

it('resolves the kelas via nama kelompok kelas when provided', function () {
    $admin = adminUser();
    $semester = Semester::factory()->create(['kode' => '20242']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['kode_matkul' => 'MK900']);
    $kelompok = KelompokKelas::factory()->create(['nama' => 'Kelompok A']);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_semester' => $semester->id,
        'id_kelompok_kelas' => $kelompok->id,
    ]);

    $file = makeJadwalImportFile([
        [$semester->kode, 'MK900', 'Kelompok A', '1', '2026-01-01', '', 'tidak', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    expect(Jadwal::where('id_kelas', $kelas->id)->exists())->toBeTrue();
});

it('skips a row whose slot pertemuan already exists and records the reason', function () {
    $admin = adminUser();
    $semester = Semester::factory()->create(['kode' => '20243']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['kode_matkul' => 'MK500']);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_semester' => $semester->id,
        'id_kelompok_kelas' => null,
    ]);
    Jadwal::factory()->create([
        'id_kelas' => $kelas->id,
        'urutan_pertemuan' => 1,
        'id_ruangan' => null,
    ]);

    $file = makeJadwalImportFile([
        [$semester->kode, 'MK500', '', '1', '2026-01-01', '', 'tidak', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 1);

    expect(Jadwal::where('id_kelas', $kelas->id)->count())->toBe(1);
});

it('records an error when the kode matkul cannot be found', function () {
    $admin = adminUser();
    $semester = Semester::factory()->create(['kode' => '20244']);

    $file = makeJadwalImportFile([
        [$semester->kode, 'TIDAK-ADA', '', '1', '2026-01-01', '', '', '', '', '', '', ''],
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
    $semester = Semester::factory()->create(['kode' => '20245']);
    $kurikulumMatkulA = KurikulumMatkul::factory()->create(['kode_matkul' => 'MKA']);
    $kurikulumMatkulB = KurikulumMatkul::factory()->create(['kode_matkul' => 'MKB']);
    $kelasA = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkulA->id,
        'id_semester' => $semester->id,
        'id_prodi' => $prodiA->id,
        'id_kelompok_kelas' => null,
    ]);
    Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkulB->id,
        'id_semester' => $semester->id,
        'id_prodi' => $prodiB->id,
        'id_kelompok_kelas' => null,
    ]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    $file = makeJadwalImportFile([
        [$semester->kode, 'MKA', '', '1', '2026-01-01', '', '', '', '', '', '', ''],
        [$semester->kode, 'MKB', '', '1', '2026-01-01', '', '', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.skip_count', 1);

    expect(Jadwal::where('id_kelas', $kelasA->id)->exists())->toBeTrue();
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.jadwal.import'))
        ->assertRedirect(route('login'));
});
