<?php

use App\Livewire\Admin\Kelas\Form;
use App\Models\Kelas;
use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\Semester;
use App\Services\KelasAngkatanService;
use Livewire\Livewire;

/**
 * kelas.id_angkatan = semester MASUK mahasiswa. Kalau diisi semester berjalan, kelasnya lolos
 * semua validasi lama tapi tidak pernah muncul di pengajuan KRS satu mahasiswa pun — kesalahan
 * yang tidak terlihat dari panel admin. Guard ini menolaknya di titik input.
 */
function kelompokAngkatan(Prodi $prodi, Semester $angkatan, int $jumlah = 2): KelompokKelas
{
    $kelompok = KelompokKelas::factory()->create(['id_prodi' => $prodi->id]);
    Mahasiswa::factory()->count($jumlah)->create([
        'id_prodi' => $prodi->id,
        'id_kelompok_kelas' => $kelompok->id,
        'id_semester_masuk' => $angkatan->id,
    ]);

    return $kelompok;
}

it('menolak simpan kelas kalau angkatan tidak dipakai satu pun mahasiswa di kelompok kelasnya', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $angkatan = Semester::factory()->create(['kode' => '20241']);
    $semesterBerjalan = Semester::factory()->active()->create(['kode' => '20261']);
    $kelompok = kelompokAngkatan($prodi, $angkatan);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', $prodi->id)
        ->set('id_kurikulum_matkul', KurikulumMatkul::factory()->create()->id)
        ->set('id_semester', $semesterBerjalan->id)
        ->set('id_kelompok_kelas', $kelompok->id)
        ->set('id_angkatan', $semesterBerjalan->id) // salah: semester berjalan, bukan semester masuk
        ->set('kode', 'X')
        ->call('save')
        ->assertHasErrors('id_angkatan');

    expect(Kelas::where('kode', 'X')->exists())->toBeFalse();
});

it('menerima simpan kelas kalau angkatan cocok dengan semester masuk mahasiswa di kelompoknya', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $angkatan = Semester::factory()->create(['kode' => '20241']);
    $semesterBerjalan = Semester::factory()->active()->create(['kode' => '20261']);
    $kelompok = kelompokAngkatan($prodi, $angkatan);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', $prodi->id)
        ->set('id_kurikulum_matkul', KurikulumMatkul::factory()->create()->id)
        ->set('id_semester', $semesterBerjalan->id)
        ->set('id_kelompok_kelas', $kelompok->id)
        ->set('id_angkatan', $angkatan->id)
        ->set('kode', 'Y')
        ->call('save')
        ->assertHasNoErrors();

    expect(Kelas::where('kode', 'Y')->value('id_angkatan'))->toBe($angkatan->id);
});

it('mengisi angkatan otomatis saat kelas mahasiswa dipilih', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $angkatan = Semester::factory()->create(['kode' => '20241']);
    $kelompok = kelompokAngkatan($prodi, $angkatan);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_kelompok_kelas', $kelompok->id)
        ->assertSet('id_angkatan', $angkatan->id);
});

it('membiarkan angkatan apa pun kalau kelompok kelas belum punya mahasiswa atau tidak dipilih', function () {
    $prodi = Prodi::factory()->create();
    $kelompokKosong = KelompokKelas::factory()->create(['id_prodi' => $prodi->id]);
    $semester = Semester::factory()->create();

    expect(KelasAngkatanService::pesanKetidakcocokan($kelompokKosong->id, $semester->id))->toBeNull();
    expect(KelasAngkatanService::pesanKetidakcocokan(null, $semester->id))->toBeNull();
    expect(KelasAngkatanService::angkatanSaranForKelompokKelas($kelompokKosong->id))->toBeNull();
});

it('tidak menyarankan angkatan kalau kelompok kelasnya campuran beberapa angkatan', function () {
    $prodi = Prodi::factory()->create();
    $angkatanA = Semester::factory()->create(['kode' => '20241']);
    $angkatanB = Semester::factory()->create(['kode' => '20251']);
    $kelompok = kelompokAngkatan($prodi, $angkatanA);
    Mahasiswa::factory()->create([
        'id_prodi' => $prodi->id,
        'id_kelompok_kelas' => $kelompok->id,
        'id_semester_masuk' => $angkatanB->id,
    ]);

    expect(KelasAngkatanService::angkatanSaranForKelompokKelas($kelompok->id))->toBeNull();
    // Campuran tetap valid untuk kedua angkatan yang benar-benar ada.
    expect(KelasAngkatanService::pesanKetidakcocokan($kelompok->id, $angkatanA->id))->toBeNull();
    expect(KelasAngkatanService::pesanKetidakcocokan($kelompok->id, $angkatanB->id))->toBeNull();
    expect(KelasAngkatanService::pesanKetidakcocokan($kelompok->id, Semester::factory()->create()->id))->toBeString();
});

it('menolak store lewat API kalau angkatan tidak cocok dengan kelompok kelas', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $angkatan = Semester::factory()->create(['kode' => '20241']);
    $semesterBerjalan = Semester::factory()->active()->create(['kode' => '20261']);
    $kelompok = kelompokAngkatan($prodi, $angkatan);

    $response = $this->actingAs($admin)->postJson('/api/kelas', [
        [
            'id_kurikulum_matkul' => KurikulumMatkul::factory()->create()->id,
            'id_prodi' => $prodi->id,
            'id_semester' => $semesterBerjalan->id,
            'id_angkatan' => $semesterBerjalan->id,
            'id_kelompok_kelas' => $kelompok->id,
            'kode' => 'Z',
        ],
    ]);

    $response->assertStatus(422)->assertJsonPath('errors.0.field', 'id_angkatan');
    expect(Kelas::where('kode', 'Z')->exists())->toBeFalse();
});

it('menolak update lewat API kalau angkatan diubah jadi tidak cocok dengan kelompok kelas', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $angkatan = Semester::factory()->create(['kode' => '20241']);
    $semesterBerjalan = Semester::factory()->active()->create(['kode' => '20261']);
    $kelompok = kelompokAngkatan($prodi, $angkatan);

    $kelas = Kelas::factory()->create([
        'id_prodi' => $prodi->id,
        'id_semester' => $semesterBerjalan->id,
        'id_angkatan' => $angkatan->id,
        'id_kelompok_kelas' => $kelompok->id,
    ]);

    $this->actingAs($admin)
        ->putJson("/api/kelas/{$kelas->id}", ['id_angkatan' => $semesterBerjalan->id])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['id_angkatan']]);

    expect($kelas->fresh()->id_angkatan)->toBe($angkatan->id);
});

it('menyimpan kelas aktif secara eksplisit saat is_active tidak dikirim lewat API', function () {
    $admin = adminUser();

    $response = $this->actingAs($admin)->postJson('/api/kelas', [
        [
            'id_kurikulum_matkul' => KurikulumMatkul::factory()->create()->id,
            'id_prodi' => Prodi::factory()->create()->id,
            'id_semester' => Semester::factory()->create()->id,
            'id_angkatan' => Semester::factory()->create()->id,
            'kode' => 'W',
        ],
    ]);

    $response->assertCreated();
    // Bukan null: kolomnya NOT NULL, menulis null ke situ gagal di strict mode MySQL.
    expect(Kelas::where('kode', 'W')->value('is_active'))->not->toBeNull();
});
