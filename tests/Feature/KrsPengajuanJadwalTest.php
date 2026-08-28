<?php

use App\Livewire\Mahasiswa\Krs\Pengajuan;
use App\Models\Kelas;
use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

/**
 * Daftar kelas di halaman pengajuan KRS disaring enam syarat sekaligus (KrsController::getJadwalPengajuan).
 * Salah satu saja meleset, kelasnya hilang tanpa pesan apa pun — makanya tiap syarat diuji terpisah.
 */
function skenarioPengajuan(array $kelasOverrides = [], array $mahasiswaOverrides = []): array
{
    $semesterMasuk = Semester::factory()->create(['kode' => '20241']);
    $semesterBerjalan = Semester::factory()->active()->create(['kode' => '20261']);

    $prodi = Prodi::factory()->create();
    $kelompokKelas = KelompokKelas::factory()->create(['id_prodi' => $prodi->id]);

    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(array_merge([
        'id_user' => $user->id,
        'id_prodi' => $prodi->id,
        'id_semester_masuk' => $semesterMasuk->id,
        'id_kelompok_kelas' => $kelompokKelas->id,
    ], $mahasiswaOverrides));

    $kelas = Kelas::factory()->create(array_merge([
        'id_kurikulum_matkul' => KurikulumMatkul::factory(),
        'id_prodi' => $prodi->id,
        'id_semester' => $semesterBerjalan->id,
        'id_angkatan' => $semesterMasuk->id,
        'id_kelompok_kelas' => $kelompokKelas->id,
        'is_active' => true,
    ], $kelasOverrides));

    return compact('semesterMasuk', 'semesterBerjalan', 'prodi', 'kelompokKelas', 'user', 'mahasiswa', 'kelas');
}

it('menampilkan kelas semester aktif yang cocok prodi, angkatan, dan kelompok kelas mahasiswa', function () {
    ['user' => $user, 'kelas' => $kelas] = skenarioPengajuan();

    $response = $this->actingAs($user)->getJson('/api/krs/pengajuan/jadwal');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id_kelas')->all())->toBe([$kelas->id]);
});

it('menyembunyikan kelas yang angkatannya diisi semester berjalan, bukan semester masuk mahasiswa', function () {
    // Regresi nyata: admin mengisi "Angkatan" dengan semester berjalan (20261) padahal kelompok
    // kelasnya angkatan 20241, sehingga halaman pengajuan KRS kosong untuk seluruh mahasiswa.
    $data = skenarioPengajuan();
    $data['kelas']->update(['id_angkatan' => $data['semesterBerjalan']->id]);

    $response = $this->actingAs($data['user'])->getJson('/api/krs/pengajuan/jadwal');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('menyembunyikan kelas yang tidak aktif', function () {
    ['user' => $user] = skenarioPengajuan(['is_active' => false]);

    $response = $this->actingAs($user)->getJson('/api/krs/pengajuan/jadwal');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('menyembunyikan kelas dari semester yang bukan semester aktif', function () {
    $data = skenarioPengajuan();
    $data['kelas']->update(['id_semester' => Semester::factory()->create()->id]);

    $response = $this->actingAs($data['user'])->getJson('/api/krs/pengajuan/jadwal');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('menyembunyikan kelas milik prodi lain', function () {
    ['user' => $user] = skenarioPengajuan(['id_prodi' => Prodi::factory()]);

    $response = $this->actingAs($user)->getJson('/api/krs/pengajuan/jadwal');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('menyembunyikan kelas milik kelompok kelas lain', function () {
    ['user' => $user] = skenarioPengajuan(['id_kelompok_kelas' => KelompokKelas::factory()]);

    $response = $this->actingAs($user)->getJson('/api/krs/pengajuan/jadwal');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('menyembunyikan kelas yang sudah dihapus (soft delete)', function () {
    ['user' => $user, 'kelas' => $kelas] = skenarioPengajuan();
    $kelas->delete();

    $response = $this->actingAs($user)->getJson('/api/krs/pengajuan/jadwal');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('mengembalikan 404 kalau tidak ada semester aktif', function () {
    ['user' => $user] = skenarioPengajuan();
    Semester::query()->update(['is_active' => false]);

    $this->actingAs($user)->getJson('/api/krs/pengajuan/jadwal')->assertStatus(404);
});

it('halaman Livewire pengajuan menyaring kelas sama persis dengan endpoint API', function () {
    $data = skenarioPengajuan();

    Livewire::actingAs($data['user'])->test(Pengajuan::class)
        ->assertSet('mahasiswaId', $data['mahasiswa']->id)
        ->tap(fn ($c) => expect(collect($c->instance()->data)->pluck('id_kelas')->all())->toBe([$data['kelas']->id]));

    $data['kelas']->update(['id_angkatan' => $data['semesterBerjalan']->id]);

    Livewire::actingAs($data['user'])->test(Pengajuan::class)
        ->tap(fn ($c) => expect($c->instance()->data)->toBe([]));
});
