<?php

use App\Models\Kelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Nilai;
use App\Models\Semester;
use App\Models\User;

/**
 * Siapkan satu mahasiswa lengkap dengan akun user-nya, plus satu KRS bernilai final
 * pada semester yang diberikan.
 *
 * @return array{user: User, mahasiswa: Mahasiswa, krs: Krs}
 */
function siapkanMahasiswaBernilai(Semester $semester, string $hurufMutu = 'A', float $angkaMutu = 4, bool $approved = true, int $sks = 3): array
{
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(['id_user' => $user->id]);

    $matkul = Matkul::factory()->create(['sks' => $sks]);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $kelas = Kelas::factory()->create([
        'id_kurikulum_matkul' => $kurikulumMatkul->id,
        'id_semester' => $semester->id,
    ]);

    $krs = Krs::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_kelas' => $kelas->id,
        'approved_at' => $approved ? now() : null,
    ]);

    Nilai::factory()->create([
        'id_krs' => $krs->id,
        'sks' => $sks,
        'huruf_mutu' => $hurufMutu,
        'angka_mutu' => $angkaMutu,
        'is_final' => true,
    ]);

    return compact('user', 'mahasiswa', 'krs');
}

it('mahasiswa dapat mengekspor nilai per semester ke PDF', function () {
    $semester = Semester::factory()->create();
    ['user' => $user, 'mahasiswa' => $mahasiswa] = siapkanMahasiswaBernilai($semester);

    $response = $this->actingAs($user)->get('/api/nilai-semester/export-pdf');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('Nilai_'.$mahasiswa->nim);
});

it('nama berkas memuat kode semester saat ekspor dibatasi satu semester', function () {
    $semester = Semester::factory()->create();
    ['user' => $user] = siapkanMahasiswaBernilai($semester);

    $response = $this->actingAs($user)->get('/api/nilai-semester/export-pdf?id_semester='.$semester->id);

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain($semester->kode);
});

it('ekspor tetap berhasil untuk mahasiswa yang belum punya nilai', function () {
    $user = User::factory()->create(['role' => 'mahasiswa']);
    Mahasiswa::factory()->create(['id_user' => $user->id]);

    $this->actingAs($user)
        ->get('/api/nilai-semester/export-pdf')
        ->assertOk();
});

it('menolak ekspor nilai untuk pengguna yang bukan mahasiswa', function () {
    $dosenUser = User::factory()->create(['role' => 'dosen']);

    $this->actingAs($dosenUser)
        ->getJson('/api/nilai-semester/export-pdf')
        ->assertForbidden();
});

it('menolak ekspor nilai tanpa autentikasi', function () {
    $this->getJson('/api/nilai-semester/export-pdf')->assertUnauthorized();
});
