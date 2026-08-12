<?php

use App\Livewire\Dosen\Jadwal\Detail;
use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\JenisKuliah;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\MateriPerkuliahan;
use App\Models\Perkuliahan;
use App\Models\Ruangan;
use App\Models\Tugas;
use App\Models\TugasMahasiswa;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('redirects unauthenticated users to the login page', function () {
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);

    $this->get(route('dosen.jadwal.detail', ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]))
        ->assertRedirect(route('login'));
});

it('forbids a non-dosen user', function () {
    $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);

    $this->actingAs($mahasiswa)
        ->get(route('dosen.jadwal.detail', ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]))
        ->assertForbidden();
});

it('forbids a dosen with no relation to the kelas, the pic assignment, or the jadwal_dosen row', function () {
    $dosenUser = dosenUser();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->assertForbidden();
});

it('allows access via an active jadwal_dosen row even without a kelas_dosen row', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'bahasan' => 'Bahasan awal']);
    JadwalDosen::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'status' => 'active']);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->assertOk()
        ->assertSet('bahasan', 'Bahasan awal');
});

it('returns 404 when the jadwal does not belong to the given kelasId', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelasBenar = Kelas::factory()->create();
    $kelasSalah = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelasBenar->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelasBenar->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelasSalah->id, 'jadwalId' => $jadwal->id])
        ->assertStatus(404);
});

it('updates hari, tanggal, ruangan, and jenis kuliah for the pic dosen', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id, 'hari' => 'senin']);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $ruangan = Ruangan::factory()->create();
    $jenisKuliah = JenisKuliah::factory()->create();

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('startEdit')
        ->set('hari', 'rabu')
        ->set('id_ruangan', (string) $ruangan->id)
        ->set('id_jenis_kuliah', (string) $jenisKuliah->id)
        ->call('saveJadwal')
        ->assertHasNoErrors();

    $jadwal->refresh();
    expect($jadwal->hari)->toBe('rabu');
    expect($jadwal->id_ruangan)->toBe($ruangan->id);
    expect($jadwal->id_jenis_kuliah)->toBe($jenisKuliah->id);
});

it('saves the bahasan field independently of the jadwal edit form', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->set('bahasan', 'Membahas rekursi dan iterasi')
        ->call('saveBahasan')
        ->assertHasNoErrors();

    expect($jadwal->fresh()->bahasan)->toBe('Membahas rekursi dan iterasi');
});

it('uploads a materi perkuliahan file scoped to the jadwal', function () {
    Storage::fake('public');

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->set('tab', 'materi')
        ->set('materiNama', 'Slide pertemuan 1')
        ->set('materiFile', UploadedFile::fake()->create('slide.pdf', 100, 'application/pdf'))
        ->call('uploadMateri')
        ->assertHasNoErrors();

    $materi = MateriPerkuliahan::where('id_jadwal', $jadwal->id)->firstOrFail();
    expect($materi->nama)->toBe('Slide pertemuan 1');
    Storage::disk('public')->assertExists($materi->file);
});

it('defaults materi nama to the original filename when left blank', function () {
    Storage::fake('public');

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->set('materiFile', UploadedFile::fake()->create('modul-2.pdf', 100, 'application/pdf'))
        ->call('uploadMateri')
        ->assertHasNoErrors();

    $materi = MateriPerkuliahan::where('id_jadwal', $jadwal->id)->firstOrFail();
    expect($materi->nama)->toBe('modul-2.pdf');
});

it('does not expose materi from another jadwal', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    $jadwalLain = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    MateriPerkuliahan::create(['id_jadwal' => $jadwalLain->id, 'nama' => 'Materi jadwal lain', 'file' => 'materi_perkuliahan/x.pdf']);

    $rows = Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->instance()
        ->materiRows();

    expect($rows)->toHaveCount(0);
});

it('creates a tugas for the jadwal and lists it with zero submissions', function () {
    Storage::fake('public');

    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->set('tab', 'tugas')
        ->set('tugasNama', 'Laporan praktikum 1')
        ->set('tugasDeskripsi', 'Kerjakan sesuai instruksi')
        ->set('tugasFile', UploadedFile::fake()->create('tugas.pdf', 100, 'application/pdf'))
        ->call('submitTugas')
        ->assertHasNoErrors();

    $tugas = Tugas::where('id_jadwal', $jadwal->id)->firstOrFail();
    expect($tugas->nama)->toBe('Laporan praktikum 1');
    expect($tugas->id_dosen)->toBe($dosen->id);
    Storage::disk('public')->assertExists($tugas->file);

    $rows = Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->instance()
        ->tugasRows();

    expect($rows->first()->jumlah_submit)->toBe(0);
});

it('rejects tugas tanggal_selesai earlier than tanggal_mulai', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->set('tugasNama', 'Tugas invalid')
        ->set('tugasTanggalMulai', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('tugasTanggalSelesai', now()->format('Y-m-d\TH:i'))
        ->call('submitTugas')
        ->assertHasErrors(['tugasTanggalSelesai']);
});

it('marks a submission as accepted only when it belongs to this jadwal', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    $jadwalLain = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $tugas = Tugas::create(['id_jadwal' => $jadwal->id, 'id_dosen' => $dosen->id, 'nama' => 'Tugas 1']);
    $tugasLain = Tugas::create(['id_jadwal' => $jadwalLain->id, 'id_dosen' => $dosen->id, 'nama' => 'Tugas lain']);
    $mhs = Mahasiswa::factory()->create();

    $submisi = TugasMahasiswa::create(['id_tugas' => $tugas->id, 'id_mahasiswa' => $mhs->id, 'status' => 'submitted', 'tanggal_submit' => now()]);
    $submisiJadwalLain = TugasMahasiswa::create(['id_tugas' => $tugasLain->id, 'id_mahasiswa' => $mhs->id, 'status' => 'submitted', 'tanggal_submit' => now()]);

    $component = Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]);

    $component->call('terimaPengumpulan', $submisi->id)->assertHasNoErrors();
    expect($submisi->fresh()->status)->toBe('accepted');

    $component->call('terimaPengumpulan', $submisiJadwalLain->id)->assertStatus(403);
    expect($submisiJadwalLain->fresh()->status)->toBe('submitted');
});

it('shows no active perkuliahan for the kehadiran tab when none exists for this jadwal', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $component = Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('setTab', 'kehadiran');

    expect($component->instance()->perkuliahanUntukKehadiran())->toBeNull();
    expect($component->instance()->kehadiranMahasiswa())->toBe([]);
    $component->assertSee('Belum ada rekaman perkuliahan');
});

it('prefers the ongoing perkuliahan over a finished one for the kehadiran tab', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);

    $selesai = Perkuliahan::factory()->create([
        'id_jadwal' => $jadwal->id,
        'waktu_mulai' => now()->subDay(),
        'waktu_selesai' => now()->subDay()->addHours(2),
    ]);
    $berlangsung = Perkuliahan::factory()->create([
        'id_jadwal' => $jadwal->id,
        'waktu_mulai' => now()->subMinutes(10),
        'waktu_selesai' => null,
    ]);

    $component = Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id]);

    expect($component->instance()->perkuliahanUntukKehadiran()->id)->toBe($berlangsung->id)
        ->not->toBe($selesai->id);
});

it('lists approved krs mahasiswa with their kehadiran status on the kehadiran tab and links to the marking page', function () {
    $dosenUser = dosenUser();
    $dosen = Dosen::where('id_user', $dosenUser->id)->firstOrFail();
    $kelas = Kelas::factory()->create();
    $jadwal = Jadwal::factory()->create(['id_kelas' => $kelas->id]);
    KelasDosen::create(['id_dosen' => $dosen->id, 'id_kelas' => $kelas->id, 'is_pic' => true]);
    $perkuliahan = Perkuliahan::factory()->create(['id_jadwal' => $jadwal->id, 'waktu_mulai' => now(), 'waktu_selesai' => null]);

    $mhsHadir = Mahasiswa::factory()->create(['nama' => 'Mahasiswa Hadir']);
    Krs::factory()->create(['id_mahasiswa' => $mhsHadir->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Kehadiran::create(['id_perkuliahan' => $perkuliahan->id, 'id_mhs' => $mhsHadir->id, 'status' => 'hadir']);

    $mhsBelumDisetujui = Mahasiswa::factory()->create(['nama' => 'Mahasiswa Belum Disetujui']);
    Krs::factory()->create(['id_mahasiswa' => $mhsBelumDisetujui->id, 'id_kelas' => $kelas->id, 'approved_at' => null]);

    $component = Livewire::actingAs($dosenUser)
        ->test(Detail::class, ['kelasId' => $kelas->id, 'jadwalId' => $jadwal->id])
        ->call('setTab', 'kehadiran');

    expect($component->instance()->kehadiranMahasiswa())->toHaveCount(1);

    $component->assertSee('Mahasiswa Hadir')
        ->assertDontSee('Mahasiswa Belum Disetujui')
        ->assertSee(route('dosen.kehadiran.detail', $perkuliahan->id), false);
});
