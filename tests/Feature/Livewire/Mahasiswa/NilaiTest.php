<?php

use App\Livewire\Mahasiswa\Nilai\Semester as NilaiSemester;
use App\Livewire\Mahasiswa\Nilai\Transkrip as NilaiTranskrip;
use App\Models\Kelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\Nilai;
use App\Models\Semester;
use App\Models\User;
use Livewire\Livewire;

function nilaiMahasiswaUser(): array
{
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(['id_user' => $user->id]);

    return [$user, $mahasiswa];
}

function buatKrsDenganNilai(Mahasiswa $mahasiswa, Semester $semester, array $matkulAttrs, ?array $nilaiAttrs, bool $approved = true): Krs
{
    $matkul = Matkul::factory()->create($matkulAttrs);
    $kurikulumMatkul = KurikulumMatkul::factory()->create(['id_matkul' => $matkul->id]);
    $kelas = Kelas::factory()->create(['id_kurikulum_matkul' => $kurikulumMatkul->id, 'id_semester' => $semester->id]);
    $krs = Krs::factory()->create([
        'id_mahasiswa' => $mahasiswa->id,
        'id_kelas' => $kelas->id,
        'approved_at' => $approved ? now() : null,
    ]);

    if ($nilaiAttrs !== null) {
        Nilai::factory()->create(array_merge(['id_krs' => $krs->id], $nilaiAttrs));
    }

    return $krs;
}

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('mahasiswa.nilai.semester'))->assertRedirect(route('login'));
    $this->get(route('mahasiswa.nilai.transkrip'))->assertRedirect(route('login'));
});

it('shows nilai semester grouped with ip per semester, including ungraded courses', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create(['nama' => 'Ganjil 2025/2026']);

    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3], [
        'angka_mutu' => 3.5, 'huruf_mutu' => 'A-', 'is_final' => true,
    ]);
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF102', 'nama' => 'Basis Data', 'sks' => 3], null);

    $this->actingAs($user)->get(route('mahasiswa.nilai.semester'))
        ->assertOk()
        ->assertSee('Ganjil 2025/2026')
        ->assertSee('IF101')
        ->assertSee('A-')
        ->assertSee('Final')
        ->assertSee('IF102')
        ->assertSee('Belum ada nilai');
});

it('computes ip semester and ip kumulatif only from final grades', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create();

    // 4.0 * 3 sks = 12, 3.0 * 2 sks = 6 → total 18 / 5 sks = 3.6
    buatKrsDenganNilai($mahasiswa, $semester, ['sks' => 3], ['angka_mutu' => 4.0, 'huruf_mutu' => 'A', 'is_final' => true]);
    buatKrsDenganNilai($mahasiswa, $semester, ['sks' => 2], ['angka_mutu' => 3.0, 'huruf_mutu' => 'B', 'is_final' => true]);
    // Nilai belum final tidak boleh ikut dihitung ke IP.
    buatKrsDenganNilai($mahasiswa, $semester, ['sks' => 3], ['angka_mutu' => 1.0, 'huruf_mutu' => 'D', 'is_final' => false]);

    $this->actingAs($user)->get(route('mahasiswa.nilai.semester'))
        ->assertOk()
        ->assertSee('3.60');
});

it('excludes courses without an approved krs or without a final huruf mutu from the transkrip', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create();

    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF201', 'nama' => 'Struktur Data'], [
        'angka_mutu' => 3.5, 'huruf_mutu' => 'A-', 'is_final' => true,
    ]);
    // Belum disetujui KRS-nya, harus absen dari transkrip.
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF202', 'nama' => 'Jaringan Komputer'], [
        'angka_mutu' => 3.0, 'huruf_mutu' => 'B', 'is_final' => true,
    ], approved: false);
    // Belum ada nilai sama sekali, harus absen dari transkrip (beda dengan Nilai Semester).
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF203', 'nama' => 'Pemrograman Web'], null);

    $response = $this->actingAs($user)->get(route('mahasiswa.nilai.transkrip'))->assertOk();
    $response->assertSee('IF201');
    $response->assertDontSee('IF202');
    $response->assertDontSee('IF203');
});

it('shows an export button on the nilai semester page when there is data', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create();
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3], [
        'angka_mutu' => 4, 'huruf_mutu' => 'A', 'is_final' => true,
    ]);

    $this->actingAs($user)->get(route('mahasiswa.nilai.semester'))
        ->assertOk()
        ->assertSee('Export PDF')
        ->assertSee('wire:click="exportPdf"', false);
});

it('hides the export button when the mahasiswa has no nilai at all', function () {
    [$user] = nilaiMahasiswaUser();

    $this->actingAs($user)->get(route('mahasiswa.nilai.semester'))
        ->assertOk()
        ->assertDontSee('Export PDF');
});

it('streams a pdf download when exporting nilai semester', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create();
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3], [
        'angka_mutu' => 4, 'huruf_mutu' => 'A', 'is_final' => true,
    ]);

    Livewire::actingAs($user)->test(NilaiSemester::class)
        ->call('exportPdf')
        ->assertFileDownloaded();
});

it('shows an export button on the transkrip page when there is data', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create();
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3], [
        'angka_mutu' => 4, 'huruf_mutu' => 'A', 'is_final' => true,
    ]);

    $this->actingAs($user)->get(route('mahasiswa.nilai.transkrip'))
        ->assertOk()
        ->assertSee('Export PDF')
        ->assertSee('wire:click="exportPdf"', false);
});

it('hides the transkrip export button when no course has a final grade yet', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create();
    // Sudah disetujui KRS-nya tapi belum ada nilai — transkrip tetap kosong.
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3], null);

    $this->actingAs($user)->get(route('mahasiswa.nilai.transkrip'))
        ->assertOk()
        ->assertDontSee('Export PDF');
});

it('streams a pdf download when exporting transkrip', function () {
    [$user, $mahasiswa] = nilaiMahasiswaUser();
    $semester = Semester::factory()->create();
    buatKrsDenganNilai($mahasiswa, $semester, ['kode' => 'IF101', 'nama' => 'Algoritma', 'sks' => 3], [
        'angka_mutu' => 4, 'huruf_mutu' => 'A', 'is_final' => true,
    ]);

    Livewire::actingAs($user)->test(NilaiTranskrip::class)
        ->call('exportPdf')
        ->assertFileDownloaded();
});
