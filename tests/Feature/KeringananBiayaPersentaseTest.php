<?php

use App\Livewire\Admin\KeringananBiaya\Form as AdminForm;
use App\Livewire\Admin\KeringananBiaya\Index as AdminIndex;
use App\Livewire\Mahasiswa\KeringananBiaya\Index as MahasiswaKeringanan;
use App\Models\JenisKeringananBiaya;
use App\Models\KeringananBiaya;
use App\Models\Mahasiswa;
use App\Models\Semester;
use App\Models\Tagihan;
use App\Models\User;
use App\Services\KeringananBiayaKreditService;
use Livewire\Livewire;

/**
 * keringanan_biaya.nominal SELALU rupiah — persentase diselesaikan sekali saat approve.
 * Test di sini menjaga batas itu: persen tidak boleh bocor ke kolom nominal.
 */
function skenarioPersentase(float $persen = 10, float $totalTagihan = 15000000): array
{
    $semester = Semester::factory()->create();
    $mahasiswa = Mahasiswa::factory()->create();
    $jenis = JenisKeringananBiaya::factory()->create([
        'nama' => 'Diskon Tipe A',
        'is_persentase' => true,
        'nominal' => $persen,
        'is_active' => true,
    ]);

    if ($totalTagihan > 0) {
        Tagihan::factory()->create([
            'id_mahasiswa' => $mahasiswa->id,
            'id_semester' => $semester->id,
            'total' => $totalTagihan,
        ]);
    }

    return compact('semester', 'mahasiswa', 'jenis');
}

it('menampilkan jenis persentase dan jenis rupiah di form pengajuan mahasiswa', function () {
    $user = User::factory()->create(['role' => 'mahasiswa']);
    Mahasiswa::factory()->create(['id_user' => $user->id]);

    JenisKeringananBiaya::factory()->create(['nama' => 'KIP', 'is_persentase' => false, 'nominal' => 0]);
    JenisKeringananBiaya::factory()->create(['nama' => 'Diskon Tipe A', 'is_persentase' => true, 'nominal' => 10]);
    JenisKeringananBiaya::factory()->create(['nama' => 'Nonaktif', 'is_active' => false]);

    Livewire::actingAs($user)->test(MahasiswaKeringanan::class)
        ->tap(fn ($c) => expect($c->instance()->jenisOptions->pluck('nama')->all())
            ->toBe(['Diskon Tipe A', 'KIP']));
});

it('menyimpan pengajuan persentase dengan nominal 0 dan snapshot persennya', function () {
    ['semester' => $semester, 'jenis' => $jenis] = skenarioPersentase();
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(['id_user' => $user->id]);

    Livewire::actingAs($user)->test(MahasiswaKeringanan::class)
        ->call('openFormModal')
        ->set('idJenis', (string) $jenis->id)
        ->set('idSemester', (string) $semester->id)
        ->call('submit')
        ->assertHasNoErrors();

    $row = KeringananBiaya::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    // Persen TIDAK boleh mendarat di kolom nominal — di sanalah bug Rp 10 dulu terjadi.
    expect((float) $row->nominal)->toBe(0.0)
        ->and((float) $row->persentase)->toBe(10.0)
        ->and($row->status)->toBe('pending');
});

it('mengubah persentase jadi rupiah saat admin menyetujui', function () {
    ['semester' => $semester, 'mahasiswa' => $mahasiswa, 'jenis' => $jenis] = skenarioPersentase(10, 15000000);
    $admin = adminUser();

    $row = KeringananBiaya::factory()->create([
        'id_jenis_keringanan_biaya' => $jenis->id,
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'nominal' => 0,
        'persentase' => 10,
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)->test(AdminForm::class, ['id' => $row->id])
        ->set('status', 'approved')
        ->call('save')
        ->assertHasNoErrors();

    $row->refresh();
    expect((float) $row->nominal)->toBe(1500000.0)
        ->and((float) $row->dasar_perhitungan)->toBe(15000000.0)
        ->and($row->dasar_dihitung_pada)->not->toBeNull();
});

it('menolak approve kalau semester itu belum punya tagihan', function () {
    ['semester' => $semester, 'mahasiswa' => $mahasiswa, 'jenis' => $jenis] = skenarioPersentase(10, 0);
    $admin = adminUser();

    $row = KeringananBiaya::factory()->create([
        'id_jenis_keringanan_biaya' => $jenis->id,
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'nominal' => 0,
        'persentase' => 10,
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)->test(AdminForm::class, ['id' => $row->id])
        ->set('status', 'approved')
        ->call('save')
        ->assertHasErrors('status');

    // Rp 0 tidak bisa dibedakan dari "sudah dihitung, hasilnya nol" — jadi jangan disimpan.
    expect($row->fresh()->status)->toBe('pending');
});

it('kredit hasil persentase benar-benar mengurangi tagihan', function () {
    ['semester' => $semester, 'mahasiswa' => $mahasiswa, 'jenis' => $jenis] = skenarioPersentase(10, 15000000);
    $admin = adminUser();

    $row = KeringananBiaya::factory()->create([
        'id_jenis_keringanan_biaya' => $jenis->id,
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'nominal' => 0,
        'persentase' => 10,
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)->test(AdminForm::class, ['id' => $row->id])
        ->set('status', 'approved')
        ->call('save')
        ->assertHasNoErrors();

    $tagihan = Tagihan::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect(KeringananBiayaKreditService::kreditUntukTagihan($tagihan))->toBe(1500000.0);
});

it('tidak mengubah snapshot keringanan yang sudah disetujui saat master jenis diedit', function () {
    ['semester' => $semester, 'mahasiswa' => $mahasiswa, 'jenis' => $jenis] = skenarioPersentase(10, 15000000);
    $admin = adminUser();

    $row = KeringananBiaya::factory()->create([
        'id_jenis_keringanan_biaya' => $jenis->id,
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'nominal' => 0,
        'persentase' => 10,
        'status' => 'pending',
    ]);

    Livewire::actingAs($admin)->test(AdminForm::class, ['id' => $row->id])
        ->set('status', 'approved')
        ->call('save');

    $jenis->update(['nominal' => 50]);

    expect((float) $row->fresh()->nominal)->toBe(1500000.0)
        ->and((float) $row->fresh()->persentase)->toBe(10.0);
});

it('menghitung ulang nominal saat tagihan semester bertambah', function () {
    ['semester' => $semester, 'mahasiswa' => $mahasiswa, 'jenis' => $jenis] = skenarioPersentase(10, 15000000);
    $admin = adminUser();

    $row = KeringananBiaya::factory()->create([
        'id_jenis_keringanan_biaya' => $jenis->id,
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'nominal' => 1500000,
        'persentase' => 10,
        'dasar_perhitungan' => 15000000,
        'status' => 'approved',
    ]);

    Tagihan::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_semester' => $semester->id,
        'total' => 5000000,
    ]);

    Livewire::actingAs($admin)->test(AdminIndex::class)->call('hitungUlang', $row->id);

    expect((float) $row->fresh()->nominal)->toBe(2000000.0)
        ->and((float) $row->fresh()->dasar_perhitungan)->toBe(20000000.0);
});

it('tidak mengambil nominal dari request pada pengajuan mandiri lewat API', function () {
    ['semester' => $semester, 'jenis' => $jenis] = skenarioPersentase();
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(['id_user' => $user->id]);

    $this->actingAs($user)->postJson('/api/keringanan-biaya-saya', [
        'id_jenis_keringanan_biaya' => $jenis->id,
        'id_semester' => $semester->id,
        'nominal' => 999999999, // dikirim mahasiswa, harus diabaikan
    ])->assertCreated();

    $row = KeringananBiaya::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect((float) $row->nominal)->toBe(0.0)
        ->and((float) $row->persentase)->toBe(10.0);
});

it('menolak pengajuan mandiri untuk jenis yang tidak aktif', function () {
    $semester = Semester::factory()->create();
    $user = User::factory()->create(['role' => 'mahasiswa']);
    Mahasiswa::factory()->create(['id_user' => $user->id]);
    $jenis = JenisKeringananBiaya::factory()->create(['is_active' => false]);

    $this->actingAs($user)->postJson('/api/keringanan-biaya-saya', [
        'id_jenis_keringanan_biaya' => $jenis->id,
        'id_semester' => $semester->id,
    ])->assertStatus(422);
});

it('membiarkan jenis rupiah memakai nominal master apa adanya', function () {
    $semester = Semester::factory()->create();
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(['id_user' => $user->id]);
    $jenis = JenisKeringananBiaya::factory()->create(['is_persentase' => false, 'nominal' => 750000]);

    Livewire::actingAs($user)->test(MahasiswaKeringanan::class)
        ->call('openFormModal')
        ->set('idJenis', (string) $jenis->id)
        ->set('idSemester', (string) $semester->id)
        ->call('submit')
        ->assertHasNoErrors();

    $row = KeringananBiaya::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    expect((float) $row->nominal)->toBe(750000.0)
        ->and($row->persentase)->toBeNull();
});
