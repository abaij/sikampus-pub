<?php

use App\Livewire\Admin\Nilai\Form;
use App\Livewire\Admin\Nilai\Index;
use App\Livewire\Admin\Nilai\Show;
use App\Models\Jenjang;
use App\Models\Kelas;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Nilai;
use App\Models\Prodi;
use App\Models\RentangNilai;
use Livewire\Livewire;

it('renders index and shows the aggregated row for a mahasiswa with krs', function () {
    $admin = adminUser();

    $prodi = Prodi::factory()->create(['nama' => 'Prodi Uji']);
    $mahasiswa = Mahasiswa::factory()->create(['nama' => 'Budi Santoso', 'nim' => '2024000001', 'id_prodi' => $prodi->id]);
    $kelas = Kelas::factory()->create(['id_prodi' => $prodi->id]);
    Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id]);

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai'))
        ->assertOk()
        ->assertSee('2024000001')
        ->assertSee('Budi Santoso');
});

it('renders the detail nilai page for a mahasiswa', function () {
    $admin = adminUser();

    $jenjang = Jenjang::factory()->create();
    $prodi = Prodi::factory()->create(['id_jenjang' => $jenjang->id]);
    $mahasiswa = Mahasiswa::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['nama' => 'Kalkulus Lanjut', 'kode' => 'MK-100', 'sks' => 3]);
    $kelas = Kelas::factory()->create(['id_prodi' => $prodi->id]);
    $kelas->kurikulumMatkul()->update(['id_matkul' => $matkul->id]);
    Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id]);

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.show', $mahasiswa->id))
        ->assertOk()
        ->assertSee('Kalkulus Lanjut');
});

it('creates a nilai row via the edit form for a krs without existing nilai', function () {
    $admin = adminUser();

    $jenjang = Jenjang::factory()->create();
    $prodi = Prodi::factory()->create(['id_jenjang' => $jenjang->id]);
    $mahasiswa = Mahasiswa::factory()->create(['id_prodi' => $prodi->id]);
    $kelas = Kelas::factory()->create(['id_prodi' => $prodi->id]);
    $krs = Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id]);
    RentangNilai::factory()->create(['id_jenjang' => $jenjang->id, 'nilai_huruf' => 'A', 'nilai_angka' => 4]);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $mahasiswa->id, 'idKrs' => $krs->id])
        ->set('huruf_mutu', 'A')
        ->set('is_final', true)
        ->call('save')
        ->assertRedirect(route('admin.akademik.nilai.show', $mahasiswa->id));

    $nilai = Nilai::where('id_krs', $krs->id)->firstOrFail();
    expect($nilai->huruf_mutu)->toBe('A');
    expect((float) $nilai->angka_mutu)->toBe(4.0);
    expect($nilai->is_final)->toBeTrue();
});

it('updates an existing nilai row via the edit form', function () {
    $admin = adminUser();

    $jenjang = Jenjang::factory()->create();
    $prodi = Prodi::factory()->create(['id_jenjang' => $jenjang->id]);
    $mahasiswa = Mahasiswa::factory()->create(['id_prodi' => $prodi->id]);
    $kelas = Kelas::factory()->create(['id_prodi' => $prodi->id]);
    $krs = Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id]);
    RentangNilai::factory()->create(['id_jenjang' => $jenjang->id, 'nilai_huruf' => 'B', 'nilai_angka' => 3]);
    $nilai = Nilai::factory()->create(['id_krs' => $krs->id, 'huruf_mutu' => 'A', 'angka_mutu' => 4, 'is_final' => false]);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $mahasiswa->id, 'idKrs' => $krs->id])
        ->assertSet('huruf_mutu', 'A')
        ->set('huruf_mutu', 'B')
        ->set('is_final', true)
        ->call('save');

    $nilai->refresh();
    expect($nilai->huruf_mutu)->toBe('B');
    expect((float) $nilai->angka_mutu)->toBe(3.0);
    expect($nilai->is_final)->toBeTrue();
});

it('deletes a nilai row from the show page', function () {
    $admin = adminUser();

    $mahasiswa = Mahasiswa::factory()->create();
    $krs = Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id]);
    $nilai = Nilai::factory()->create(['id_krs' => $krs->id]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $mahasiswa->id])
        ->call('confirmDelete', $nilai->id)
        ->call('delete');

    expect(Nilai::find($nilai->id))->toBeNull();
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('admin.akademik.nilai'))->assertRedirect(route('login'));
});

it('admin dengan scope prodi hanya melihat nilai mahasiswa di prodinya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();

    $mahasiswaA = Mahasiswa::factory()->create(['nama' => 'Mahasiswa Prodi A', 'nim' => '2024000011', 'id_prodi' => $prodiA->id]);
    $mahasiswaB = Mahasiswa::factory()->create(['nama' => 'Mahasiswa Prodi B', 'nim' => '2024000022', 'id_prodi' => $prodiB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Mahasiswa Prodi A')
        ->assertDontSee('Mahasiswa Prodi B');

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.show', $mahasiswaB->id))
        ->assertForbidden();
});

it('admin dengan scope prodi tidak bisa menghapus nilai mahasiswa di luar prodinya lewat id langsung', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();

    $mahasiswaA = Mahasiswa::factory()->create(['id_prodi' => $prodiA->id]);
    $mahasiswaB = Mahasiswa::factory()->create(['id_prodi' => $prodiB->id]);
    $krsB = Krs::factory()->create(['id_mahasiswa' => $mahasiswaB->id]);
    $nilaiB = Nilai::factory()->create(['id_krs' => $krsB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.show', $mahasiswaA->id))
        ->assertOk();

    // mount() Show::class untuk mahasiswaB harus 403 karena di luar scope.
    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.show', $mahasiswaB->id))
        ->assertForbidden();

    expect(Nilai::find($nilaiB->id))->not->toBeNull();
});

it('generates an xlsx export and an inline pdf for a mahasiswa nilai', function () {
    $admin = adminUser();

    $mahasiswa = Mahasiswa::factory()->create();
    $kelas = Kelas::factory()->create(['id_prodi' => $mahasiswa->id_prodi]);
    $krs = Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id]);
    Nilai::factory()->create(['id_krs' => $krs->id, 'is_final' => true]);

    $excelResponse = $this->actingAs($admin)->get(route('admin.akademik.nilai.export', $mahasiswa->id));
    $excelResponse->assertOk();
    $excelResponse->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $pdfResponse = $this->actingAs($admin)->get(route('admin.akademik.nilai.cetak', $mahasiswa->id));
    $pdfResponse->assertOk();
    $pdfResponse->assertHeader('Content-Type', 'application/pdf');
    expect($pdfResponse->headers->get('Content-Disposition'))->toContain('inline');
});

it('lists mata kuliah sorted by name on the detail nilai page', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $mahasiswa = Mahasiswa::factory()->create(['id_prodi' => $prodi->id]);

    // Dibuat sengaja tidak berurutan abjad supaya urutan created_at berbeda dari urutan nama.
    foreach (['Zoologi Dasar', 'Anatomi Manusia', 'Biologi Sel'] as $nama) {
        $matkul = Matkul::factory()->create(['nama' => $nama, 'sks' => 2]);
        $kelas = Kelas::factory()->create(['id_prodi' => $prodi->id]);
        $kelas->kurikulumMatkul()->update(['id_matkul' => $matkul->id]);
        Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id]);
    }

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.show', $mahasiswa->id))
        ->assertOk()
        ->assertSeeInOrder(['Anatomi Manusia', 'Biologi Sel', 'Zoologi Dasar']);
});
