<?php

use App\Livewire\Dosen\Nilai\Input;
use App\Models\Dosen;
use App\Models\JenisPenilaian;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $kelas = Kelas::factory()->create();

    $this->get(route('dosen.nilai.input', $kelas->id))->assertRedirect(route('login'));
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);
    $kelas = Kelas::factory()->create();

    $this->actingAs($mahasiswa)->get(route('dosen.nilai.input', $kelas->id))->assertForbidden();
});

it('forbids a dosen who is neither pic nor has an active jadwal for the kelas', function () {
    $dosenUser = dosenUser();
    $kelas = Kelas::factory()->create();

    Livewire::actingAs($dosenUser)->test(Input::class, ['kelasId' => $kelas->id])->assertForbidden();
});

it('preselects the first jenis penilaian and prefills existing nilai_komponen values', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id, 'id_semester' => $semesterAktif->id]);

    $jenisPenilaian = JenisPenilaian::factory()->create(['nama' => 'Ujian Tengah Semester', 'status' => 'manual']);
    $mhs = Mahasiswa::factory()->create();
    $krs = Krs::factory()->create(['id_mahasiswa' => $mhs->id, 'id_kelas' => $kelas->id]);
    DB::table('nilai_komponen')->insert([
        'id_krs' => $krs->id,
        'id_jenis_penilaian' => $jenisPenilaian->id,
        'nilai' => 88,
        'id_dosen' => $dosen->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($dosenUser)
        ->test(Input::class, ['kelasId' => $kelas->id])
        ->assertSet('selectedJenisPenilaianId', (string) $jenisPenilaian->id)
        ->assertSet("nilaiInputs.{$krs->id}", '88.00');
});

it('saves filled nilai values as nilai_komponen rows and skips blank ones', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id, 'id_semester' => $semesterAktif->id]);

    $jenisPenilaian = JenisPenilaian::factory()->create(['status' => 'manual']);
    $mhs1 = Mahasiswa::factory()->create();
    $mhs2 = Mahasiswa::factory()->create();
    $krs1 = Krs::factory()->create(['id_mahasiswa' => $mhs1->id, 'id_kelas' => $kelas->id]);
    $krs2 = Krs::factory()->create(['id_mahasiswa' => $mhs2->id, 'id_kelas' => $kelas->id]);

    Livewire::actingAs($dosenUser)
        ->test(Input::class, ['kelasId' => $kelas->id])
        ->set('selectedJenisPenilaianId', (string) $jenisPenilaian->id)
        ->set("nilaiInputs.{$krs1->id}", '75')
        ->set("nilaiInputs.{$krs2->id}", '')
        ->call('save');

    $this->assertDatabaseHas('nilai_komponen', [
        'id_krs' => $krs1->id,
        'id_jenis_penilaian' => $jenisPenilaian->id,
        'nilai' => 75,
    ]);
    $this->assertDatabaseMissing('nilai_komponen', ['id_krs' => $krs2->id]);
});

it('updates an existing nilai_komponen value instead of creating a duplicate', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id, 'id_semester' => $semesterAktif->id]);

    $jenisPenilaian = JenisPenilaian::factory()->create(['status' => 'manual']);
    $mhs = Mahasiswa::factory()->create();
    $krs = Krs::factory()->create(['id_mahasiswa' => $mhs->id, 'id_kelas' => $kelas->id]);
    DB::table('nilai_komponen')->insert([
        'id_krs' => $krs->id,
        'id_jenis_penilaian' => $jenisPenilaian->id,
        'nilai' => 60,
        'id_dosen' => $dosen->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::actingAs($dosenUser)
        ->test(Input::class, ['kelasId' => $kelas->id])
        ->set('selectedJenisPenilaianId', (string) $jenisPenilaian->id)
        ->set("nilaiInputs.{$krs->id}", '95')
        ->call('save');

    expect(DB::table('nilai_komponen')->where('id_krs', $krs->id)->count())->toBe(1);
    expect(DB::table('nilai_komponen')->where('id_krs', $krs->id)->value('nilai'))->toEqual(95);
});

it('forbids input nilai for a kelas outside the active semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    Semester::factory()->active()->create();
    $semesterLampau = Semester::factory()->create();
    $kelasLampau = Kelas::factory()->create(['id_dosen_pic' => $dosen->id, 'id_semester' => $semesterLampau->id]);

    Livewire::actingAs($dosenUser)
        ->test(Input::class, ['kelasId' => $kelasLampau->id])
        ->assertForbidden();
});

it('allows a dosen listed only in kelas_dosen to input nilai', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    // Bukan PIC, tanpa jadwal_dosen — hanya tercatat sebagai pengampu di kelas_dosen.
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)
        ->test(Input::class, ['kelasId' => $kelas->id])
        ->assertOk();
});
