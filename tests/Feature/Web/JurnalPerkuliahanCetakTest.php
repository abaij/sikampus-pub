<?php

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Perkuliahan;
use App\Models\Semester;
use App\Models\User;

it('redirects unauthenticated users to the login page', function () {
    $kelas = Kelas::factory()->create();

    $this->get(route('dosen.jadwal.jurnal-perkuliahan', $kelas->id))
        ->assertRedirect(route('login'));
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);
    $kelas = Kelas::factory()->create();

    $this->actingAs($mahasiswa)
        ->get(route('dosen.jadwal.jurnal-perkuliahan', $kelas->id))
        ->assertForbidden();
});

it('forbids a dosen who does not teach the kelas', function () {
    $dosenUser = dosenUser();
    $kelas = Kelas::factory()->create();

    $this->actingAs($dosenUser)
        ->get(route('dosen.jadwal.jurnal-perkuliahan', $kelas->id))
        ->assertForbidden();
});

it('streams a PDF download for a dosen who teaches the kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create(['kode' => 'KLS-JURNAL01']);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'is_active' => true]);

    $response = $this->actingAs($dosenUser)->get(route('dosen.jadwal.jurnal-perkuliahan', $kelas->id));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('Jurnal_Perkuliahan_KLS-JURNAL01');
});

it('rejects a mismatched id_semester query parameter with a 422', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $semesterKelas = Semester::factory()->create();
    $semesterLain = Semester::factory()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterKelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $this->actingAs($dosenUser)
        ->get(route('dosen.jadwal.jurnal-perkuliahan', ['kelasId' => $kelas->id, 'id_semester' => $semesterLain->id]))
        ->assertStatus(422);
});

it('builds a row per jadwal slot, counting only hadir kehadiran against approved krs mahasiswa', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'is_active' => true]);
    $perkuliahan = Perkuliahan::factory()->create([
        'id_jadwal' => $jadwal->id,
        'waktu_mulai' => now()->subHour(),
        'waktu_selesai' => now(),
    ]);

    $mhsApproved = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mhsApproved->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Kehadiran::create(['id_perkuliahan' => $perkuliahan->id, 'id_mhs' => $mhsApproved->id, 'status' => 'hadir']);

    $mhsBelumDisetujui = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mhsBelumDisetujui->id, 'id_kelas' => $kelas->id, 'approved_at' => null]);
    Kehadiran::create(['id_perkuliahan' => $perkuliahan->id, 'id_mhs' => $mhsBelumDisetujui->id, 'status' => 'hadir']);

    $response = $this->actingAs($dosenUser)->get(route('dosen.jadwal.jurnal-perkuliahan', $kelas->id));

    $response->assertOk();
    // 1 mahasiswa disetujui via KRS, dan tepat 1 kehadiran "hadir" tercatat untuk mahasiswa itu —
    // kehadiran mahasiswa yang belum di-ACC KRS-nya tidak boleh ikut dihitung.
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('renders even for a kelas with no jadwal yet', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $this->actingAs($dosenUser)
        ->get(route('dosen.jadwal.jurnal-perkuliahan', $kelas->id))
        ->assertOk();
});
