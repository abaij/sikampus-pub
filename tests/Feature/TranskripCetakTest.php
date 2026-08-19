<?php

use App\Livewire\Admin\Transkrip\Penandatangan;
use App\Models\Mahasiswa;
use App\Models\Setting;
use App\Models\Yudisium;
use App\Services\TranskripPdfGenerator;
use Livewire\Livewire;

it('admin dapat mencetak transkrip nilai dalam format PDF', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create();

    $response = $this->actingAs($admin)->get(route('admin.akademik.nilai.transkrip', $mahasiswa->id));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    // "inline", bukan attachment — transkrip dibuka di tab baru untuk diperiksa dulu.
    expect($response->headers->get('content-disposition'))->toContain('inline');
});

it('halaman detail nilai menawarkan transkrip dan laporan pada tombol cetak', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.show', $mahasiswa->id))
        ->assertOk()
        ->assertSee('Transkrip Nilai')
        ->assertSee('Laporan Nilai')
        ->assertSee(route('admin.akademik.nilai.transkrip', $mahasiswa->id))
        ->assertSee(route('admin.akademik.nilai.cetak', $mahasiswa->id));
});

it('transkrip memakai nomor ijazah dan nomor transkrip dari tabel yudisium', function () {
    $mahasiswa = Mahasiswa::factory()->create();

    Yudisium::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'no_ijazah' => '1234/IJZ/2026',
        'no_transkrip' => '5678/TRK/2026',
    ]);

    $payload = (new TranskripPdfGenerator)->payload($mahasiswa->fresh());

    expect($payload['no_ijazah'])->toBe('1234/IJZ/2026');
    expect($payload['no_transkrip'])->toBe('5678/TRK/2026');
});

it('transkrip tetap bisa dicetak untuk mahasiswa yang belum diyudisium', function () {
    $admin = adminUser();
    $mahasiswa = Mahasiswa::factory()->create();

    $payload = (new TranskripPdfGenerator)->payload($mahasiswa);
    expect($payload['no_ijazah'])->toBe('');
    expect($payload['no_transkrip'])->toBe('');

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.transkrip', $mahasiswa->id))
        ->assertOk();
});

it('menolak cetak transkrip mahasiswa di luar scope prodi admin', function () {
    $admin = adminUser('admin_akademik');
    $mahasiswa = Mahasiswa::factory()->create();

    // Batasi admin ke prodi lain, bukan prodi mahasiswa di atas.
    scopeAdminToProdi($admin, $mahasiswa->id_prodi + 1);

    $this->actingAs($admin)
        ->get(route('admin.akademik.nilai.transkrip', $mahasiswa->id))
        ->assertForbidden();
});

it('pengaturan penandatangan tersimpan dan terbaca kembali oleh generator transkrip', function () {
    $admin = adminUser();

    Livewire::actingAs($admin)
        ->test(Penandatangan::class)
        ->set('jabatan', 'Rektor Universitas Contoh')
        ->set('jabatanEn', 'Rector Universitas Contoh')
        ->set('namaPejabat', 'Dr. Contoh, M.Kom.')
        ->set('nip', '19800101 200501 1 001')
        ->set('kotaTerbit', 'Kab. Bogor')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::where('key', 'app_transkrip_nama_pejabat')->value('value'))->toBe('Dr. Contoh, M.Kom.');

    $pengaturan = TranskripPdfGenerator::pengaturanPenandatangan();
    expect($pengaturan['jabatan'])->toBe('Rektor Universitas Contoh');
    expect($pengaturan['jabatan_en'])->toBe('Rector Universitas Contoh');
    expect($pengaturan['nip'])->toBe('19800101 200501 1 001');
    expect($pengaturan['kota_terbit'])->toBe('Kab. Bogor');
});

it('halaman pengaturan penandatangan dapat dibuka admin', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.penandatangan-transkrip'))
        ->assertOk()
        ->assertSee('Penandatangan Transkrip');
});

// Dipisah dari test di atas: actingAs() bertahan sepanjang satu test, jadi permintaan "tamu"
// di test yang sama sebenarnya masih membawa sesi login dan selalu lolos.
it('mengalihkan tamu dari halaman pengaturan penandatangan ke login', function () {
    $this->get(route('admin.akademik.penandatangan-transkrip'))->assertRedirect(route('login'));
});
