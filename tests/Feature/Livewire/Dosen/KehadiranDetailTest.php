<?php

use App\Livewire\Dosen\Kehadiran\Detail;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Perkuliahan;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $perkuliahan = Perkuliahan::factory()->create();

    $this->get(route('dosen.kehadiran.detail', $perkuliahan->id))->assertRedirect(route('login'));
});

it('forbids a dosen with no access to the perkuliahan', function () {
    $dosenUser = dosenUser();
    $perkuliahan = Perkuliahan::factory()->create();

    Livewire::actingAs($dosenUser)->test(Detail::class, ['id' => $perkuliahan->id])->assertForbidden();
});

it('prefills existing kehadiran and lists only approved krs', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    $perkuliahan = Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id]);

    $mhsApproved = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mhsApproved->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Kehadiran::create(['id_perkuliahan' => $perkuliahan->id, 'id_mhs' => $mhsApproved->id, 'status' => 'izin', 'keterangan' => 'Sakit demam']);

    $mhsBelumDisetujui = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mhsBelumDisetujui->id, 'id_kelas' => $kelas->id, 'approved_at' => null]);

    $component = Livewire::actingAs($dosenUser)->test(Detail::class, ['id' => $perkuliahan->id]);

    expect($component->instance()->mahasiswa())->toHaveCount(1);
    expect($component->get('form')[$mhsApproved->id]['status'])->toBe('izin');
    expect($component->get('form')[$mhsApproved->id]['keterangan'])->toBe('Sakit demam');
});

it('sorts the mahasiswa list by nim ascending, regardless of krs insertion order', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    $perkuliahan = Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id]);

    $mhsC = Mahasiswa::factory()->create(['nim' => '2024030']);
    $mhsA = Mahasiswa::factory()->create(['nim' => '2024010']);
    $mhsB = Mahasiswa::factory()->create(['nim' => '2024020']);
    // Sengaja dibuat tidak berurutan supaya urutan insersi KRS tidak kebetulan sama dengan NIM.
    Krs::factory()->create(['id_mahasiswa' => $mhsC->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Krs::factory()->create(['id_mahasiswa' => $mhsA->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Krs::factory()->create(['id_mahasiswa' => $mhsB->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);

    $component = Livewire::actingAs($dosenUser)->test(Detail::class, ['id' => $perkuliahan->id]);

    $nims = collect($component->instance()->mahasiswa())->pluck('mahasiswa.nim')->all();
    expect($nims)->toBe(['2024010', '2024020', '2024030']);
});

it('requires a status for every mahasiswa before saving', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create(['id_dosen_pic' => $dosen->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    $perkuliahan = Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id]);

    $mhs = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mhs->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['id' => $perkuliahan->id])
        ->call('save')
        ->assertHasErrors(["form.{$mhs->id}.status"]);

    expect(Kehadiran::where('id_perkuliahan', $perkuliahan->id)->count())->toBe(0);
});

it('bulk saves kehadiran for all mahasiswa', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);
    $perkuliahan = Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id]);

    $mhs1 = Mahasiswa::factory()->create();
    $mhs2 = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mhs1->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Krs::factory()->create(['id_mahasiswa' => $mhs2->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['id' => $perkuliahan->id])
        ->set("form.{$mhs1->id}.status", 'hadir')
        ->set("form.{$mhs2->id}.status", 'alfa')
        ->set("form.{$mhs2->id}.keterangan", 'Tanpa kabar')
        ->call('save')
        ->assertHasNoErrors();

    expect(Kehadiran::where('id_perkuliahan', $perkuliahan->id)->where('id_mhs', $mhs1->id)->value('status'))->toBe('hadir');
    $k2 = Kehadiran::where('id_perkuliahan', $perkuliahan->id)->where('id_mhs', $mhs2->id)->first();
    expect($k2->status)->toBe('alfa');
    expect($k2->keterangan)->toBe('Tanpa kabar');
});
