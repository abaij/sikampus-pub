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
        ->set('tanggalTerbit', '2026-03-17')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::where('key', 'app_transkrip_nama_pejabat')->value('value'))->toBe('Dr. Contoh, M.Kom.');

    $pengaturan = TranskripPdfGenerator::pengaturanPenandatangan();
    expect($pengaturan['jabatan'])->toBe('Rektor Universitas Contoh');
    expect($pengaturan['jabatan_en'])->toBe('Rector Universitas Contoh');
    expect($pengaturan['nip'])->toBe('19800101 200501 1 001');
    expect($pengaturan['kota_terbit'])->toBe('Kab. Bogor');
    expect($pengaturan['tanggal_terbit'])->toBe('2026-03-17');
});

it('memakai tanggal terbit dari pengaturan, bukan tanggal saat transkrip dicetak', function () {
    $mahasiswa = Mahasiswa::factory()->create();
    Setting::updateOrCreate(['key' => 'app_transkrip_tanggal_terbit'], ['value' => '2026-03-17']);

    $payload = (new TranskripPdfGenerator)->payload($mahasiswa);

    expect($payload['tanggal_terbit_id'])->toBe('17 Maret 2026')
        ->and($payload['tanggal_terbit_en'])->toBe('17 March 2026');
});

it('jatuh ke tanggal hari ini kalau tanggal terbit belum diisi', function () {
    $mahasiswa = Mahasiswa::factory()->create();
    Setting::updateOrCreate(['key' => 'app_transkrip_tanggal_terbit'], ['value' => '']);

    $payload = (new TranskripPdfGenerator)->payload($mahasiswa);

    expect($payload['tanggal_terbit_id'])->toBe(
        now()->format('j').' '.[1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int) now()->format('n')].' '.now()->format('Y')
    );
});

it('mengabaikan tanggal terbit yang tidak bisa dibaca dan memakai hari ini', function () {
    $mahasiswa = Mahasiswa::factory()->create();
    // Nilai lama/aneh di tabel settings tidak boleh menggagalkan seluruh cetakan.
    Setting::updateOrCreate(['key' => 'app_transkrip_tanggal_terbit'], ['value' => 'bukan tanggal']);

    $payload = (new TranskripPdfGenerator)->payload($mahasiswa);

    expect($payload['tanggal_terbit_id'])->toContain(now()->format('Y'));
});

it('menolak tanggal terbit yang bukan tanggal di form pengaturan', function () {
    $admin = adminUser();

    Livewire::actingAs($admin)
        ->test(Penandatangan::class)
        ->set('tanggalTerbit', 'bukan tanggal')
        ->call('save')
        ->assertHasErrors('tanggalTerbit');
});

it('pratinjau blok tanda tangan memakai tanggal terbit yang tersimpan', function () {
    $admin = adminUser();
    Setting::updateOrCreate(['key' => 'app_transkrip_tanggal_terbit'], ['value' => '2026-03-17']);
    Setting::updateOrCreate(['key' => 'app_transkrip_kota_terbit'], ['value' => 'Kab. Bogor']);

    Livewire::actingAs($admin)
        ->test(Penandatangan::class)
        ->assertSet('tanggalTerbit', '2026-03-17')
        ->assertSee('Diterbitkan di Kab. Bogor, 17 Maret 2026')
        ->assertSee('Issued in Kab. Bogor, 17 March 2026');
});

it('halaman pengaturan penandatangan dapat dibuka admin', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->get(route('admin.akademik.penandatangan-transkrip'))
        ->assertOk()
        ->assertSee('Penandatangan Transkrip')
        ->assertSee('Tanggal Terbit');
});

// Dipisah dari test di atas: actingAs() bertahan sepanjang satu test, jadi permintaan "tamu"
// di test yang sama sebenarnya masih membawa sesi login dan selalu lolos.
it('mengalihkan tamu dari halaman pengaturan penandatangan ke login', function () {
    $this->get(route('admin.akademik.penandatangan-transkrip'))->assertRedirect(route('login'));
});
