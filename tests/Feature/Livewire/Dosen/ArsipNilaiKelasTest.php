<?php

use App\Livewire\Dosen\Arsip\NilaiKelas;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\NilaiRevisi;
use App\Models\Semester;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $kelas = Kelas::factory()->create();

    $this->get(route('dosen.arsip.nilai', $kelas->id))->assertRedirect(route('login'));
});

it('forbids a dosen who is neither pic nor has an active jadwal for the kelas', function () {
    $dosenUser = dosenUser();
    $kelas = Kelas::factory()->create();

    Livewire::actingAs($dosenUser)->test(NilaiKelas::class, ['id' => $kelas->id])->assertForbidden();
});

it('shows archived nilai for a non-active semester kelas via past jadwal_dosen', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterLalu = Semester::factory()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterLalu->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $mhs = Mahasiswa::factory()->create();
    $krs = Krs::factory()->create(['id_mahasiswa' => $mhs->id, 'id_kelas' => $kelas->id]);
    Nilai::factory()->create(['id_krs' => $krs->id, 'huruf_mutu' => 'B', 'angka_mutu' => 3, 'is_final' => true]);

    $data = Livewire::actingAs($dosenUser)->test(NilaiKelas::class, ['id' => $kelas->id])->instance()->data();

    expect($data['mahasiswa'])->toHaveCount(1);
    expect($data['mahasiswa'][0]['nilai']->huruf_mutu)->toBe('B');
});

it('stores a revisi entry and bumps the revisi counter', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id]);
    $mhs = Mahasiswa::factory()->create();
    $krs = Krs::factory()->create(['id_mahasiswa' => $mhs->id, 'id_kelas' => $kelas->id]);
    Nilai::factory()->create(['id_krs' => $krs->id, 'huruf_mutu' => 'C', 'angka_mutu' => 2, 'revisi' => 0]);

    Livewire::actingAs($dosenUser)
        ->test(NilaiKelas::class, ['id' => $kelas->id])
        ->call('openRevisiModal', $krs->id)
        ->set('revisiHurufMutu', 'A')
        ->set('revisiAngkaMutu', '4')
        ->set('revisiKeterangan', 'Koreksi salah input')
        ->call('saveRevisi')
        ->assertHasNoErrors();

    $nilai = Nilai::where('id_krs', $krs->id)->firstOrFail();
    expect($nilai->huruf_mutu)->toBe('A');
    expect($nilai->revisi)->toBe(1);
    expect(NilaiRevisi::where('id_krs', $krs->id)->count())->toBe(1);
});

it('rejects revising a krs that does not belong to this kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id]);

    $otherKelas = Kelas::factory()->create();
    $otherMhs = Mahasiswa::factory()->create();
    $otherKrs = Krs::factory()->create(['id_mahasiswa' => $otherMhs->id, 'id_kelas' => $otherKelas->id]);

    Livewire::actingAs($dosenUser)
        ->test(NilaiKelas::class, ['id' => $kelas->id])
        ->set('revisiKrsId', $otherKrs->id)
        ->set('revisiHurufMutu', 'A')
        ->call('saveRevisi')
        ->assertStatus(404);
});

it('lets a dosen listed only in kelas_dosen open the page', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    // Bukan PIC dan tanpa jadwal_dosen — hanya tercatat sebagai pengampu di kelas_dosen,
    // sumber yang juga dipakai daftar kelas di halaman Nilai dan Arsip.
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)
        ->test(NilaiKelas::class, ['id' => $kelas->id])
        ->assertOk();
});
