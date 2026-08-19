<?php

/**
 * Fungsi import (Kelas/Nilai/KRS/Mata Kuliah) tidak punya middleware/permission tersendiri —
 * rutenya jatuh ke permission dasar modulnya masing-masing lewat longest-prefix-match di
 * App\Support\PanelAccess (lihat config/panel_access.php), yang sudah hanya dimiliki role
 * Superadmin dan Akademik (lihat database/seeders/PermissionSeeder.php — 'manage kelas',
 * 'manage krs', 'manage mata kuliah', 'manage nilai' ada di $akademikPermissions, tidak pernah
 * di $keuanganPermissions). Test ini mengonfirmasi perilaku itu secara eksplisit supaya kalau
 * suatu saat peta permission berubah, regresinya langsung ketahuan di sini.
 */
$modules = [
    'kelas' => ['import' => 'admin.akademik.kelas.import', 'template' => 'admin.akademik.kelas.template'],
    'nilai' => ['import' => 'admin.akademik.nilai.import', 'template' => 'admin.akademik.nilai.template'],
    'krs' => ['import' => 'admin.akademik.krs.import', 'template' => 'admin.akademik.krs.template'],
    'matkul' => ['import' => 'admin.akademik.matkul.import', 'template' => 'admin.akademik.matkul.template'],
    'yudisium' => ['import' => 'admin.akademik.yudisium.import', 'template' => 'admin.akademik.yudisium.template'],
    'tugas-akhir' => ['import' => 'admin.akademik.tugas-akhir.import', 'template' => 'admin.akademik.tugas-akhir.template'],
];

foreach ($modules as $name => $routes) {
    it("allows superadmin to reach the {$name} import page and template download", function () use ($routes) {
        $admin = adminUser();

        $this->actingAs($admin)->get(route($routes['import']))->assertOk();
        $this->actingAs($admin)->get(route($routes['template']))->assertOk();
    });

    it("allows an akademik admin to reach the {$name} import page and template download", function () use ($routes) {
        $admin = adminUser('admin_akademik');

        $this->actingAs($admin)->get(route($routes['import']))->assertOk();
        $this->actingAs($admin)->get(route($routes['template']))->assertOk();
    });

    it("blocks a keuangan-only admin from the {$name} import page and template download", function () use ($routes) {
        $admin = adminUser('admin_keuangan');

        $this->actingAs($admin)->get(route($routes['import']))->assertStatus(403);
        $this->actingAs($admin)->get(route($routes['template']))->assertStatus(403);
    });
}

it('still blocks keuangan from nilai import when granular permissions are enabled', function () {
    config(['access.granular_permissions' => true]);
    $admin = adminUser('admin_keuangan');

    $this->actingAs($admin)->get(route('admin.akademik.nilai.import'))->assertStatus(403);
});

it('requires update nilai (not just view) to reach nilai import in granular mode', function () {
    config(['access.granular_permissions' => true]);
    $admin = adminUser('admin_akademik');

    // Akademik role hanya mendapat 'view nilai' secara default (lihat PermissionSeeder) —
    // 'update nilai' (dipakai juga oleh rute edit) sengaja tidak diberikan otomatis.
    $this->actingAs($admin)->get(route('admin.akademik.nilai'))->assertOk();
    $this->actingAs($admin)->get(route('admin.akademik.nilai.import'))->assertStatus(403);

    $admin->givePermissionTo('update nilai');

    $this->actingAs($admin)->get(route('admin.akademik.nilai.import'))->assertOk();
});
