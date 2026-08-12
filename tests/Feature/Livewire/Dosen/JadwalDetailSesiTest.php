<?php

use App\Livewire\Dosen\Jadwal\Detail;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Perkuliahan;
use Carbon\Carbon;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

it('shows the mulai sesi button when the slot has no perkuliahan yet', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $component = Livewire::actingAs($dosenUser)->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]);

    expect($component->instance()->bisaTampilMulaiSesi())->toBeTrue();
    expect($component->instance()->sesiAktif())->toBeNull();
});

it('hides the mulai sesi button while a session is ongoing, and shows selesaikan sesi instead', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    $ongoing = Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null]);

    $component = Livewire::actingAs($dosenUser)->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]);

    expect($component->instance()->bisaTampilMulaiSesi())->toBeFalse();
    expect($component->instance()->sesiAktif()->id)->toBe($ongoing->id);
    $component->assertSee('Selesaikan sesi')->assertDontSee('Mulai sesi');
});

it('hides the mulai sesi button once the last session for the slot has already finished (FE-only rule)', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now()->subHour(), 'waktu_selesai' => now()]);

    $component = Livewire::actingAs($dosenUser)->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]);

    expect($component->instance()->sesiTerakhirSudahSelesai())->toBeTrue();
    expect($component->instance()->bisaTampilMulaiSesi())->toBeFalse();
    $component->assertDontSee('Mulai sesi')->assertDontSee('Selesaikan sesi');
});

it('prefills modalMateriSesi from bahasan when opening the mulai sesi dialog', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'bahasan' => 'Pengenalan konsep dasar']);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('klikMulaiSesi')
        ->assertSet('mulaiDialog', 'konfirmasi_mulai_materi')
        ->assertSet('modalMateriSesi', 'Pengenalan konsep dasar');
});

it('starts a session directly when the current time is within the schedule window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create([
        'id_kelas' => $kelas->id,
        'tanggal' => null,
        'jam_mulai' => '09:45:00',
        'jam_selesai' => '11:00:00',
    ]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->set('modalMateriSesi', 'Topik hari ini')
        ->call('konfirmasiMulaiDariModal')
        ->assertSet('mulaiDialog', 'none');

    $perkuliahan = Perkuliahan::where('id_jadwal', $jadwal->id)->first();
    expect($perkuliahan)->not->toBeNull();
    expect($perkuliahan->materi)->toBe('Topik hari ini');
    expect($perkuliahan->waktu_mulai)->not->toBeNull();
    expect($perkuliahan->waktu_selesai)->toBeNull();
    expect($perkuliahan->created_by)->not->toBeNull();
});

it('shows the luar jadwal warning instead of starting a session when outside the schedule window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create([
        'id_kelas' => $kelas->id,
        'tanggal' => null,
        'jam_mulai' => '14:00:00',
        'jam_selesai' => '16:00:00',
    ]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('konfirmasiMulaiDariModal')
        ->assertSet('mulaiDialog', 'luar_jadwal');

    expect(Perkuliahan::where('id_jadwal', $jadwal->id)->count())->toBe(0);
});

it('still starts a session outside the schedule window after confirming konfirmasiMulaiLuarJadwal', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create([
        'id_kelas' => $kelas->id,
        'tanggal' => null,
        'jam_mulai' => '14:00:00',
        'jam_selesai' => '16:00:00',
    ]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('konfirmasiMulaiDariModal')
        ->assertSet('mulaiDialog', 'luar_jadwal')
        ->call('konfirmasiMulaiLuarJadwal')
        ->assertSet('mulaiDialog', 'none');

    expect(Perkuliahan::where('id_jadwal', $jadwal->id)->count())->toBe(1);
});

it('activates a jadwal_dosen row for the dosen when starting a session, if one did not already exist', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create([
        'id_kelas' => $kelas->id,
        'tanggal' => null,
        'jam_mulai' => '09:45:00',
        'jam_selesai' => '11:00:00',
    ]);
    // Akses lewat is_pic, bukan jadwal_dosen — belum ada baris jadwal_dosen untuk dosen ini.
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    expect(JadwalDosen::where('id_jadwal', $jadwal->id)->where('id_dosen', $dosen->id)->exists())->toBeFalse();

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('konfirmasiMulaiDariModal');

    $jadwalDosen = JadwalDosen::where('id_jadwal', $jadwal->id)->where('id_dosen', $dosen->id)->first();
    expect($jadwalDosen)->not->toBeNull();
    expect($jadwalDosen->status)->toBe('active');
});

it('sets a submit error and does not create a duplicate session if one already started concurrently', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-12 10:00:00'));

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create([
        'id_kelas' => $kelas->id,
        'tanggal' => null,
        'jam_mulai' => '09:45:00',
        'jam_selesai' => '11:00:00',
    ]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $component = Livewire::actingAs($dosenUser)->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]);

    // Sesi ongoing muncul di antara render awal dan submit (mis. dari tab lain / request lain).
    Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null]);

    $component->call('konfirmasiMulaiDariModal');

    expect($component->get('submitError'))->not->toBe('');
    expect(Perkuliahan::where('id_jadwal', $jadwal->id)->count())->toBe(1);
});

it('prefills formRealisasiMateriSelesai from the ongoing session materi when opening the selesai dialog', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null, 'materi' => 'Materi berjalan']);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('klikSelesaikanSesi')
        ->assertSet('selesaiDialogOpen', true)
        ->assertSet('formRealisasiMateriSelesai', 'Materi berjalan');
});

it('ends the ongoing session with realisasi materi and clears the modal state', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    $sesi = Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now()->subMinutes(30), 'waktu_selesai' => null]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->set('formRealisasiMateriSelesai', 'Sudah dibahas semua topik')
        ->call('submitSelesaiSesi')
        ->assertSet('selesaiDialogOpen', false);

    $sesi->refresh();
    expect($sesi->waktu_selesai)->not->toBeNull();
    expect($sesi->realisasi_materi)->toBe('Sudah dibahas semua topik');
});

it('rejects ending a session when none is ongoing for this slot', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('submitSelesaiSesi')
        ->assertStatus(404);
});
