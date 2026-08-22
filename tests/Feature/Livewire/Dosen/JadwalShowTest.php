<?php

use App\Livewire\Dosen\Jadwal\Detail;
use App\Livewire\Dosen\Jadwal\Show;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JenisKuliah;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Perkuliahan;
use App\Models\Ruangan;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $kelas = Kelas::factory()->create();

    $this->get(route('dosen.jadwal.show', ['kelasId' => $kelas->id]))->assertRedirect(route('login'));
});

it('forbids a dosen who does not teach this kelas', function () {
    $dosenUser = dosenUser();
    $kelas = Kelas::factory()->create();

    Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])->assertForbidden();
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);
    $kelas = Kelas::factory()->create();

    $this->actingAs($mahasiswa)->get(route('dosen.jadwal.show', ['kelasId' => $kelas->id]))->assertForbidden();
});

it('shows the approved krs count and the pic flag for the assigned dosen', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $mahasiswaDiterima = Mahasiswa::factory()->create();
    $mahasiswaBelum = Mahasiswa::factory()->create();
    Krs::factory()->create(['id_mahasiswa' => $mahasiswaDiterima->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Krs::factory()->create(['id_mahasiswa' => $mahasiswaBelum->id, 'id_kelas' => $kelas->id, 'approved_at' => null]);

    $component = Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id]);

    $component->assertOk()->assertSee('Dosen Penanggung Jawab');
    expect($component->instance()->jumlahMahasiswa())->toBe(1);
});

it('marks a jadwal slot as sedang berlangsung when a perkuliahan session has started but not ended', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'is_active' => true]);
    Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null]);

    $rows = Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])->instance()->jadwalRows();

    expect($rows[0]['sesi_status'])->toBe('sedang_berlangsung');
});

it('shows the Status sesi column with the sesi badge in the slot jadwal pertemuan table', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'is_active' => true]);
    Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null]);

    $this->actingAs($dosenUser)
        ->get(route('dosen.jadwal.show', ['kelasId' => $kelas->id]))
        ->assertOk()
        ->assertSee('Status sesi')
        ->assertSee('Sedang berlangsung');
});

it('rejects a mismatched id_semester query with a 422', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterKelas = Semester::factory()->create();
    $semesterLain = Semester::factory()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterKelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)
        ->test(Show::class, ['kelasId' => $kelas->id])
        ->set('idSemester', (string) $semesterLain->id)
        ->assertStatus(422);
});

it('does not show a Tambah Slot button — dosen self-service jadwal creation was decided against, though the action still exists', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    $html = $this->actingAs($dosenUser)->get(route('dosen.jadwal.show', ['kelasId' => $kelas->id]))->getContent();

    expect($html)->not->toContain('wire:click="openTambahSlotModal"');

    // Aksinya sendiri tetap ada (bukan dihapus), sesuai instruksi — hanya tombolnya yang hilang.
    $component = Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id]);
    expect(method_exists($component->instance(), 'saveTambahSlot'))->toBeTrue();
});

it('opens the tambah slot modal with a reset form', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)
        ->test(Show::class, ['kelasId' => $kelas->id])
        ->set('tambahJamMulai', '08:00')
        ->call('closeTambahSlotModal')
        ->call('openTambahSlotModal')
        ->assertSet('showTambahSlotModal', true)
        ->assertSet('tambahJamMulai', '');
});

it('adds a new jadwal slot for the kelas, even for a non-pic dosen on the teaching team', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)
        ->test(Show::class, ['kelasId' => $kelas->id])
        ->set('tambahHari', 'senin')
        ->set('tambahJamMulai', '08:00')
        ->set('tambahJamSelesai', '10:00')
        ->set('tambahUrutanPertemuan', '1')
        ->set('tambahBahasan', 'Pengenalan konsep dasar')
        ->call('saveTambahSlot')
        ->assertSet('showTambahSlotModal', false)
        ->assertHasNoErrors();

    $jadwal = Jadwal::where('id_kelas', $kelas->id)->first();
    expect($jadwal)->not->toBeNull();
    expect($jadwal->hari)->toBe('senin');
    expect($jadwal->jam_mulai)->toBe('08:00:00');
    expect($jadwal->jam_selesai)->toBe('10:00:00');
    expect($jadwal->urutan_pertemuan)->toBe(1);
    expect($jadwal->bahasan)->toBe('Pengenalan konsep dasar');
    expect($jadwal->is_active)->toBeFalse();
});

it('requires jam_mulai and jam_selesai, and rejects jam_selesai not after jam_mulai', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    Livewire::actingAs($dosenUser)
        ->test(Show::class, ['kelasId' => $kelas->id])
        ->call('saveTambahSlot')
        ->assertHasErrors(['tambahJamMulai' => 'required', 'tambahJamSelesai' => 'required']);

    Livewire::actingAs($dosenUser)
        ->test(Show::class, ['kelasId' => $kelas->id])
        ->set('tambahJamMulai', '10:00')
        ->set('tambahJamSelesai', '09:00')
        ->call('saveTambahSlot')
        ->assertHasErrors(['tambahJamSelesai' => 'after']);

    expect(Jadwal::where('id_kelas', $kelas->id)->count())->toBe(0);
});

it('rejects adding a slot with a pertemuan ke- and ruangan that already exists for the kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);
    $ruangan = Ruangan::factory()->create();
    Jadwal::factory()->create(['id_kelas' => $kelas->id, 'urutan_pertemuan' => 3, 'id_ruangan' => $ruangan->id]);

    Livewire::actingAs($dosenUser)
        ->test(Show::class, ['kelasId' => $kelas->id])
        ->set('tambahJamMulai', '08:00')
        ->set('tambahJamSelesai', '10:00')
        ->set('tambahUrutanPertemuan', '3')
        ->set('tambahIdRuangan', (string) $ruangan->id)
        ->call('saveTambahSlot')
        ->assertHasErrors(['tambahUrutanPertemuan']);

    expect(Jadwal::where('id_kelas', $kelas->id)->count())->toBe(1);
});

it('refreshes the slot jadwal pertemuan table after adding a new slot', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    $component = Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id]);
    expect($component->instance()->jadwalRows())->toHaveCount(0);

    $component->set('tambahJamMulai', '08:00')
        ->set('tambahJamSelesai', '10:00')
        ->call('saveTambahSlot');

    expect($component->instance()->jadwalRows())->toHaveCount(1);
});

it('lets any assigned dosen edit an existing jadwal slot from the list', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    // Bukan PIC: mengubah jadwal terbuka untuk semua dosen pengampu kelas ini.
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);

    $jadwal = Jadwal::factory()->create([
        'id_kelas' => $kelas->id,
        'hari' => 'senin',
        'jam_mulai' => '08:00',
        'jam_selesai' => '10:00',
    ]);
    $ruangan = Ruangan::factory()->create();
    $jenisKuliah = JenisKuliah::factory()->create();

    Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])
        ->assertSee('Ubah')
        ->call('openEditModal', $jadwal->id)
        ->assertSet('showEditModal', true)
        ->assertSet('editHari', 'senin')
        ->assertSet('editJamMulai', '08:00')
        ->assertSet('editJamSelesai', '10:00')
        ->set('editHari', 'rabu')
        ->set('editJamMulai', '13:00')
        ->set('editJamSelesai', '15:30')
        ->set('editTanggal', '2026-04-01')
        ->set('editIdRuangan', (string) $ruangan->id)
        ->set('editIdJenisKuliah', (string) $jenisKuliah->id)
        ->call('saveEditJadwal')
        ->assertHasNoErrors()
        ->assertSet('showEditModal', false);

    $jadwal->refresh();

    expect($jadwal->hari)->toBe('rabu');
    expect(substr((string) $jadwal->jam_mulai, 0, 5))->toBe('13:00');
    expect(substr((string) $jadwal->jam_selesai, 0, 5))->toBe('15:30');
    expect($jadwal->tanggal->format('Y-m-d'))->toBe('2026-04-01');
    expect($jadwal->id_ruangan)->toBe($ruangan->id);
    expect($jadwal->id_jenis_kuliah)->toBe($jenisKuliah->id);
});

it('rejects jam selesai that is not after jam mulai, and jam filled only on one side', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'jam_mulai' => '08:00', 'jam_selesai' => '10:00']);

    Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])
        ->call('openEditModal', $jadwal->id)
        ->set('editJamMulai', '10:00')
        ->set('editJamSelesai', '09:00')
        ->call('saveEditJadwal')
        ->assertHasErrors(['editJamSelesai']);

    Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])
        ->call('openEditModal', $jadwal->id)
        ->set('editJamMulai', '')
        ->set('editJamSelesai', '09:00')
        ->call('saveEditJadwal')
        ->assertHasErrors(['editJamMulai']);

    $jadwal->refresh();
    expect(substr((string) $jadwal->jam_mulai, 0, 5))->toBe('08:00');
});

it('refuses to edit a jadwal slot that belongs to another kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $kelasLain = Kelas::factory()->create();
    $jadwalLain = Jadwal::factory()->create(['id_kelas' => $kelasLain->id, 'hari' => 'senin']);

    Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])
        ->call('openEditModal', $jadwalLain->id)
        ->assertForbidden();

    // Juga saat id-nya diselundupkan langsung ke aksi simpan.
    Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])
        ->set('editJadwalId', $jadwalLain->id)
        ->set('editHari', 'jumat')
        ->call('saveEditJadwal')
        ->assertForbidden();

    expect($jadwalLain->refresh()->hari)->toBe('senin');
});

it('lets a dosen change the jam of a slot from the detail page too', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'jam_mulai' => '08:00', 'jam_selesai' => '10:00']);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('startEdit')
        ->assertSet('jam_mulai', '08:00')
        ->set('jam_mulai', '07:30')
        ->set('jam_selesai', '09:30')
        ->call('saveJadwal')
        ->assertHasNoErrors();

    $jadwal->refresh();
    expect(substr((string) $jadwal->jam_mulai, 0, 5))->toBe('07:30');
    expect(substr((string) $jadwal->jam_selesai, 0, 5))->toBe('09:30');
});

it('orders the slot table by pertemuan ke, not alphabetically by hari', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $kelas = Kelas::factory()->create();
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    // Alfabetis, "jumat" mendahului "senin" — urutan pertemuan yang harus menang.
    $pertemuan1 = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'urutan_pertemuan' => 1, 'hari' => 'senin']);
    $pertemuan2 = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'urutan_pertemuan' => 2, 'hari' => 'jumat']);

    $rows = Livewire::actingAs($dosenUser)->test(Show::class, ['kelasId' => $kelas->id])->instance()->jadwalRows();

    expect(collect($rows)->pluck('jadwal.id')->all())->toBe([$pertemuan1->id, $pertemuan2->id]);
});
