<?php

use App\Livewire\Mahasiswa\KeringananBiaya\Index as KeringananBiayaIndex;
use App\Livewire\Mahasiswa\Tagihan\Index as TagihanIndex;
use App\Models\JenisKeringananBiaya;
use App\Models\KeringananBiaya;
use App\Models\KomponenBiaya;
use App\Models\Mahasiswa;
use App\Models\Pembayaran;
use App\Models\Semester;
use App\Models\Tagihan;
use App\Models\TagihanRinci;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function biayaMahasiswaUser(): array
{
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(['id_user' => $user->id]);

    return [$user, $mahasiswa];
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('mahasiswa.tagihan'))->assertRedirect(route('login'));
    $this->get(route('mahasiswa.pembayaran'))->assertRedirect(route('login'));
    $this->get(route('mahasiswa.keringanan-biaya'))->assertRedirect(route('login'));
});

it('lists only tagihan that have already become effective, with rincian and sisa tagihan', function () {
    [$user, $mahasiswa] = biayaMahasiswaUser();
    $semester = Semester::factory()->create(['nama' => 'Ganjil 2025/2026']);
    $tagihan = Tagihan::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'no_tagihan' => 'TGH-001',
        'total' => 1000000,
        'tanggal_tagihan' => now()->subDay(),
    ]);
    $komponen = KomponenBiaya::factory()->create(['nama' => 'SPP', 'kode' => 'SPP-01']);
    TagihanRinci::factory()->create(['id_tagihan' => $tagihan->id, 'id_komponen_biaya' => $komponen->id, 'nominal' => 1000000]);

    // Tagihan yang belum berlaku (tanggal_tagihan di masa depan) tidak boleh muncul.
    Tagihan::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'tanggal_tagihan' => now()->addWeek(),
    ]);

    $this->actingAs($user)->get(route('mahasiswa.tagihan'))
        ->assertOk()
        ->assertSee('TGH-001')
        ->assertSee('SPP')
        ->assertSee('Belum Lunas');

    Livewire::actingAs($user)
        ->test(TagihanIndex::class)
        ->assertSet('tagihanList', fn ($list) => $list->count() === 1);
});

it('lets a mahasiswa submit a full payment for their own tagihan', function () {
    Storage::fake('public');

    [$user, $mahasiswa] = biayaMahasiswaUser();
    $tagihan = Tagihan::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'total' => 1000000,
        'tanggal_tagihan' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test(TagihanIndex::class)
        ->call('openBayarModal', $tagihan->id)
        ->set('buktiFile', UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))
        ->call('submitBayar')
        ->assertHasNoErrors();

    $pembayaran = Pembayaran::where('id_tagihan', $tagihan->id)->firstOrFail();
    expect((float) $pembayaran->nominal)->toBe(1000000.0);
    expect($pembayaran->approved_at)->toBeNull();
    Storage::disk('public')->assertExists($pembayaran->bukti_bayar);
});

it('rejects a partial payment nominal larger than the sisa that can be paid', function () {
    [$user, $mahasiswa] = biayaMahasiswaUser();
    $tagihan = Tagihan::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'total' => 1000000,
        'tanggal_tagihan' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test(TagihanIndex::class)
        ->call('openBayarModal', $tagihan->id)
        ->set('tipeBayar', 'sebagian')
        ->set('nominalPartial', '5000000')
        ->set('buktiFile', UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf'))
        ->call('submitBayar')
        ->assertHasErrors('nominalPartial');

    expect(Pembayaran::where('id_tagihan', $tagihan->id)->count())->toBe(0);
});

it('shows a mahasiswa their own pembayaran history only', function () {
    [$user, $mahasiswa] = biayaMahasiswaUser();
    $tagihan = Tagihan::factory()->create(['id_mahasiswa' => $mahasiswa->id]);
    Pembayaran::factory()->create(['id_tagihan' => $tagihan->id, 'no_pembayaran' => 'PAY-MINE', 'nominal' => 500000]);

    $otherMahasiswa = Mahasiswa::factory()->create();
    $otherTagihan = Tagihan::factory()->create(['id_mahasiswa' => $otherMahasiswa->id]);
    Pembayaran::factory()->create(['id_tagihan' => $otherTagihan->id, 'no_pembayaran' => 'PAY-OTHER']);

    $response = $this->actingAs($user)->get(route('mahasiswa.pembayaran'))->assertOk();
    $response->assertSee('PAY-MINE');
    $response->assertDontSee('PAY-OTHER');
});

it('lists every active jenis keringanan biaya as pengajuan options', function () {
    // Dulu daftar ini disaring `nominal = 0` karena submit menyalin nominal master mentah-mentah.
    // Persentase kini diselesaikan saat approve, jadi seluruh jenis aktif boleh diajukan.
    [$user] = biayaMahasiswaUser();
    JenisKeringananBiaya::factory()->create(['nama' => 'Beasiswa Prestasi', 'nominal' => 0, 'is_active' => true]);
    JenisKeringananBiaya::factory()->create(['nama' => 'Diskon Tetap 50%', 'nominal' => 50, 'is_active' => true]);
    JenisKeringananBiaya::factory()->create(['nama' => 'Sudah Ditutup', 'nominal' => 0, 'is_active' => false]);

    Livewire::actingAs($user)
        ->test(KeringananBiayaIndex::class)
        ->assertSet('jenisOptions', fn ($options) => $options->pluck('nama')->all() === ['Beasiswa Prestasi', 'Diskon Tetap 50%']);
});

it('lets a mahasiswa submit a keringanan biaya pengajuan', function () {
    [$user, $mahasiswa] = biayaMahasiswaUser();
    $jenis = JenisKeringananBiaya::factory()->create(['nama' => 'Beasiswa Prestasi', 'nominal' => 0, 'is_active' => true]);
    $semester = Semester::factory()->create();

    Livewire::actingAs($user)
        ->test(KeringananBiayaIndex::class)
        ->call('openFormModal')
        ->set('idJenis', (string) $jenis->id)
        ->set('idSemester', (string) $semester->id)
        ->set('keterangan', 'Mohon dipertimbangkan')
        ->call('submit')
        ->assertHasNoErrors();

    $row = KeringananBiaya::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect($row->id_jenis_keringanan_biaya)->toBe($jenis->id);
    expect($row->status)->toBe('pending');
});

it('rejects a duplicate keringanan biaya pengajuan for the same jenis and semester', function () {
    [$user, $mahasiswa] = biayaMahasiswaUser();
    $jenis = JenisKeringananBiaya::factory()->create(['nominal' => 0, 'is_active' => true]);
    $semester = Semester::factory()->create();
    KeringananBiaya::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_jenis_keringanan_biaya' => $jenis->id, 'id_semester' => $semester->id]);

    Livewire::actingAs($user)
        ->test(KeringananBiayaIndex::class)
        ->set('idJenis', (string) $jenis->id)
        ->set('idSemester', (string) $semester->id)
        ->call('submit')
        ->assertHasErrors('idSemester');

    expect(KeringananBiaya::where('id_mahasiswa', $mahasiswa->id)->count())->toBe(1);
});
