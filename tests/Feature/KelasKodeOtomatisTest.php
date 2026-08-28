<?php

use App\Livewire\Admin\Kelas\Form;
use App\Models\Kelas;
use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;
use App\Models\Matkul;
use App\Models\Prodi;
use App\Models\Semester;
use App\Services\KelasKodeGenerator;
use Livewire\Livewire;

it('menyingkat nama kelompok kelas jadi kode maksimal 5 karakter', function (string $nama, ?string $harapan) {
    expect(KelasKodeGenerator::dariNama($nama))->toBe($harapan);
})->with([
    // Satu kata + tahun: huruf awal kata + 2 digit tahun.
    ['Bidan 2024', 'BID24'],
    ['KEB 2022', 'KEB22'],
    ['ILKOM 2025', 'ILK25'],
    ['SIPIL 2021', 'SIP21'],
    // Huruf seksi di akhir diabaikan — A dan B sengaja menghasilkan kode yang sama.
    ['KEP 2023 A', 'KEP23'],
    ['KEP 2023 B', 'KEP23'],
    // Dua kata atau lebih: inisial tiap kata.
    ['KEP REG A 2024', 'KR24'],
    ['REG SORE SEM I', 'RSS'],
    ['Kelas Percobaan', 'KP'],
    // Nama tanpa huruf dipakai apa adanya.
    ['22201', '22201'],
    // Nama panjang tanpa tahun tetap dipotong 5 karakter.
    ['Reguler', 'REGUL'],
    ['', null],
    ['   ', null],
]);

it('membuat kode dari nama kelas mahasiswa saat admin mengosongkan isian kode', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kelompok = KelompokKelas::factory()->create(['nama' => 'Bidan 2024', 'id_prodi' => $prodi->id]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', $prodi->id)
        ->set('id_kurikulum_matkul', KurikulumMatkul::factory()->create()->id)
        ->set('id_semester', Semester::factory()->create()->id)
        ->set('id_angkatan', Semester::factory()->create()->id)
        ->set('id_kelompok_kelas', $kelompok->id)
        ->set('kode', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Kelas::where('id_kelompok_kelas', $kelompok->id)->value('kode'))->toBe('BID24');
});

it('tidak menimpa kode yang diisi admin sendiri', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kelompok = KelompokKelas::factory()->create(['nama' => 'Bidan 2024', 'id_prodi' => $prodi->id]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', $prodi->id)
        ->set('id_kurikulum_matkul', KurikulumMatkul::factory()->create()->id)
        ->set('id_semester', Semester::factory()->create()->id)
        ->set('id_angkatan', Semester::factory()->create()->id)
        ->set('id_kelompok_kelas', $kelompok->id)
        ->set('kode', 'A')
        ->call('save')
        ->assertHasNoErrors();

    expect(Kelas::where('id_kelompok_kelas', $kelompok->id)->value('kode'))->toBe('A');
});

it('mengisi ulang kode saat admin mengosongkannya lewat form edit', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kelompok = KelompokKelas::factory()->create(['nama' => 'KEB 2022', 'id_prodi' => $prodi->id]);
    $kelas = Kelas::factory()->create([
        'kode' => 'LAMA',
        'id_prodi' => $prodi->id,
        'id_kelompok_kelas' => $kelompok->id,
    ]);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $kelas->id])
        ->assertSet('kode', 'LAMA')
        ->set('kode', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($kelas->fresh()->kode)->toBe('KEB22');
});

it('jatuh ke kode mata kuliah kalau kelas mahasiswa tidak dipilih', function () {
    $admin = adminUser();
    $matkul = Matkul::factory()->create(['kode' => 'BID501']);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', Prodi::factory()->create()->id)
        ->set('id_kurikulum_matkul', $kurikulumMatkul->id)
        ->set('id_semester', Semester::factory()->create()->id)
        ->set('id_angkatan', Semester::factory()->create()->id)
        ->set('kode', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Kelas::where('id_kurikulum_matkul', $kurikulumMatkul->id)->value('kode'))->toBe('BID50');
});

it('membuat kode otomatis lewat API store dan update', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kelompok = KelompokKelas::factory()->create(['nama' => 'MNJ 2024', 'id_prodi' => $prodi->id]);

    $this->actingAs($admin)->postJson('/api/kelas', [
        [
            'id_kurikulum_matkul' => KurikulumMatkul::factory()->create()->id,
            'id_prodi' => $prodi->id,
            'id_semester' => Semester::factory()->create()->id,
            'id_angkatan' => Semester::factory()->create()->id,
            'id_kelompok_kelas' => $kelompok->id,
            'kode' => '',
        ],
    ])->assertCreated();

    $kelas = Kelas::where('id_kelompok_kelas', $kelompok->id)->firstOrFail();
    expect($kelas->kode)->toBe('MNJ24');

    $lain = KelompokKelas::factory()->create(['nama' => 'HKM 2025', 'id_prodi' => $prodi->id]);
    $this->actingAs($admin)
        ->putJson("/api/kelas/{$kelas->id}", ['kode' => '', 'id_kelompok_kelas' => $lain->id])
        ->assertOk();

    expect($kelas->fresh()->kode)->toBe('HKM25');
});

it('membiarkan kode null kalau tidak ada bahan sama sekali', function () {
    expect(KelasKodeGenerator::untukKelas(null, null))->toBeNull();
});
