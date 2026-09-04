<?php

use App\Jobs\ImportPerkuliahanJob;
use App\Livewire\Admin\Perkuliahan\Import;
use App\Models\ImportBatch;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\KurikulumMatkul;
use App\Models\MateriPerkuliahan;
use App\Models\Perkuliahan;
use App\Models\Prodi;
use App\Models\Semester;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Susun file xlsx sungguhan (bukan UploadedFile::fake() biasa — isinya harus bisa diparse
 * PhpSpreadsheet) dengan urutan kolom persis PerkuliahanImportService::run.
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

beforeEach(function () {
    // Isolasi file yang disimpan komponen (disk 'local', bukan file asli di storage/app/private)
    // dari sesi tes lain — lihat catatan App\Livewire\Admin\Perkuliahan\Import::import().
    Storage::fake('local');
});

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

it('dispatches ImportPerkuliahanJob and creates a pending import batch instead of processing synchronously', function () {
    Bus::fake();

    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '2026-01-15 08:00', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->assertSet('result', null)
        ->assertSet('status', 'pending');

    Bus::assertDispatched(ImportPerkuliahanJob::class);

    // Karena Bus di-fake, job tidak benar-benar jalan — batch harus tetap "pending" di DB.
    expect(ImportBatch::where('type', 'perkuliahan')->where('status', 'pending')->exists())->toBeTrue();
    expect(Perkuliahan::where('id_jadwal', $jadwal->id)->exists())->toBeFalse();
});

it('completes the batch and reflects the result after one poll tick (queue runs sync in tests)', function () {
    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '2026-01-15 08:00', '2026-01-15 10:00', 'Pengenalan kuliah', 'Materi sesuai RPS', '', ''],
    ]);

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import');

    // QUEUE_CONNECTION=sync di lingkungan tes: job sudah selesai begitu import() kembali, tapi
    // properti komponen baru ter-update lewat poll() — sama seperti alur nyata (browser mem-poll
    // beberapa kali sampai job yang jalan di worker antrian selesai).
    $batch = ImportBatch::where('type', 'perkuliahan')->latest('id')->first();
    expect($batch->status)->toBe('completed');

    $component->call('poll')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.materi_perkuliahan_count', 0)
        ->assertSet('result.skip_count', 0)
        ->assertSet('result.failed_count', 0)
        ->assertSet('batchId', null);

    $perkuliahan = Perkuliahan::where('id_jadwal', $jadwal->id)->firstOrFail();
    expect($perkuliahan->materi)->toBe('Pengenalan kuliah');
    expect($perkuliahan->realisasi_materi)->toBe('Materi sesuai RPS');
});

it('deletes the temporary uploaded file once the job finishes', function () {
    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();

    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '2026-01-15 08:00', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import');

    $batch = ImportBatch::where('type', 'perkuliahan')->latest('id')->first();
    expect($batch->file_path)->not->toBeNull();
    Storage::disk('local')->assertMissing($batch->file_path);
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

    $component = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->call('poll')
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
        ->call('poll')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.skip_count', 1)
        ->assertSet('result.failed_count', 0);

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
        ->call('poll')
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
        ->call('poll')
        ->assertSet('result.materi_perkuliahan_count', 0)
        ->assertSet('result.failed_count', 1)
        ->assertSet('result.skip_count', 0)
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
        ->call('poll')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.failed_count', 1)
        ->assertSet('result.skip_count', 0)
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
        ->call('poll')
        ->assertSet('result.success_count', 0)
        ->assertSet('result.failed_count', 1)
        ->assertSet('result.skip_count', 0)
        ->get('result');

    expect($result['errors'])->not->toBeEmpty();
});

it('distinguishes failed rows from skipped rows in the same import — the exact confusion this counter was added to resolve', function () {
    $admin = adminUser();
    $jadwal = Jadwal::factory()->create();
    Perkuliahan::factory()->create([
        'id_jadwal' => $jadwal->id,
        'waktu_mulai' => '2026-01-15 08:00:00',
    ]);

    $file = makePerkuliahanImportFile([
        // Baris 1: gagal validasi — semester dengan kode ini tidak ada, bukan "dilewati".
        ['', 'TIDAK-ADA-SEMESTER', 'MK001', '', '1', '', '2026-01-01 08:00', '', '', '', '', ''],
        // Baris 2: dilewati — realisasi untuk jadwal & waktu mulai ini sudah ada.
        [$jadwal->id, '', '', '', '', '', '2026-01-15 08:00', '', '', '', '', ''],
        // Baris 3: berhasil.
        [$jadwal->id, '', '', '', '', '', '2026-02-01 08:00', '', '', '', '', ''],
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->call('poll')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.skip_count', 1)
        ->assertSet('result.failed_count', 1);
});

it('includes kode matkul and nama prodi in the error message for a row resolved via natural keys', function () {
    $semester = Semester::factory()->create(['kode' => '20241']);
    $prodi = Prodi::factory()->create(['nama' => 'Teknik Informatika']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['kode_matkul' => 'MK002']);
    // Kelas ada tapi sengaja tidak dibuatkan jadwal untuk pertemuan ke-1 supaya
    // findJadwalSlotForImport() gagal — di titik ini kelas (dan prodi-nya) sudah ter-resolve.
    Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_semester' => $semester->id,
        'id_prodi' => $prodi->id,
        'id_kelompok_kelas' => null,
    ]);

    $admin = adminUser();
    $file = makePerkuliahanImportFile([
        ['', $semester->kode, 'MK002', '', '1', '', '2026-01-15 08:00', '', '', '', '', ''],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->call('poll')
        ->assertSet('result.failed_count', 1)
        ->get('result');

    expect($result['errors'][0])
        ->toContain('Matkul MK002')
        ->toContain('Prodi Teknik Informatika');
});

it('includes kode matkul and nama prodi derived from the jadwal when a row uses id_jadwal directly', function () {
    $prodiAllowed = Prodi::factory()->create();
    $prodiDenied = Prodi::factory()->create(['nama' => 'Sistem Informasi']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['kode_matkul' => 'MK003']);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_prodi' => $prodiDenied->id,
    ]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiAllowed->id);

    // Baris hanya mengisi id_jadwal — kolom Kode Mata Kuliah kosong, jadi kode matkul di log
    // harus diturunkan dari kelas milik jadwal ini, bukan dari kolom yang memang tidak diisi.
    $file = makePerkuliahanImportFile([
        [$jadwal->id, '', '', '', '', '', '2026-01-15 08:00', '', '', '', '', ''],
    ]);

    $result = Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->call('poll')
        ->assertSet('result.skip_count', 1)
        ->get('result');

    expect($result['errors'][0])
        ->toContain('Matkul MK003')
        ->toContain('Prodi Sistem Informasi');
});

it('marks the batch failed with a friendly message (not a raw PHP error) when the file cannot be parsed', function () {
    // Regresi: PerkuliahanImportService::run() dulu tidak membungkus IOFactory::load()/toArray()
    // dalam try/catch, jadi error mentah dari PhpSpreadsheet (mis. TypeError array_intersect_key()
    // dari mesin kalkulasi formula-nya untuk sel berformula yang tidak didukung) bocor apa adanya
    // ke error_message batch, bukan pesan ramah seperti importer lain di app ini.
    $admin = adminUser();

    // Plain text ("not a real xlsx file") diam-diam berhasil dibaca PhpSpreadsheet lewat fallback
    // reader CSV (jadi lolos ke pesan "File Excel kosong", bukan jalur yang mau diuji di sini) —
    // header ZIP acak ini yang benar-benar gagal diidentifikasi reader mana pun.
    $file = UploadedFile::fake()->createWithContent('import.xlsx', "PK\x03\x04".random_bytes(200));

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->set('file', $file)
        ->call('import')
        ->call('poll')
        ->assertSet('result', null)
        ->assertSet('jobError', fn ($value) => str_contains($value, 'Gagal membaca file Excel') && str_contains($value, 'hindari rumus error'));

    expect(ImportBatch::where('type', 'perkuliahan')->where('status', 'failed')->exists())->toBeTrue();
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
        ->call('poll')
        ->assertSet('result.success_count', 1)
        ->assertSet('result.skip_count', 1)
        ->assertSet('result.failed_count', 0);

    expect(Perkuliahan::where('id_jadwal', $jadwalA->id)->exists())->toBeTrue();
    expect(Perkuliahan::where('id_jadwal', $jadwalB->id)->exists())->toBeFalse();
});

it('resumes tracking an in-progress batch for the same user after remounting (e.g. page refresh)', function () {
    $admin = adminUser();

    $batch = ImportBatch::create([
        'type' => 'perkuliahan',
        'id_user' => $admin->id,
        'status' => 'processing',
        'file_path' => 'imports/perkuliahan/does-not-matter.xlsx',
    ]);

    Livewire::actingAs($admin)
        ->test(Import::class)
        ->assertSet('batchId', $batch->id)
        ->assertSet('status', 'processing');
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.perkuliahan.import'))
        ->assertRedirect(route('login'));
});
