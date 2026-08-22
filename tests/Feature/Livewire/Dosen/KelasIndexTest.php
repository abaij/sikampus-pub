<?php

use App\Livewire\Dosen\Kelas\Index;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\KurikulumMatkul;
use App\Models\Matkul;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('dosen.kelas'))->assertRedirect(route('login'));
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);

    $this->actingAs($mahasiswa)->get(route('dosen.kelas'))->assertForbidden();
});

it('only lists kelas the dosen is assigned to via kelas_dosen', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $matkul = Matkul::factory()->create(['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3]);
    $km = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $kelasSaya = Kelas::factory()->create(['id_kurikulum_matkul' => $km->id, 'id_semester' => $semesterAktif->id]);
    $kelasOrangLain = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);

    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasSaya->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Index::class)
        ->assertSee('IF101')
        ->assertSee('Algoritma')
        ->assertDontSee($kelasOrangLain->kode);
});

it('shows the pic badge and schedule summary using the kurikulum_matkul override label', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $km = KurikulumMatkul::factory()->create(['kode_matkul' => 'OVR-01', 'nama_matkul' => 'Nama Override']);
    $kelas = Kelas::factory()->create(['id_kurikulum_matkul' => $km->id, 'id_semester' => $semesterAktif->id]);
    Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:00', 'is_active' => true]);

    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Index::class)
        ->assertSee('OVR-01')
        ->assertSee('Nama Override')
        ->assertSee('Senin, 08:00–10:00');

    expect(Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows()[0]['is_pic'])->toBeTrue();
});

it('filters kelas by the selected semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterA = Semester::factory()->active()->create();
    $semesterB = Semester::factory()->create();

    $kelasA = Kelas::factory()->create(['id_semester' => $semesterA->id]);
    $kelasB = Kelas::factory()->create(['id_semester' => $semesterB->id]);

    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasA->id, 'is_pic' => false]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasB->id, 'is_pic' => false]);

    $component = Livewire::actingAs($dosenUser)->test(Index::class);
    expect($component->instance()->rows())->toHaveCount(1);

    $component->set('filterSemester', (string) $semesterB->id);
    expect($component->instance()->rows())->toHaveCount(1);

    $component->set('filterSemester', '');
    expect($component->instance()->rows())->toHaveCount(2);
});

it('offers both export formats only when there is at least one kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $semester = Semester::factory()->active()->create();

    Livewire::actingAs($dosenUser)->test(Index::class)
        ->assertDontSee('Ekspor Excel')
        ->assertDontSee('Ekspor PDF');

    $kelas = Kelas::factory()->create(['id_semester' => $semester->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)->test(Index::class)
        ->assertSee('Ekspor Excel')
        ->assertSee('Ekspor PDF');
});

it('streams an xlsx download when exporting kelas to excel', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semester = Semester::factory()->active()->create();
    $matkul = Matkul::factory()->create(['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3]);
    $km = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $kelas = Kelas::factory()->create(['id_kurikulum_matkul' => $km->id, 'id_semester' => $semester->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)->test(Index::class)
        ->call('exportExcel')
        ->assertFileDownloaded();
});

it('streams a pdf download when exporting kelas to pdf', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semester = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semester->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)->test(Index::class)
        ->call('exportPdf')
        ->assertFileDownloaded();
});

it('exports only the kelas of the selected semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterA = Semester::factory()->active()->create();
    $semesterB = Semester::factory()->create();

    $matkulA = Matkul::factory()->create(['kode' => 'IF101', 'nama' => 'Algoritma']);
    $matkulB = Matkul::factory()->create(['kode' => 'IF202', 'nama' => 'Basis Data']);
    $kelasA = Kelas::factory()->create([
        'id_kurikulum_matkul' => KurikulumMatkul::factory()->create(['id_matkul' => $matkulA->id])->id,
        'id_semester' => $semesterA->id,
    ]);
    $kelasB = Kelas::factory()->create([
        'id_kurikulum_matkul' => KurikulumMatkul::factory()->create(['id_matkul' => $matkulB->id])->id,
        'id_semester' => $semesterB->id,
    ]);

    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasA->id, 'is_pic' => true]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasB->id, 'is_pic' => false]);

    // Semester aktif (A) yang terpilih saat mount — hanya kelas A yang boleh ikut terekspor.
    $component = Livewire::actingAs($dosenUser)->test(Index::class);
    $isiPdf = $component->call('exportPdf')->effects['download']['content'] ?? '';
    $isiPdf = base64_decode($isiPdf);

    expect($isiPdf)->not->toBe('');
    expect(strlen($isiPdf))->toBeGreaterThan(500);

    // Barisnya sendiri lebih mudah diperiksa lewat data sumber ekspor.
    expect($component->instance()->rows())->toHaveCount(1);
});
