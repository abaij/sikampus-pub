<?php

use App\Livewire\Dosen\Jadwal\Index;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('dosen.jadwal'))->assertRedirect(route('login'));
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);

    $this->actingAs($mahasiswa)->get(route('dosen.jadwal'))->assertForbidden();
});

it('groups the jadwal of a kelas reached through an active jadwal_dosen row', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'is_active' => true]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $groups = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->kelasGroups();

    expect($groups)->toHaveCount(1);
    expect($groups->first()['kelas']->id)->toBe($kelas->id);
    expect($groups->first()['rows']->pluck('id')->all())->toBe([$jadwal->id]);
});

it('shows a kelas that is diampu but has no jadwal at all', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    // Kasus DL2025.025: kelas tercatat diampu (PIC + kelas_dosen) tapi belum dijadwalkan.
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id, 'id_dosen_pic' => $dosen->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $component = Livewire::actingAs($dosenUser)->test(Index::class);
    $groups = $component->instance()->kelasGroups();

    expect($groups)->toHaveCount(1);
    expect($groups->first()['rows'])->toBeEmpty();

    $component->assertSee('Tidak ada jadwal mengajar di semester ini')
        ->assertSee('belum dijadwalkan');
});

it('lists a kelas reached only through kelas_dosen, with its jadwal', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => false]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'rabu', 'is_active' => true]);

    $groups = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->kelasGroups();

    expect($groups)->toHaveCount(1);
    expect($groups->first()['rows']->pluck('id')->all())->toBe([$jadwal->id]);
});

it('puts kelas without any jadwal after the scheduled ones', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelasTanpaJadwal = Kelas::factory()->create(['id_semester' => $semesterAktif->id, 'id_dosen_pic' => $dosen->id]);
    $kelasBerjadwal = Kelas::factory()->create(['id_semester' => $semesterAktif->id, 'id_dosen_pic' => $dosen->id]);
    Jadwal::factory()->create(['id_kelas' => $kelasBerjadwal->id, 'hari' => 'jumat', 'jam_mulai' => '13:00', 'is_active' => true]);

    $groups = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->kelasGroups();

    expect($groups->pluck('kelas.id')->all())->toBe([$kelasBerjadwal->id, $kelasTanpaJadwal->id]);
});

it('sorts rows by hari then jam_mulai, not by a naive combined numeric key', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);

    // Senin sore harus tetap terurut sebelum Selasa pagi, walau formula angka tunggal
    // ($hariNum * 10000 + jamMulai) yang dipakai JadwalDosenController::getMyJadwal akan
    // salah urut di kasus ini (lihat catatan di Index::jadwalRows()).
    $jadwalSeninSore = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'jam_mulai' => '18:00', 'is_active' => true]);
    $jadwalSelasaPagi = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'selasa', 'jam_mulai' => '07:00', 'is_active' => true]);

    JadwalDosen::create(['id_jadwal' => $jadwalSeninSore->id, 'id_dosen' => $dosen->id, 'status' => 'active']);
    JadwalDosen::create(['id_jadwal' => $jadwalSelasaPagi->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $rows = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->kelasGroups()->first()['rows'];

    expect($rows->pluck('id')->all())->toBe([$jadwalSeninSore->id, $jadwalSelasaPagi->id]);
});

it('filters jadwal rows by the selected semester', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterA = Semester::factory()->create();
    $semesterB = Semester::factory()->create();
    $kelasA = Kelas::factory()->create(['id_semester' => $semesterA->id]);
    $kelasB = Kelas::factory()->create(['id_semester' => $semesterB->id]);
    $jadwalA = Jadwal::factory()->create(['id_kelas' => $kelasA->id, 'is_active' => true]);
    $jadwalB = Jadwal::factory()->create(['id_kelas' => $kelasB->id, 'is_active' => true]);
    JadwalDosen::create(['id_jadwal' => $jadwalA->id, 'id_dosen' => $dosen->id, 'status' => 'active']);
    JadwalDosen::create(['id_jadwal' => $jadwalB->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $groups = Livewire::actingAs($dosenUser)
        ->test(Index::class)
        ->set('filterSemester', (string) $semesterB->id)
        ->instance()
        ->kelasGroups();

    expect($groups->pluck('kelas.id')->all())->toBe([$kelasB->id]);
    expect($groups->first()['rows']->pluck('id')->all())->toBe([$jadwalB->id]);
});

it('ignores an inactive jadwal_dosen row when the dosen has no other claim on the kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'is_active' => true]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'inactive']);

    // Penugasan nonaktif bukan bukti mengampu, dan dosen ini tidak tercatat di kelas_dosen
    // maupun sebagai PIC — kelasnya tidak boleh muncul.
    expect(Livewire::actingAs($dosenUser)->test(Index::class)->instance()->kelasGroups())->toHaveCount(0);
});

it('links each row to the jadwal detail page', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'is_active' => true]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $this->actingAs($dosenUser)
        ->get(route('dosen.jadwal'))
        ->assertOk()
        ->assertSee(route('dosen.jadwal.detail', ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id, 'id_semester' => $semesterAktif->id]), false);
});

it('groups jadwal rows by kelas for the accordion, ordered by each kelas earliest slot', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelasSelasa = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);
    $kelasSenin = Kelas::factory()->create(['id_semester' => $semesterAktif->id]);

    // kelasSelasa dibuat lebih dulu, tapi slotnya (Selasa) lebih lambat dari kelasSenin (Senin) —
    // urutan grup harus ikut urutan hari/jam slot, bukan urutan pembuatan Kelas.
    $jadwalSelasa = Jadwal::factory()->create(['id_kelas' => $kelasSelasa->id, 'hari' => 'selasa', 'jam_mulai' => '08:00', 'is_active' => true]);
    $jadwalSeninPagi = Jadwal::factory()->create(['id_kelas' => $kelasSenin->id, 'hari' => 'senin', 'jam_mulai' => '08:00', 'is_active' => true]);
    $jadwalSeninSore = Jadwal::factory()->create(['id_kelas' => $kelasSenin->id, 'hari' => 'senin', 'jam_mulai' => '15:00', 'is_active' => true]);

    JadwalDosen::create(['id_jadwal' => $jadwalSelasa->id, 'id_dosen' => $dosen->id, 'status' => 'active']);
    JadwalDosen::create(['id_jadwal' => $jadwalSeninPagi->id, 'id_dosen' => $dosen->id, 'status' => 'active']);
    JadwalDosen::create(['id_jadwal' => $jadwalSeninSore->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $groups = Livewire::actingAs($dosenUser)->test(Index::class)->instance()->kelasGroups();

    expect($groups)->toHaveCount(2);
    expect($groups->first()['kelas']->id)->toBe($kelasSenin->id);
    expect($groups->first()['rows'])->toHaveCount(2);
    expect($groups->last()['kelas']->id)->toBe($kelasSelasa->id);
    expect($groups->last()['rows'])->toHaveCount(1);
});

it('renders the jadwal mengajar page as a collapsible accordion per kelas', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();

    $semesterAktif = Semester::factory()->active()->create();
    $kelas = Kelas::factory()->create(['id_semester' => $semesterAktif->id, 'kode' => 'KLS-ACC01']);
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin', 'is_active' => true]);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    $html = $this->actingAs($dosenUser)->get(route('dosen.jadwal'))->getContent();

    expect($html)->toContain('<details')
        ->toContain('<summary')
        ->toContain('KLS-ACC01');
});
