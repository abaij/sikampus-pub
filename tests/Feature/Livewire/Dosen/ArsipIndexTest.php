<?php

use App\Livewire\Dosen\Arsip\Index;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('dosen.arsip'))->assertRedirect(route('login'));
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);

    $this->actingAs($mahasiswa)->get(route('dosen.arsip'))->assertForbidden();
});

it('lists a unique kelas from active jadwal_dosen rows, filtered by semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $semesterLain = Semester::factory()->create();

    $kelasAktif = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    $jadwal1 = Jadwal::factory()->create(['id_kelas' => $kelasAktif->id]);
    $jadwal2 = Jadwal::factory()->create(['id_kelas' => $kelasAktif->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal1->id, 'id_dosen' => $dosen->id, 'status' => 'active']);
    JadwalDosen::create(['id_jadwal' => $jadwal2->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $kelasLain = Kelas::factory()->create(['id_semester' => $semesterLain->id]);
    $jadwalLain = Jadwal::factory()->create(['id_kelas' => $kelasLain->id]);
    JadwalDosen::create(['id_jadwal' => $jadwalLain->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    // jadwal nonaktif -> tidak muncul
    $kelasNonaktif = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    $jadwalNonaktif = Jadwal::factory()->create(['id_kelas' => $kelasNonaktif->id]);
    JadwalDosen::create(['id_jadwal' => $jadwalNonaktif->id, 'id_dosen' => $dosen->id, 'status' => 'inactive']);

    // Default arsip = semua semester, jadi kelas dari kedua semester ikut terdaftar.
    $rows = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows();
    expect($rows)->toHaveCount(2);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)
        ->set('filterSemester', (string) $semesterAktif->id)
        ->instance()->rows();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->id)->toBe($kelasAktif->id);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)
        ->set('filterSemester', (string) $semesterLain->id)
        ->instance()->rows();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->id)->toBe($kelasLain->id);
});

it('opens on all semesters instead of locking to the active one', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    Semester::factory()->active()->create();
    $semesterLampau = Semester::factory()->create();

    // Kelas arsip hanya ada di semester lampau — dulu halaman ini terbuka kosong karenanya.
    $kelasLampau = Kelas::factory()->create(['id_semester' => $semesterLampau->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelasLampau->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $component = Livewire::actingAs($dosenUser)->test(Index::class);

    expect($component->get('filterSemester'))->toBe('');
    expect($component->instance()->rows())->toHaveCount(1);
});

it('also lists kelas the dosen only has a kelas_dosen row for', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semester = Semester::factory()->create();

    // Diampu (kelas_dosen) tapi belum punya slot jadwal sama sekali.
    $kelasTanpaJadwal = Kelas::factory()->create(['id_semester' => $semester->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasTanpaJadwal->id, 'is_pic' => true]);

    // Punya jadwal_dosen saja — tetap harus ikut, tidak tergeser oleh sumber baru.
    $kelasDariJadwal = Kelas::factory()->create(['id_semester' => $semester->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelasDariJadwal->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows();
    $ids = array_map(fn ($k) => $k->id, $rows);

    expect($ids)->toContain($kelasTanpaJadwal->id);
    expect($ids)->toContain($kelasDariJadwal->id);
    expect($rows)->toHaveCount(2);
});

it('does not duplicate a kelas listed in both kelas_dosen and jadwal_dosen', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semester = Semester::factory()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semester->id]);

    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    $jadwal1 = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    $jadwal2 = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal1->id, 'id_dosen' => $dosen->id, 'status' => 'active']);
    JadwalDosen::create(['id_jadwal' => $jadwal2->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    expect(Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows())->toHaveCount(1);
});

it('does not list kelas where the dosen has no jadwal', function () {
    $dosenUser = dosenUser();
    Kelas::factory()->create();

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows();
    expect($rows)->toHaveCount(0);
});

it('shows the semester of each kelas, newest semester first', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterLama = Semester::factory()->create(['kode' => '20241', 'nama' => '2024 Ganjil']);
    $semesterBaru = Semester::factory()->create(['kode' => '20251', 'nama' => '2025 Ganjil']);

    $kelasLama = Kelas::factory()->create(['id_semester' => $semesterLama->id]);
    $kelasBaru = Kelas::factory()->create(['id_semester' => $semesterBaru->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasLama->id, 'is_pic' => true]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasBaru->id, 'is_pic' => true]);

    $component = Livewire::actingAs($dosenUser)->test(Index::class);

    $component->assertSee('2025 Ganjil')->assertSee('20251')
        ->assertSee('2024 Ganjil')->assertSee('20241')
        ->assertSeeInOrder(['2025 Ganjil', '2024 Ganjil']);

    expect($component->instance()->rows()[0]->id)->toBe($kelasBaru->id);
});

it('offers both export formats only when the arsip has at least one kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    Livewire::actingAs($dosenUser)->test(Index::class)
        ->assertDontSee('Ekspor Excel')
        ->assertDontSee('Ekspor PDF');

    $kelas = Kelas::factory()->create(['id_semester' => Semester::factory()->create()->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)->test(Index::class)
        ->assertSee('Ekspor Excel')
        ->assertSee('Ekspor PDF');
});

it('streams xlsx and pdf downloads of the arsip', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semester = Semester::factory()->create(['kode' => '20242', 'nama' => '2024 Genap']);
    $kelas = Kelas::factory()->create(['id_semester' => $semester->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    Livewire::actingAs($dosenUser)->test(Index::class)->call('exportExcel')->assertFileDownloaded();
    Livewire::actingAs($dosenUser)->test(Index::class)->call('exportPdf')->assertFileDownloaded();
});

it('names the export file after the selected semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semester = Semester::factory()->create(['kode' => '20242', 'nama' => '2024 Genap']);
    $kelas = Kelas::factory()->create(['id_semester' => $semester->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    // Tanpa filter: nama berkas tidak menyebut semester mana pun.
    $tanpaFilter = Livewire::actingAs($dosenUser)->test(Index::class)
        ->call('exportExcel')->effects['download']['name'];
    expect($tanpaFilter)->toStartWith('Arsip_Perkuliahan_');
    expect($tanpaFilter)->not->toContain('20242');

    $denganFilter = Livewire::actingAs($dosenUser)->test(Index::class)
        ->set('filterSemester', (string) $semester->id)
        ->call('exportExcel')->effects['download']['name'];
    expect($denganFilter)->toContain('20242');
});

it('honours id_semester from the query string, as sent by the arsip nilai back link', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterLampau = Semester::factory()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterLampau->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    Semester::factory()->active()->create();

    $component = Livewire::withQueryParams(['id_semester' => (string) $semesterLampau->id])
        ->actingAs($dosenUser)
        ->test(Index::class);

    expect($component->get('filterSemester'))->toBe((string) $semesterLampau->id);
    expect($component->instance()->rows())->toHaveCount(1);

    // Sisi pengirimnya: halaman arsip nilai menautkan balik dengan semester kelas ini.
    $this->actingAs($dosenUser)->get(route('dosen.arsip.nilai', $kelas->id))
        ->assertOk()
        ->assertSee(route('dosen.arsip', ['id_semester' => $semesterLampau->id]), false);
});
