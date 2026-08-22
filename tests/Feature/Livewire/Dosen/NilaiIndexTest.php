<?php

use App\Livewire\Dosen\Nilai\Index;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('dosen.nilai'))->assertRedirect(route('login'));
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);

    $this->actingAs($mahasiswa)->get(route('dosen.nilai'))->assertForbidden();
});

it('lists a kelas where the dosen is pic, with the mahasiswa count', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $matkul = Matkul::factory()->create(['kode' => 'IF301', 'nama' => 'Kecerdasan Buatan']);
    $km = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $kelas = Kelas::factory()->create(['id_kurikulum_matkul' => $km->id, 'id_semester' => $semesterAktif->id, 'id_dosen_pic' => $dosen->id]);

    $mhs = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mhs->id, 'id_kelas' => $kelas->id]);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['kode_matkul'])->toBe('IF301');
    expect($rows[0]['jumlah_mahasiswa'])->toBe(1);
});

it('lists a kelas via an active jadwal_dosen row even without being the kelas pic', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows();

    expect($rows)->toHaveCount(1);
});

it('opens on the active semester but can be filtered to another one', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $semesterLain = Semester::factory()->create();
    $kelasAktif = Kelas::factory()->create(['id_semester' => $semesterAktif->id, 'id_dosen_pic' => $dosen->id]);
    $kelasLain = Kelas::factory()->create(['id_semester' => $semesterLain->id, 'id_dosen_pic' => $dosen->id]);

    $component = Livewire::actingAs($dosenUser)->test(Index::class);
    expect($component->get('filterSemester'))->toBe((string) $semesterAktif->id);

    $rows = $component->instance()->rows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['kelas']->id)->toBe($kelasAktif->id);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)
        ->set('filterSemester', (string) $semesterLain->id)
        ->instance()->rows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['kelas']->id)->toBe($kelasLain->id);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)
        ->set('filterSemester', '')
        ->instance()->rows();
    expect($rows)->toHaveCount(2);
});

it('lists a kelas the dosen only has a kelas_dosen row for', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->rows();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['kelas']->id)->toBe($kelas->id);
});

it('offers input nilai only for kelas in the active semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $semesterLampau = Semester::factory()->create();

    $matkulAktif = Matkul::factory()->create(['kode' => 'IF301', 'nama' => 'Kecerdasan Buatan']);
    $kmAktif = KurikulumMatkul::factory()->create(['id_matkul' => $matkulAktif->id]);
    $kelasAktif = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kmAktif->id,
        'id_semester' => $semesterAktif->id,
        'id_dosen_pic' => $dosen->id,
    ]);

    $matkulLampau = Matkul::factory()->create(['kode' => 'IF202', 'nama' => 'Basis Data']);
    $kmLampau = KurikulumMatkul::factory()->create(['id_matkul' => $matkulLampau->id]);
    $kelasLampau = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kmLampau->id,
        'id_semester' => $semesterLampau->id,
        'id_dosen_pic' => $dosen->id,
    ]);

    // Semester aktif: dua tombol.
    Livewire::actingAs($dosenUser)->test(Index::class)
        ->assertSee('Lihat Nilai')
        ->assertSee('Input Nilai')
        ->assertSee(route('dosen.nilai.input', $kelasAktif->id));

    // Semester lampau: hanya Lihat Nilai, tautan input tidak ditawarkan.
    Livewire::actingAs($dosenUser)->test(Index::class)
        ->set('filterSemester', (string) $semesterLampau->id)
        ->assertSee('Lihat Nilai')
        ->assertSee(route('dosen.nilai.rekap', $kelasLampau->id))
        ->assertDontSee('Input Nilai')
        // Kutip penutup ikut dicocokkan: URL input adalah awalan dari URL rekap
        // (/dosen/nilai/{id} vs /dosen/nilai/{id}/rekap), jadi tanpa itu asersinya lolos palsu.
        ->assertDontSee('href="'.route('dosen.nilai.input', $kelasLampau->id).'"', false);
});

it('honours id_semester from the query string instead of defaulting to the active semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $semesterLampau = Semester::factory()->create();
    Kelas::factory()->create(['id_semester' => $semesterAktif->id, 'id_dosen_pic' => $dosen->id]);
    $kelasLampau = Kelas::factory()->create(['id_semester' => $semesterLampau->id, 'id_dosen_pic' => $dosen->id]);

    // Inilah yang terjadi saat tombol "Kembali" dari Rincian Nilai diklik.
    $component = Livewire::withQueryParams(['id_semester' => (string) $semesterLampau->id])
        ->actingAs($dosenUser)
        ->test(Index::class);

    expect($component->get('filterSemester'))->toBe((string) $semesterLampau->id);

    $rows = $component->instance()->rows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['kelas']->id)->toBe($kelasLampau->id);
});

it('sends the kelas semester along in the back link of rincian and input nilai', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id, 'id_dosen_pic' => $dosen->id]);

    $this->actingAs($dosenUser)->get(route('dosen.nilai.rekap', $kelas->id))
        ->assertOk()
        ->assertSee(route('dosen.nilai', ['id_semester' => $semesterAktif->id]), false);

    $this->actingAs($dosenUser)->get(route('dosen.nilai.input', $kelas->id))
        ->assertOk()
        ->assertSee(route('dosen.nilai', ['id_semester' => $semesterAktif->id]), false);
});
