<?php

use App\Livewire\Admin\Kurikulum\Form;
use App\Livewire\Admin\Kurikulum\Index;
use App\Livewire\Admin\Kurikulum\Show;
use App\Models\BobotPenilaian;
use App\Models\JenisPenilaian;
use App\Models\Kurikulum;
use App\Models\KurikulumMatkul;
use App\Models\Matkul;
use App\Models\Prodi;
use App\Models\Semester;
use Livewire\Livewire;

it('renders index and create form as full pages', function () {
    $admin = adminUser();
    Kurikulum::factory()->create(['nama' => 'Kurikulum Merdeka Belajar']);

    $this->actingAs($admin)->get(route('admin.akademik.kurikulum'))->assertOk()->assertSee('Kurikulum Merdeka Belajar');
    $this->actingAs($admin)->get(route('admin.akademik.kurikulum.create'))->assertOk()->assertSee('Tambah Kurikulum');
});

it('creates a kurikulum with selected mata kuliah', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $semester = Semester::factory()->create();
    $matkulA = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK001', 'sks' => 3, 'semester' => 2]);
    $matkulB = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK002', 'sks' => 2, 'semester' => 3]);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', $prodi->id)
        ->set('kode', 'KUR-2024')
        ->set('nama', 'Kurikulum 2024')
        ->set('id_tahun_berlaku', $semester->id)
        ->set('sks_wajib_minimal', '144')
        ->set('selectedMatkulIds', [$matkulA->id, $matkulB->id])
        ->set('matkulDetail.'.$matkulB->id.'.is_wajib', false)
        ->call('save')
        ->assertRedirect(route('admin.akademik.kurikulum'));

    $kurikulum = Kurikulum::where('kode', 'KUR-2024')->firstOrFail();
    expect($kurikulum->id_prodi)->toBe($prodi->id);
    expect($kurikulum->sks_wajib_minimal)->toBe(144);
    expect($kurikulum->matkuls)->toHaveCount(2);

    $pivotA = $kurikulum->matkuls->firstWhere('id', $matkulA->id)->pivot;
    expect((int) $pivotA->semester_rekomendasi)->toBe(2);
    expect((bool) $pivotA->is_wajib)->toBeTrue();

    $pivotB = $kurikulum->matkuls->firstWhere('id', $matkulB->id)->pivot;
    expect((bool) $pivotB->is_wajib)->toBeFalse();
});

it('toggles select all / deselect all mata kuliah for the chosen prodi', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $otherProdi = Prodi::factory()->create();
    $matkulA = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK001', 'semester' => 2]);
    $matkulB = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK002', 'semester' => 3]);
    $matkulOther = Matkul::factory()->create(['id_prodi' => $otherProdi->id, 'kode' => 'MK900']);

    $component = Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', $prodi->id)
        ->call('toggleSelectAllMatkul');

    expect($component->get('selectedMatkulIds'))->toEqualCanonicalizing([$matkulA->id, $matkulB->id]);
    expect($component->get('matkulDetail'))->toHaveKeys([$matkulA->id, $matkulB->id]);
    expect($component->get('matkulDetail.'.$matkulA->id.'.semester_rekomendasi'))->toBe('2');
    expect($component->get('selectedMatkulIds'))->not->toContain($matkulOther->id);

    $component->call('toggleSelectAllMatkul');
    expect($component->get('selectedMatkulIds'))->toBe([]);
});

it('updates a kurikulum and resyncs its mata kuliah', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $semester = Semester::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id, 'id_tahun_berlaku' => $semester->id]);
    $matkulKeep = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $matkulDrop = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulum->matkuls()->sync([
        $matkulKeep->id => ['kode_matkul' => $matkulKeep->kode, 'nama_matkul' => $matkulKeep->nama, 'sks' => $matkulKeep->sks, 'is_wajib' => true],
        $matkulDrop->id => ['kode_matkul' => $matkulDrop->kode, 'nama_matkul' => $matkulDrop->nama, 'sks' => $matkulDrop->sks, 'is_wajib' => true],
    ]);

    Livewire::actingAs($admin)
        ->test(Form::class, ['id' => $kurikulum->id])
        ->assertSet('nama', $kurikulum->nama)
        ->assertSet('selectedMatkulIds', [$matkulKeep->id, $matkulDrop->id])
        ->set('nama', 'Kurikulum Revisi')
        ->set('selectedMatkulIds', [$matkulKeep->id])
        ->call('save');

    expect($kurikulum->fresh()->nama)->toBe('Kurikulum Revisi');
    expect($kurikulum->fresh()->matkuls->pluck('id')->all())->toBe([$matkulKeep->id]);
});

it('deletes a kurikulum after confirmation', function () {
    $admin = adminUser();
    $kurikulum = Kurikulum::factory()->create();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $kurikulum->id)
        ->call('delete');

    expect(Kurikulum::find($kurikulum->id))->toBeNull();
});

it('shows kurikulum detail with its mata kuliah list', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id, 'nama' => 'Kurikulum Detail']);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK900']);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'semester_rekomendasi' => 4, 'is_wajib' => true],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.akademik.kurikulum.show', $kurikulum->id))
        ->assertOk()
        ->assertSee('Kurikulum Detail')
        ->assertSee('MK900');
});

it('shows bobot status per mata kuliah and updates it after saving bobot in the modal', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkulLengkap = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'LKP01', 'nama' => 'Matkul Bobot Lengkap']);
    $matkulKosong = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'KSG01', 'nama' => 'Matkul Belum Ada Bobot']);
    $kurikulum->matkuls()->sync([
        $matkulLengkap->id => ['kode_matkul' => $matkulLengkap->kode, 'nama_matkul' => $matkulLengkap->nama, 'sks' => $matkulLengkap->sks, 'is_wajib' => true],
        $matkulKosong->id => ['kode_matkul' => $matkulKosong->kode, 'nama_matkul' => $matkulKosong->nama, 'sks' => $matkulKosong->sks, 'is_wajib' => true],
    ]);
    $kmLengkap = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkulLengkap->id)->firstOrFail();
    $kmKosong = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkulKosong->id)->firstOrFail();
    $jenis = JenisPenilaian::factory()->create(['bobot' => 0]);
    BobotPenilaian::factory()->create(['id_kurikulum_matkul' => $kmLengkap->id, 'id_jenis_penilaian' => $jenis->id, 'bobot' => 100]);

    $component = Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->assertSeeInOrder(['Matkul Bobot Lengkap', 'Lengkap'])
        ->assertSeeInOrder(['Matkul Belum Ada Bobot', 'Belum lengkap']);

    $component
        ->call('openDetailModal', $kmKosong->id)
        ->call('openBobotForm')
        ->set('bobotForm.'.$jenis->id, '100')
        ->call('saveBobotForm')
        ->assertSeeInOrder(['Matkul Belum Ada Bobot', 'Lengkap']);
});

it('searches and paginates the mata kuliah list on the detail page', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);

    $syncData = [];
    foreach (range(1, 12) as $i) {
        $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => sprintf('AA%02d', $i), 'nama' => "Mata Kuliah Umum {$i}"]);
        $syncData[$matkul->id] = ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'is_wajib' => true];
    }
    $target = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'ZZ99', 'nama' => 'Mata Kuliah Pencarian Khusus']);
    $syncData[$target->id] = ['kode_matkul' => $target->kode, 'nama_matkul' => $target->nama, 'sks' => $target->sks, 'is_wajib' => true];
    $kurikulum->matkuls()->sync($syncData);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->assertSee('AA01')
        ->assertDontSee('ZZ99')
        ->assertSee('39') // total SKS (13 mata kuliah x 3 SKS) — lihat catatan totalSksKurikulum() soal ini hilang kalau dihitung di mount()
        ->set('matkulSearch', 'Pencarian Khusus')
        ->assertSee('ZZ99')
        ->assertDontSee('AA01')
        ->assertSee('39') // masih benar setelah request Livewire berikutnya, bukan cuma di render pertama
        ->set('matkulSearch', '')
        ->set('matkulPerPage', 25)
        ->assertSee('ZZ99')
        ->assertSee('AA01');
});

it('opens the mata kuliah detail modal with its bobot penilaian', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK500', 'nama' => 'Kalkulus Lanjut', 'sks' => 4]);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'semester_rekomendasi' => 3, 'is_wajib' => true],
    ]);
    $km = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkul->id)->firstOrFail();
    $jenis = JenisPenilaian::factory()->create(['nama' => 'Ujian Akhir Semester', 'kode' => 'UAS']);
    BobotPenilaian::factory()->create(['id_kurikulum_matkul' => $km->id, 'id_jenis_penilaian' => $jenis->id, 'bobot' => 40]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openDetailModal', $km->id)
        ->assertSee('Kalkulus Lanjut')
        ->assertSee('MK500')
        ->assertSee('Ujian Akhir Semester')
        ->assertSee('40%');
});

it('syncs a kurikulum_matkul snapshot from the master matkul without touching semester/wajib', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'kode' => 'MK600', 'nama' => 'Nama Baru Master', 'sks' => 3]);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => 'MK600-OLD', 'nama_matkul' => 'Nama Lama Snapshot', 'sks' => 2, 'semester_rekomendasi' => 5, 'is_wajib' => false],
    ]);
    $km = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkul->id)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openDetailModal', $km->id)
        ->call('syncMatkulFromMaster');

    $km->refresh();
    expect($km->kode_matkul)->toBe('MK600');
    expect($km->nama_matkul)->toBe('Nama Baru Master');
    expect($km->sks)->toBe(3);
    expect($km->semester_rekomendasi)->toBe(5);
    expect((bool) $km->is_wajib)->toBeFalse();
});

it('saves bobot penilaian manually, replacing any existing rows', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'is_wajib' => true],
    ]);
    $km = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkul->id)->firstOrFail();
    $jenisTugas = JenisPenilaian::factory()->create(['nama' => 'Tugas', 'bobot' => 0]);
    $jenisUas = JenisPenilaian::factory()->create(['nama' => 'UAS', 'bobot' => 0]);
    BobotPenilaian::factory()->create(['id_kurikulum_matkul' => $km->id, 'id_jenis_penilaian' => $jenisTugas->id, 'bobot' => 20]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openDetailModal', $km->id)
        ->call('openBobotForm')
        ->assertSet('bobotForm.'.$jenisTugas->id, '20')
        ->set('bobotForm.'.$jenisTugas->id, '30')
        ->set('bobotForm.'.$jenisUas->id, '70')
        ->call('saveBobotForm');

    $rows = BobotPenilaian::where('id_kurikulum_matkul', $km->id)->get()->keyBy('id_jenis_penilaian');
    expect($rows->count())->toBe(2);
    expect((float) $rows[$jenisTugas->id]->bobot)->toBe(30.0);
    expect((float) $rows[$jenisUas->id]->bobot)->toBe(70.0);
});

it('rejects bobot penilaian totaling more than 100 percent', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'is_wajib' => true],
    ]);
    $km = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkul->id)->firstOrFail();
    $jenis = JenisPenilaian::factory()->create(['bobot' => 0]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openDetailModal', $km->id)
        ->call('openBobotForm')
        ->set('bobotForm.'.$jenis->id, '150')
        ->call('saveBobotForm')
        ->assertHasErrors('bobotForm');

    expect(BobotPenilaian::where('id_kurikulum_matkul', $km->id)->count())->toBe(0);
});

it('auto-fills bobot penilaian from jenis penilaian defaults', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'is_wajib' => true],
    ]);
    $km = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkul->id)->firstOrFail();
    $jenisA = JenisPenilaian::factory()->create(['nama' => 'Kehadiran', 'bobot' => 10]);
    $jenisB = JenisPenilaian::factory()->create(['nama' => 'UTS', 'bobot' => 30]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openDetailModal', $km->id)
        ->call('openAutoFillConfirm')
        ->call('confirmAutoFill');

    $rows = BobotPenilaian::where('id_kurikulum_matkul', $km->id)->get()->keyBy('id_jenis_penilaian');
    expect($rows->count())->toBe(2);
    expect((float) $rows[$jenisA->id]->bobot)->toBe(10.0);
    expect((float) $rows[$jenisB->id]->bobot)->toBe(30.0);
});

it('cannot open the detail modal for a kurikulum_matkul belonging to another kurikulum', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulumA = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulumB = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id, 'nama' => 'Matkul Kurikulum B']);
    $kurikulumB->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'is_wajib' => true],
    ]);
    $kmB = KurikulumMatkul::where('id_kurikulum', $kurikulumB->id)->where('id_matkul', $matkul->id)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulumA->id])
        ->call('openDetailModal', $kmB->id)
        ->assertDontSee('Matkul Kurikulum B');
});

it('shows the tetapkan bobot massal button only when some mata kuliah lack bobot penilaian', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'is_wajib' => true],
    ]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->assertSee('Tetapkan Bobot Massal');

    $km = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkul->id)->firstOrFail();
    $jenis = JenisPenilaian::factory()->create(['bobot' => 0]);
    BobotPenilaian::factory()->create(['id_kurikulum_matkul' => $km->id, 'id_jenis_penilaian' => $jenis->id, 'bobot' => 100]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->assertDontSee('Tetapkan Bobot Massal');
});

it('applies bobot massal only to mata kuliah without existing bobot penilaian', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkulSudah = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $matkulBelumA = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $matkulBelumB = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulum->matkuls()->sync([
        $matkulSudah->id => ['kode_matkul' => $matkulSudah->kode, 'nama_matkul' => $matkulSudah->nama, 'sks' => $matkulSudah->sks, 'is_wajib' => true],
        $matkulBelumA->id => ['kode_matkul' => $matkulBelumA->kode, 'nama_matkul' => $matkulBelumA->nama, 'sks' => $matkulBelumA->sks, 'is_wajib' => true],
        $matkulBelumB->id => ['kode_matkul' => $matkulBelumB->kode, 'nama_matkul' => $matkulBelumB->nama, 'sks' => $matkulBelumB->sks, 'is_wajib' => true],
    ]);
    $kmSudah = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkulSudah->id)->firstOrFail();
    $kmBelumA = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkulBelumA->id)->firstOrFail();
    $kmBelumB = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkulBelumB->id)->firstOrFail();

    $jenisA = JenisPenilaian::factory()->create(['nama' => 'Tugas', 'bobot' => 0]);
    $jenisB = JenisPenilaian::factory()->create(['nama' => 'UAS', 'bobot' => 0]);
    $existing = BobotPenilaian::factory()->create(['id_kurikulum_matkul' => $kmSudah->id, 'id_jenis_penilaian' => $jenisA->id, 'bobot' => 100]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openBobotMassalForm')
        ->assertSet('bobotMassalForm.'.$jenisA->id, '0')
        ->set('bobotMassalForm.'.$jenisA->id, '40')
        ->set('bobotMassalForm.'.$jenisB->id, '60')
        ->call('saveBobotMassalForm');

    // Matkul yang sudah punya bobot tidak disentuh.
    expect(BobotPenilaian::find($existing->id))->not->toBeNull();
    expect(BobotPenilaian::where('id_kurikulum_matkul', $kmSudah->id)->count())->toBe(1);

    // Matkul yang belum punya bobot menerima item bobot massal.
    foreach ([$kmBelumA, $kmBelumB] as $km) {
        $rows = BobotPenilaian::where('id_kurikulum_matkul', $km->id)->get()->keyBy('id_jenis_penilaian');
        expect($rows->count())->toBe(2);
        expect((float) $rows[$jenisA->id]->bobot)->toBe(40.0);
        expect((float) $rows[$jenisB->id]->bobot)->toBe(60.0);
    }
});

it('rejects bobot massal totaling more than 100 percent and requires at least one positive bobot', function () {
    $admin = adminUser();
    $prodi = Prodi::factory()->create();
    $kurikulum = Kurikulum::factory()->create(['id_prodi' => $prodi->id]);
    $matkul = Matkul::factory()->create(['id_prodi' => $prodi->id]);
    $kurikulum->matkuls()->sync([
        $matkul->id => ['kode_matkul' => $matkul->kode, 'nama_matkul' => $matkul->nama, 'sks' => $matkul->sks, 'is_wajib' => true],
    ]);
    $km = KurikulumMatkul::where('id_kurikulum', $kurikulum->id)->where('id_matkul', $matkul->id)->firstOrFail();
    $jenis = JenisPenilaian::factory()->create(['bobot' => 0]);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openBobotMassalForm')
        ->set('bobotMassalForm.'.$jenis->id, '150')
        ->call('saveBobotMassalForm')
        ->assertHasErrors('bobotMassalForm');
    expect(BobotPenilaian::where('id_kurikulum_matkul', $km->id)->count())->toBe(0);

    Livewire::actingAs($admin)
        ->test(Show::class, ['id' => $kurikulum->id])
        ->call('openBobotMassalForm')
        ->set('bobotMassalForm.'.$jenis->id, '0')
        ->call('saveBobotMassalForm')
        ->assertHasErrors('bobotMassalForm');
    expect(BobotPenilaian::where('id_kurikulum_matkul', $km->id)->count())->toBe(0);
});

it('admin dengan scope prodi hanya melihat kurikulum miliknya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    Kurikulum::factory()->create(['nama' => 'Kurikulum Prodi A', 'id_prodi' => $prodiA->id]);
    Kurikulum::factory()->create(['nama' => 'Kurikulum Prodi B', 'id_prodi' => $prodiB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Kurikulum Prodi A')
        ->assertDontSee('Kurikulum Prodi B');
});

it('admin dengan scope prodi tidak bisa menghapus kurikulum di luar scope-nya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $kurikulumB = Kurikulum::factory()->create(['id_prodi' => $prodiB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $kurikulumB->id)
        ->call('delete')
        ->assertStatus(403);

    expect(Kurikulum::find($kurikulumB->id))->not->toBeNull();
});

it('admin dengan scope prodi tidak bisa membuka detail kurikulum di luar scope-nya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $kurikulumB = Kurikulum::factory()->create(['id_prodi' => $prodiB->id]);

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    $this->actingAs($admin)
        ->get(route('admin.akademik.kurikulum.show', $kurikulumB->id))
        ->assertStatus(403);
});

it('admin dengan scope prodi tidak bisa membuat kurikulum di luar scope-nya', function () {
    $prodiA = Prodi::factory()->create();
    $prodiB = Prodi::factory()->create();
    $semester = Semester::factory()->create();

    $admin = adminUser('admin_akademik');
    scopeAdminToProdi($admin, $prodiA->id);

    Livewire::actingAs($admin)
        ->test(Form::class)
        ->set('id_prodi', $prodiB->id)
        ->set('kode', 'KUR-X')
        ->set('nama', 'Kurikulum X')
        ->set('id_tahun_berlaku', $semester->id)
        ->call('save')
        ->assertStatus(403);
});

it('redirects unauthenticated users to the admin login page', function () {
    $this->get(route('admin.akademik.kurikulum'))->assertRedirect(route('login'));
});

// Regression: layouts.web me-render @section('page_actions') di luar root <div> komponen, jadi
// tombol wire:click yang diletakkan di sana tidak pernah terikat Livewire dan diam saja saat diklik.
it('keeps the delete button inside the livewire root so wire:click stays bound', function () {
    $admin = adminUser();
    $kurikulum = Kurikulum::factory()->create();

    $html = $this->actingAs($admin)->get(route('admin.akademik.kurikulum.show', $kurikulum->id))->getContent();

    $rootStart = strpos($html, 'wire:id=');
    expect($rootStart)->not->toBeFalse();
    expect(strpos($html, 'wire:click="confirmDeleteKurikulum"'))->toBeGreaterThan($rootStart);
});
