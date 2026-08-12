<?php

use App\Livewire\Dosen\Jadwal\Show;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Perkuliahan;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $kelas = Kelas::factory()->create();

    $this->get(route('dosen.jadwal.show', ['kelasId' => $kelas->id]))->assertRedirect(route('login'));
});

it('forbids a dosen who does not teach this kelas', function () {
    $dosenUser = dosenUser();
    $kelas = Kelas::factory()->create();

    Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])->assertForbidden();
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);
    $kelas = Kelas::factory()->create();

    $this->actingAs($mahasiswa)->get(route('dosen.jadwal.show', ['kelasId' => $kelas->id]))->assertForbidden();
});

it('shows the approved krs count and the pic flag for the assigned dosen', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $mahasiswaDiterima = Mahasiswa::factory()->create();
    $mahasiswaBelum = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mahasiswaDiterima->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Krs::factory()->create(['id_mahasiswa' => $mahasiswaBelum->id, 'id_kelas' => $kelas->id, 'approved_at' => null]);

    $component = Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id]);

    $component->assertOk()->assertSee('Dosen Penanggung Jawab');
    expect($component->instance()->jumlahMahasiswa())->toBe(1);
});

it('marks a jadwal slot as sedang berlangsung when a perkuliahan session has started but not ended', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'is_active' => true]);
    Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null]);

    $rows = Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])->instance()->jadwalRows();

    expect($rows[0]['sesi_status'])->toBe('sedang_berlangsung');
});

it('shows the Status sesi column with the sesi badge in the slot jadwal pertemuan table', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'is_active' => true]);
    Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null]);

    $this->actingAs($dosenUser)
        ->get(route('dosen.jadwal.show', ['kelasId' => $kelas->id]))
        ->assertOk()
        ->assertSee('Status sesi')
        ->assertSee('Sedang berlangsung');
});

it('rejects a mismatched id_semester query with a 422', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterKelas = Semester::factory()->create();
    $semesterLain = Semester::factory()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterKelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)
        ->test(Show::class, ['kelasId' => $kelas->id])
        ->set('idSemester', (string) $semesterLain->id)
        ->assertStatus(422);
});
