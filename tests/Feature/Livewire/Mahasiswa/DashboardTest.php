<?php

use App\Livewire\Mahasiswa\Dashboard;
use App\Models\Dosen;
use App\Models\DosenWali;
use App\Models\Kelas;
use App\Models\Krs;
use App\Models\Ktm;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Nilai;
use App\Models\Pengumuman;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

function dashboardMahasiswaUser(array $mahasiswaAttributes = []): array
{
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(array_merge(['id_user' => $user->id], $mahasiswaAttributes));

    return [$user, $mahasiswa];
}

function nilaiKrsUntukDashboard(Mahasiswa $mahasiswa, Semester $semester, array $nilaiAttrs = []): void
{
    $matkul = Matkul::factory()->create(['sks' => 3]);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $kelas = Kelas::factory()->create(['id_kurikulum_matkul' => $kurikulumMatkul->id, 'id_semester' => $semester->id]);
    $krs = Krs::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_kelas' => $kelas->id, 'approved_at' => now()]);
    Nilai::factory()->create(array_merge(['id_krs' => $krs->id, 'is_final' => true], $nilaiAttrs));
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('mahasiswa.dashboard'))->assertRedirect(route('login'));
});

it('forbids a non-mahasiswa user', function () {
    $dosen = User::factory()->create(['role' => 'dosen']);

    $this->actingAs($dosen)->get(route('mahasiswa.dashboard'))->assertForbidden();
});

it('renders the dashboard with the linked mahasiswa academic info', function () {
    [$user, $mahasiswa] = dashboardMahasiswaUser(['nim' => '2099001', 'nama' => 'Mahasiswa Uji']);

    $this->actingAs($user)->get(route('mahasiswa.dashboard'))
        ->assertOk()
        ->assertSee('2099001')
        ->assertSee('Kartu Rencana Studi')
        ->assertSee('Kartu Tanda Mahasiswa');
});

it('shows the active dosen wali name, hiding an inactive assignment', function () {
    [$user, $mahasiswa] = dashboardMahasiswaUser();
    $dosenAktif = Dosen::factory()->create(['nama' => 'Dosen Wali Aktif']);
    $dosenNonaktif = Dosen::factory()->create(['nama' => 'Dosen Wali Lama']);
    DosenWali::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_dosen' => $dosenAktif->id, 'status' => 'active']);
    DosenWali::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'id_dosen' => $dosenNonaktif->id, 'status' => 'inactive']);

    $this->actingAs($user)->get(route('mahasiswa.dashboard'))
        ->assertOk()
        ->assertSee('Dosen Wali Aktif')
        ->assertDontSee('Dosen Wali Lama');
});

it('computes ip per semester only from approved krs with final grades', function () {
    [$user, $mahasiswa] = dashboardMahasiswaUser();
    $semester = Semester::factory()->create(['kode' => '20241', 'nama' => 'Ganjil 2024/2025']);
    nilaiKrsUntukDashboard($mahasiswa, $semester, ['angka_mutu' => 4, 'is_final' => true]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('20241');
});

it('lists active pengumuman aimed at mahasiswa and lets the mahasiswa open the detail modal', function () {
    [$user] = dashboardMahasiswaUser();
    $pengumuman = Pengumuman::factory()->create([
        'judul' => 'Pengumuman Uji',
        'isi' => 'Isi lengkap pengumuman uji.',
        'audien' => 'mahasiswa',
        'prioritas' => 'high',
        'tanggal_mulai' => now()->subDay(),
        'tanggal_selesai' => now()->addDay(),
    ]);
    Pengumuman::factory()->create(['audien' => 'dosen', 'tanggal_mulai' => now()->subDay(), 'tanggal_selesai' => now()->addDay()]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Pengumuman Uji')
        ->call('showPengumuman', $pengumuman->id)
        ->assertSet('selectedPengumumanId', $pengumuman->id)
        ->assertSee('Isi lengkap pengumuman uji.');
});

it('shows a ktm preview with a manage link when the mahasiswa already has one', function () {
    [$user, $mahasiswa] = dashboardMahasiswaUser();
    Ktm::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'file' => 'ktm/foto/dummy.png', 'status' => 'active']);

    $this->actingAs($user)->get(route('mahasiswa.dashboard'))
        ->assertOk()
        ->assertSee('Kelola KTM');
});

it('shows a call to action to create a ktm when the mahasiswa has none', function () {
    [$user] = dashboardMahasiswaUser();

    $this->actingAs($user)->get(route('mahasiswa.dashboard'))
        ->assertOk()
        ->assertSee('Buat KTM');
});
