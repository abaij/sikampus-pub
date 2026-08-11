<?php

use App\Livewire\Admin\Mahasiswa\Import;
use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\StatusAkademik;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa
 * diparse PhpSpreadsheet) dengan urutan kolom persis MahasiswaController::import.
 * Dibungkus lewat UploadedFile::fake()->createWithContent supaya hasilnya instance
 * Illuminate\Http\Testing\File — Livewire test harness butuh properti publik ->name
 * yang tidak ada di UploadedFile biasa.
 */
function makeMahasiswaImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Nama*', 'NIM'], null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'mhs_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));
}

it('renders the import page with a link to download the template', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.administrasi.mahasiswa.import'))
        ->assertOk()
        ->assertSee('Proses Import')
        ->assertSee(route('admin.administrasi.mahasiswa.template'));
});

it('shows the import submenu under Mahasiswa, below Kelas Mahasiswa', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.administrasi.mahasiswa'))
        ->assertOk()
        ->assertSeeInOrder([
            route('admin.administrasi.kelas-mahasiswa'),
            route('admin.administrasi.mahasiswa.import'),
        ]);
});

it('downloads a template with an xlsx content type', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.administrasi.mahasiswa.template'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('imports new mahasiswa rows and reports the success count', function () {
    $admin = adminUser();
    $file = makeMahasiswaImportFile([
        ['Budi Santoso', '2024111001'],
        ['Citra Lestari', '2024111002'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 2)
        ->assertSet('result.skip_count', 0);

    expect(Mahasiswa::where('nim', '2024111001')->exists())->toBeTrue();
    expect(Mahasiswa::where('nim', '2024111002')->exists())->toBeTrue();
});

it('updates the existing mahasiswa instead of duplicating when the nim already exists', function () {
    $admin = adminUser();
    $existing = Mahasiswa::factory()->create(['nim' => '2024111003', 'nama' => 'Nama Lama']);

    $file = makeMahasiswaImportFile([
        ['Nama Baru', '2024111003'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    expect(Mahasiswa::where('nim', '2024111003')->count())->toBe(1);
    expect($existing->fresh()->nama)->toBe('Nama Baru');
});

it('skips a row with an empty nama and records the error', function () {
    $admin = adminUser();
    $file = makeMahasiswaImportFile([
        ['', '2024111004'],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 1);

    expect(Mahasiswa::where('nim', '2024111004')->exists())->toBeFalse();
});

it('resolves prodi kode and status akademik nama to their ids', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create(['kode' => 'TI']);
    $status = StatusAkademik::factory()->create(['nama' => 'Aktif']);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $row = array_fill(0, 52, null);
    $row[0] = 'Dewi Anggraini';
    $row[1] = '2024111005';
    $row[9] = 'Aktif';
    $row[20] = 'TI';
    $sheet->fromArray($row, null, 'A2');
    $path = tempnam(sys_get_temp_dir(), 'mhs_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $file = UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $mahasiswa = Mahasiswa::where('nim', '2024111005')->firstOrFail();
    expect($mahasiswa->id_prodi)->toBe($prodi->id);
    expect($mahasiswa->id_status_akademik)->toBe($status->id);
});

it('links a foto path that already exists on the public disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('mahasiswa/foto/existing.jpg', 'isi-file-dummy');

    $admin = adminUser();

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $row = array_fill(0, 53, null);
    $row[0] = 'Foto Ada';
    $row[1] = '2024111006';
    $row[52] = 'mahasiswa/foto/existing.jpg';
    $sheet->fromArray($row, null, 'A2');
    $path = tempnam(sys_get_temp_dir(), 'mhs_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $file = UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $mahasiswa = Mahasiswa::where('nim', '2024111006')->firstOrFail();
    expect($mahasiswa->foto)->toBe('mahasiswa/foto/existing.jpg');
});

it('saves foto as empty and records a warning when the path does not exist on the public disk', function () {
    Storage::fake('public');

    $admin = adminUser();

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $row = array_fill(0, 53, null);
    $row[0] = 'Foto Hilang';
    $row[1] = '2024111007';
    $row[52] = 'mahasiswa/foto/tidak-ada.jpg';
    $sheet->fromArray($row, null, 'A2');
    $path = tempnam(sys_get_temp_dir(), 'mhs_import_').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $file = UploadedFile::fake()->createWithContent('import.xlsx', file_get_contents($path));

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result.success_count', 1);

    $mahasiswa = Mahasiswa::where('nim', '2024111007')->firstOrFail();
    expect($mahasiswa->foto)->toBeNull();
    expect($component->get('result.errors'))->toContain("Baris 2: Foto 'mahasiswa/foto/tidak-ada.jpg' tidak ditemukan di storage, disimpan dengan Foto kosong.");
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.administrasi.mahasiswa.import'))
        ->assertRedirect(route('login'));
});
