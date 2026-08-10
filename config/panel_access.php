<?php

/*
|--------------------------------------------------------------------------
| Hak akses panel admin per menu/rute
|--------------------------------------------------------------------------
|
| Peta nama-rute → permission Spatie yang dibutuhkan. Satu peta ini dipakai
| oleh DUA tempat sekaligus supaya tampilan dan fungsi tidak pernah berbeda:
|
|  - App\Http\Middleware\EnsurePanelPermission (menjaga rutenya, 403)
|  - App\Support\PanelAccess::allows()          (menyembunyikan menu di navbar)
|
| Kuncinya adalah PREFIX nama rute (tanpa awalan "admin."); yang paling
| panjang menang, jadi 'pengguna.role' menutup 'pengguna.role.index' dan
| 'pengguna.role.create' sekaligus tanpa mengganggu 'pengguna.index'.
|
| Rute yang tidak ada di peta ini bebas diakses semua admin (mis. dashboard
| dan profil). Karena pengecekan memakai $user->can(), permission yang
| diberikan langsung ke seorang user (model_has_permissions) otomatis
| membuka akses walau role-nya sendiri tidak punya — persis kebutuhan
| "admin keuangan yang diberi permission mahasiswa boleh membuka mahasiswa".
|
*/

return [
    'route_permissions' => [
        // Akademik
        'akademik.matkul' => 'manage mata kuliah',
        'akademik.jenis-penilaian' => 'manage jenis penilaian',
        'akademik.kurikulum' => 'manage kurikulum',
        'akademik.krs' => 'manage krs',
        'akademik.nilai' => 'manage nilai',
        'akademik.rentang-nilai' => 'manage rentang nilai',
        'akademik.konversi-nilai' => 'manage konversi nilai',
        'akademik.kelas' => 'manage kelas',
        'akademik.jadwal' => 'manage jadwal',
        'akademik.jadwal-ujian' => 'manage jadwal ujian',
        'akademik.perkuliahan' => 'manage perkuliahan',
        'akademik.tugas-akhir' => 'manage tugas akhir',
        'akademik.yudisium' => 'manage yudisium',
        'akademik.wisuda' => 'manage wisuda',

        // Administrasi
        'administrasi.dosen' => 'manage dosen',
        'administrasi.dosen-wali' => 'manage dosen wali',
        'administrasi.mahasiswa' => 'manage mahasiswa',
        'administrasi.ktm' => 'manage ktm',
        'administrasi.kelas-mahasiswa' => 'manage grup mahasiswa',
        'administrasi.ruangan' => 'manage ruangan',
        'administrasi.survey' => 'manage survey',
        'administrasi.pengumuman' => 'manage pengumuman',
        'fakultas' => 'manage fakultas',
        'prodi' => 'manage prodi',
        'perguruan-tinggi' => 'manage perguruan tinggi',
        'jenjang' => 'manage jenjang',

        // Keuangan
        'keuangan.tagihan' => 'manage tagihan',
        'keuangan.pembayaran' => 'manage pembayaran',
        'keuangan.keringanan-biaya' => 'manage keringanan biaya',
        'keuangan.jenis-keringanan-biaya' => 'manage jenis keringanan biaya',
        'keuangan.aturan-akses-keuangan' => 'manage aturan akses keuangan',
        'keuangan.struktur-biaya' => 'manage struktur biaya',
        'keuangan.komponen-biaya' => 'manage komponen biaya',
        'keuangan.kategori-biaya' => 'manage kategori biaya',

        // Pengaturan akademik — permission-nya milik grup akademik/administrasi,
        // jadi admin akademik tetap bisa mengelolanya walau menunya ada di bawah
        // "Pengaturan". Pindahkan permission-nya di PermissionSeeder kalau ingin
        // menu ini benar-benar superadmin-only.
        'semester' => 'manage semester',
        'jalur-masuk' => 'manage jalur masuk',
        'jenis-daftar' => 'manage jenis pendaftaran',
        'jenis-matkul' => 'manage jenis mata kuliah',
        'status-akademik' => 'manage status akademik',

        // Pengaturan pengguna — permission-nya hanya dimiliki Superadmin secara default. Modul
        // Pengguna sendiri (create/edit/hapus/assign role/assign permission) SENGAJA TIDAK dipecah
        // granular penuh seperti modul lain di file ini: mengelola user itu sendiri adalah aksi
        // privilege-escalation (mis. App\Livewire\Admin\Pengguna\Form::save() bisa mengangkat user
        // baru jadi Superadmin lewat spatieRoleId, dan App\Livewire\Admin\Pengguna\Show punya aksi
        // assign role/scope/permission yang sudah dikunci abort_unless(isSuperadminActor())
        // terlepas dari permission apa pun) — memecahnya jadi create/update/delete akan membuka
        // jalan staf yang diberi 'create pengguna' untuk membuat akun admin baru dan mengangkatnya
        // sendiri jadi Superadmin. Role/Permission tetap satu permission 'manage role'/'manage
        // permission', sama sekali tidak disentuh di sini.
        //
        // Satu pengecualian: 'view pengguna' (lihat route_permissions_granular di bawah) — melihat
        // daftar/detail pengguna itu sendiri TIDAK membuka jalur privilege-escalation apa pun, jadi
        // aman didelegasikan terpisah dari 'manage pengguna' (yang tetap dibutuhkan untuk
        // create/edit/hapus, dijaga di App\Livewire\Admin\Pengguna\Show::confirmDeleteUser()/
        // deleteUser() lewat App\Support\PanelAccess::can($user, 'pengguna', 'manage')).
        'pengguna.role' => 'manage role',
        'pengguna.permission' => 'manage permission',
        'pengguna' => 'manage pengguna',

        // Modul sistem tetap dijaga ganda oleh middleware role.admin.superadmin di routes/web.php;
        // entri ini yang membuat menunya ikut hilang dari navbar untuk non-superadmin.
        'sistem' => 'manage sistem',
    ],

    /*
    |----------------------------------------------------------------------
    | Peta granular (khusus GRANULAR_PERMISSIONS=true, lihat config/access.php)
    |----------------------------------------------------------------------
    |
    | Dipakai App\Support\PanelAccess::permissionFor() di ATAS peta dasar (route_permissions)
    | saat flag menyala — array_merge dengan peta ini di posisi kedua, jadi entri dengan prefix
    | yang SAMA (mis. 'keuangan.tagihan') menimpa nilai dasarnya, dan entri dengan prefix baru
    | (mis. 'keuangan.tagihan.create') menambah pemisahan per aksi yang tidak ada di peta dasar.
    | "Longest prefix wins" tetap berlaku sama seperti peta dasar.
    |
    | Baru dipetakan untuk modul Keuangan > Tagihan (pilot). Modul lain masih ikut peta dasar
    | (satu permission "manage X" untuk semua aksi) walau flag ini true, sampai ditambahkan juga
    | di sini.
    |
    */
    'route_permissions_granular' => [
        'keuangan.tagihan' => 'view tagihan', // index + show (tidak ada entri lebih spesifik)
        'keuangan.tagihan.create' => 'create tagihan',
        'keuangan.tagihan.generate' => 'create tagihan',
        'keuangan.tagihan.edit' => 'update tagihan',

        // index + show + laporan-pelunasan (tidak ada entri lebih spesifik untuk ketiganya —
        // laporan-pelunasan murni laporan, cukup 'view'). Aksi Hapus/ACC pembayaran tidak lewat
        // rute sendiri (method Livewire di halaman show), jadi dijaga langsung di
        // App\Livewire\Admin\Pembayaran\Show, bukan lewat peta ini.
        'keuangan.pembayaran' => 'view pembayaran',
        'keuangan.pembayaran.create' => 'create pembayaran',
        'keuangan.pembayaran.edit' => 'update pembayaran',

        // index + show + export + cetak (tidak ada entri lebih spesifik untuk ketiganya — semua
        // aksi baca). Tidak ada rute create tersendiri (lihat komentar $nilaiGranularPermissions
        // di PermissionSeeder) — 'akademik.nilai.edit' mencakup isi-baru maupun koreksi
        // sekaligus. Aksi Hapus nilai tidak lewat rute sendiri (method Livewire di halaman show),
        // dijaga langsung di App\Livewire\Admin\Nilai\Show.
        'akademik.nilai' => 'view nilai',
        'akademik.nilai.edit' => 'update nilai',
        'akademik.nilai.import' => 'update nilai', // import mengubah data (insert/update massal) — bukan sekadar 'view'.

        // Aksi Hapus tidak lewat rute sendiri (method Livewire di halaman index), dijaga
        // langsung di App\Livewire\Admin\StrukturBiaya\Index.
        'keuangan.struktur-biaya' => 'view struktur biaya',
        'keuangan.struktur-biaya.create' => 'create struktur biaya',
        'keuangan.struktur-biaya.edit' => 'update struktur biaya',

        // Aksi Hapus tidak lewat rute sendiri (method Livewire di halaman index), dijaga
        // langsung di App\Livewire\Admin\KomponenBiaya\Index.
        'keuangan.komponen-biaya' => 'view komponen biaya',
        'keuangan.komponen-biaya.create' => 'create komponen biaya',
        'keuangan.komponen-biaya.edit' => 'update komponen biaya',

        // Aksi Hapus tidak lewat rute sendiri (method Livewire di halaman index), dijaga
        // langsung di App\Livewire\Admin\AturanAksesKeuangan\Index.
        'keuangan.aturan-akses-keuangan' => 'view aturan akses keuangan',
        'keuangan.aturan-akses-keuangan.create' => 'create aturan akses keuangan',
        'keuangan.aturan-akses-keuangan.edit' => 'update aturan akses keuangan',

        // index + show (tidak ada entri lebih spesifik untuk show — halaman itu murni
        // menampilkan mahasiswa dalam kategori ini). Aksi "Tambah Mahasiswa" di halaman show
        // tidak lewat rute sendiri (method Livewire), dijaga langsung di
        // App\Livewire\Admin\KategoriBiaya\Show pakai 'update kategori biaya'.
        'keuangan.kategori-biaya' => 'view kategori biaya',
        'keuangan.kategori-biaya.create' => 'create kategori biaya',
        'keuangan.kategori-biaya.edit' => 'update kategori biaya',

        // Aksi Hapus tidak lewat rute sendiri (method Livewire di halaman index), dijaga
        // langsung di App\Livewire\Admin\KeringananBiaya\Index.
        'keuangan.keringanan-biaya' => 'view keringanan biaya',
        'keuangan.keringanan-biaya.create' => 'create keringanan biaya',
        'keuangan.keringanan-biaya.edit' => 'update keringanan biaya',

        // Aksi Hapus tidak lewat rute sendiri (method Livewire di halaman index), dijaga
        // langsung di App\Livewire\Admin\JenisKeringananBiaya\Index.
        'keuangan.jenis-keringanan-biaya' => 'view jenis keringanan biaya',
        'keuangan.jenis-keringanan-biaya.create' => 'create jenis keringanan biaya',
        'keuangan.jenis-keringanan-biaya.edit' => 'update jenis keringanan biaya',

        // Aksi Hapus tidak lewat rute sendiri (method Livewire di halaman index), dijaga
        // langsung di App\Livewire\Admin\Fakultas\Index.
        'fakultas.index' => 'view fakultas',
        'fakultas.create' => 'create fakultas',
        'fakultas.edit' => 'update fakultas',

        'prodi.index' => 'view prodi',
        'prodi.create' => 'create prodi',
        'prodi.edit' => 'update prodi',

        'jenjang.index' => 'view jenjang',
        'jenjang.create' => 'create jenjang',
        'jenjang.edit' => 'update jenjang',

        // Halaman settings singleton — 'update' menjaga method save() itu sendiri (dipanggil di
        // App\Livewire\Admin\PerguruanTinggi, bukan lewat rute terpisah), 'view' menjaga rute.
        'perguruan-tinggi' => 'view perguruan tinggi',

        'administrasi.ruangan' => 'view ruangan',
        'administrasi.ruangan.create' => 'create ruangan',
        'administrasi.ruangan.edit' => 'update ruangan',

        'administrasi.pengumuman' => 'view pengumuman',
        'administrasi.pengumuman.create' => 'create pengumuman',
        'administrasi.pengumuman.edit' => 'update pengumuman',

        'administrasi.kelas-mahasiswa' => 'view grup mahasiswa',
        'administrasi.kelas-mahasiswa.create' => 'create grup mahasiswa',
        'administrasi.kelas-mahasiswa.edit' => 'update grup mahasiswa',

        // index/show/statistik-export (tidak ada entri lebih spesifik untuk ketiganya — semua
        // aksi baca). Manajemen pertanyaan survey tidak lewat rute sendiri (method Livewire di
        // halaman show), dijaga langsung di App\Livewire\Admin\Survey\Show pakai 'update'/'delete'.
        'administrasi.survey' => 'view survey',
        'administrasi.survey.create' => 'create survey',
        'administrasi.survey.edit' => 'update survey',

        // index/show/template/export (tidak ada entri lebih spesifik — semua aksi baca). Hapus
        // dari halaman show (bukan cuma index) tidak lewat rute sendiri, dijaga langsung di
        // App\Livewire\Admin\Dosen\Show::deleteDosen(). Import dianggap 'create' (data massal).
        'administrasi.dosen' => 'view dosen',
        'administrasi.dosen.create' => 'create dosen',
        'administrasi.dosen.edit' => 'update dosen',
        'administrasi.dosen.import' => 'create dosen',

        // index/show/riwayat/template (tidak ada entri lebih spesifik — semua aksi baca). Tidak
        // ada rute create/edit tersendiri — penugasan bimbingan (tambah/hapus, Set Kuota massal,
        // import) semuanya method Livewire, dijaga langsung di App\Livewire\Admin\DosenWali\Index
        // dan \Show (dan pintu yang sama di App\Livewire\Admin\Dosen\Show).
        'administrasi.dosen-wali' => 'view dosen wali',

        // index/show/template/export (tidak ada entri lebih spesifik — semua aksi baca). Hapus
        // dari halaman show tidak lewat rute sendiri, dijaga langsung di
        // App\Livewire\Admin\Mahasiswa\Show::deleteMahasiswa(). Import dianggap 'create'.
        'administrasi.mahasiswa' => 'view mahasiswa',
        'administrasi.mahasiswa.create' => 'create mahasiswa',
        'administrasi.mahasiswa.edit' => 'update mahasiswa',
        'administrasi.mahasiswa.import' => 'create mahasiswa',

        // Pengaturan akademik — menunya di bawah "Pengaturan" tapi permission-nya tetap milik
        // grup akademik/administrasi (lihat komentar di route_permissions di atas). Aksi Hapus
        // tidak lewat rute sendiri (method Livewire di halaman index), dijaga langsung di
        // masing-masing App\Livewire\Admin\{Semester,JalurMasuk,JenisDaftar,JenisMatkul,StatusAkademik}\Index.
        'semester.index' => 'view semester',
        'semester.create' => 'create semester',
        'semester.edit' => 'update semester',

        'jalur-masuk.index' => 'view jalur masuk',
        'jalur-masuk.create' => 'create jalur masuk',
        'jalur-masuk.edit' => 'update jalur masuk',

        'jenis-daftar.index' => 'view jenis pendaftaran',
        'jenis-daftar.create' => 'create jenis pendaftaran',
        'jenis-daftar.edit' => 'update jenis pendaftaran',

        'jenis-matkul.index' => 'view jenis mata kuliah',
        'jenis-matkul.create' => 'create jenis mata kuliah',
        'jenis-matkul.edit' => 'update jenis mata kuliah',

        'status-akademik.index' => 'view status akademik',
        'status-akademik.create' => 'create status akademik',
        'status-akademik.edit' => 'update status akademik',

        // Pengecualian tunggal untuk modul Pengguna (lihat catatan panjang di route_permissions
        // di atas) — cuma index/show yang dipecah, create/edit SENGAJA tidak diberi entri di sini
        // sehingga tetap jatuh ke base 'pengguna' => 'manage pengguna' lewat longest-prefix-match.
        // Hapus pengguna (method Livewire di halaman show, bukan rute sendiri) dijaga langsung di
        // App\Livewire\Admin\Pengguna\Show pakai PanelAccess::can($user, 'pengguna', 'manage').
        'pengguna.index' => 'view pengguna',
        'pengguna.show' => 'view pengguna',
    ],
];
