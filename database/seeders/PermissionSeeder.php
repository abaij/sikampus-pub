<?php

namespace Database\Seeders;

use App\Models\Role as AppRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Buat permissions untuk menu akademik
        $akademikPermissions = [
            'view akademik',
            'manage semester',
            'manage kurikulum',
            'manage mata kuliah',
            'manage kelas',
            'manage jadwal',
            'manage jadwal ujian',
            'manage krs',
            'manage perkuliahan',
            'manage nilai',
            'manage rentang nilai',
            'manage konversi nilai',
            'manage tugas akhir',
            'manage yudisium',
            'manage wisuda',
        ];

        // Buat permissions untuk menu keuangan
        $keuanganPermissions = [
            'view keuangan',
            'manage tagihan',
            'manage pembayaran',
            'manage struktur biaya',
            'manage komponen biaya',
            'manage kategori biaya',
            'manage kebijakan biaya',
            'manage jenis keringanan biaya',
            'manage keringanan biaya',
            'manage aturan akses keuangan',
        ];

        // Permission granular untuk Tagihan (dipakai saat GRANULAR_PERMISSIONS=true, lihat
        // config/access.php + config/panel_access.php). Sengaja dipisah dari $keuanganPermissions
        // supaya 'create tagihan'/'update tagihan'/'delete tagihan' TIDAK otomatis ikut ke role
        // Keuangan di bawah — hanya 'view tagihan' yang jadi baseline role tsb; sisanya diberikan
        // manual per-user lewat tab Permission di halaman Pengguna kalau staf tertentu memang
        // perlu tambah/ubah/hapus tagihan.
        $tagihanGranularPermissions = [
            'view tagihan',
            'create tagihan',
            'update tagihan',
            'delete tagihan',
        ];

        // Sama seperti $tagihanGranularPermissions, plus 'approve pembayaran' — aksi ACC
        // pembayaran itu sendiri sengaja permission terpisah (bukan disamakan ke 'update'),
        // supaya bisa dipisah dari sekadar mengoreksi data pembayaran (mis. staf entri boleh
        // ubah tapi tidak boleh menyetujui — pemisahan tugas yang lazim di alur keuangan).
        $pembayaranGranularPermissions = [
            'view pembayaran',
            'create pembayaran',
            'update pembayaran',
            'delete pembayaran',
            'approve pembayaran',
        ];

        // Tidak ada 'create nilai' — halaman akademik/nilai/{id}/{idKrs}/edit itu satu-satunya
        // rute dan bersifat upsert (isi nilai kalau belum ada, koreksi kalau sudah), bukan
        // create+edit terpisah seperti Tagihan/Pembayaran, jadi cukup 'update' untuk keduanya.
        $nilaiGranularPermissions = [
            'view nilai',
            'update nilai',
            'delete nilai',
        ];

        // Struktur biaya: pola identik dengan $tagihanGranularPermissions (index/create/edit
        // sebagai rute terpisah, hapus lewat method Livewire di Index).
        $strukturBiayaGranularPermissions = [
            'view struktur biaya',
            'create struktur biaya',
            'update struktur biaya',
            'delete struktur biaya',
        ];

        // Komponen biaya: pola yang sama persis lagi (index/create/edit sebagai rute terpisah,
        // hapus lewat method Livewire di Index).
        $komponenBiayaGranularPermissions = [
            'view komponen biaya',
            'create komponen biaya',
            'update komponen biaya',
            'delete komponen biaya',
        ];

        // Aturan akses keuangan: pola yang sama persis lagi (index/create/edit sebagai rute
        // terpisah, hapus lewat method Livewire di Index).
        $aturanAksesKeuanganGranularPermissions = [
            'view aturan akses keuangan',
            'create aturan akses keuangan',
            'update aturan akses keuangan',
            'delete aturan akses keuangan',
        ];

        // Kategori biaya: index/create/edit/show sebagai rute, hapus lewat method Livewire di
        // Index. 'update kategori biaya' juga menjaga aksi "Tambah Mahasiswa" di halaman show
        // (App\Livewire\Admin\KategoriBiaya\Show::save()) — menetapkan kategori biaya seorang
        // mahasiswa itu bagian dari mengelola kategori biaya, bukan aksi terpisah.
        $kategoriBiayaGranularPermissions = [
            'view kategori biaya',
            'create kategori biaya',
            'update kategori biaya',
            'delete kategori biaya',
        ];

        // Keringanan biaya: pola yang sama persis (index/create/edit sebagai rute terpisah,
        // hapus lewat method Livewire di Index). Approve/reject pengajuan bukan aksi terpisah —
        // itu bagian dari field status di form edit, jadi cukup 'update'.
        $keringananBiayaGranularPermissions = [
            'view keringanan biaya',
            'create keringanan biaya',
            'update keringanan biaya',
            'delete keringanan biaya',
        ];

        // Jenis keringanan biaya: pola yang sama persis lagi (index/create/edit sebagai rute
        // terpisah, hapus lewat method Livewire di Index).
        $jenisKeringananBiayaGranularPermissions = [
            'view jenis keringanan biaya',
            'create jenis keringanan biaya',
            'update jenis keringanan biaya',
            'delete jenis keringanan biaya',
        ];

        // Modul Administrasi (dan tabel institusi terkait) — pola granular yang sama diterapkan
        // ke seluruh grup ini. Berbeda dari Nilai/Keuangan, di sini role Akademik memang jadi
        // view-only default juga (keputusan eksplisit, bukan bawaan pola) — lihat baseline role
        // Akademik di bawah.
        $fakultasGranularPermissions = [
            'view fakultas',
            'create fakultas',
            'update fakultas',
            'delete fakultas',
        ];

        $prodiGranularPermissions = [
            'view prodi',
            'create prodi',
            'update prodi',
            'delete prodi',
        ];

        $jenjangGranularPermissions = [
            'view jenjang',
            'create jenjang',
            'update jenjang',
            'delete jenjang',
        ];

        // Perguruan tinggi: halaman settings singleton (satu baris key-value di tabel settings),
        // tidak ada create/delete — cuma 'view' (buka halaman) dan 'update' (submit form, method
        // Livewire di rute yang sama, dijaga langsung di App\Livewire\Admin\PerguruanTinggi).
        $perguruanTinggiGranularPermissions = [
            'view perguruan tinggi',
            'update perguruan tinggi',
        ];

        $ruanganGranularPermissions = [
            'view ruangan',
            'create ruangan',
            'update ruangan',
            'delete ruangan',
        ];

        $pengumumanGranularPermissions = [
            'view pengumuman',
            'create pengumuman',
            'update pengumuman',
            'delete pengumuman',
        ];

        // Nama resource "grup mahasiswa" (bukan "kelas mahasiswa") sengaja mengikuti nama
        // permission dasar yang sudah ada ('manage grup mahasiswa') supaya satu resource yang
        // sama tidak punya dua nama berbeda di mode dasar vs granular.
        $grupMahasiswaGranularPermissions = [
            'view grup mahasiswa',
            'create grup mahasiswa',
            'update grup mahasiswa',
            'delete grup mahasiswa',
        ];

        // Survey: CRUD survey-nya sendiri + manajemen pertanyaan (tambah/ubah/hapus pertanyaan
        // di App\Livewire\Admin\Survey\Show) dianggap bagian dari 'update'/'delete survey' —
        // bukan permission terpisah, karena pertanyaan itu bagian tak terpisahkan dari satu
        // survey. Lihat statistik/export tetap 'view'.
        $surveyGranularPermissions = [
            'view survey',
            'create survey',
            'update survey',
            'delete survey',
        ];

        // Dosen: index/create/edit sebagai rute, hapus lewat method Livewire (baik di Index
        // maupun di Show — App\Livewire\Admin\Dosen\Show::deleteDosen()). Import spreadsheet
        // dianggap 'create' (menambah data massal). Template/export tetap 'view' (baca saja).
        $dosenGranularPermissions = [
            'view dosen',
            'create dosen',
            'update dosen',
            'delete dosen',
        ];

        // Dosen wali: bukan entitas yang benar-benar di-"create" sendiri — barisnya (penugasan
        // dosen sebagai wali seorang mahasiswa/"bimbingan") ditambahkan lewat modal "Tambah
        // Bimbingan" di halaman Show (baik dari App\Livewire\Admin\DosenWali\Show maupun modal
        // yang sama di App\Livewire\Admin\Dosen\Show — dua pintu ke resource yang sama), jadi
        // dipetakan ke 'update', sama seperti pola "Tambah Mahasiswa" di Kategori Biaya. Set
        // Kuota massal dan import spreadsheet bimbingan juga 'update' (mengubah/menambah
        // penugasan yang sudah ada). Riwayat bimbingan murni baca → 'view'.
        $dosenWaliGranularPermissions = [
            'view dosen wali',
            'update dosen wali',
            'delete dosen wali',
        ];

        // Mahasiswa: index/create/edit sebagai rute, hapus lewat method Livewire di Show
        // (App\Livewire\Admin\Mahasiswa\Show::deleteMahasiswa() — Index tidak punya tombol hapus
        // sendiri). Import dianggap 'create' (menambah data massal), template/export 'view'.
        $mahasiswaGranularPermissions = [
            'view mahasiswa',
            'create mahasiswa',
            'update mahasiswa',
            'delete mahasiswa',
        ];

        // Pengaturan akademik (Semester, Jalur Masuk, Jenis Daftar, Status Akademik) — menunya
        // ada di bawah "Pengaturan", tapi permission-nya tetap milik grup akademik/administrasi
        // (lihat komentar di config/panel_access.php), jadi pola granularnya sama: role Akademik
        // jadi view-only default, sama seperti Nilai dan seluruh grup Administrasi.
        $semesterGranularPermissions = [
            'view semester',
            'create semester',
            'update semester',
            'delete semester',
        ];

        $jalurMasukGranularPermissions = [
            'view jalur masuk',
            'create jalur masuk',
            'update jalur masuk',
            'delete jalur masuk',
        ];

        $jenisDaftarGranularPermissions = [
            'view jenis pendaftaran',
            'create jenis pendaftaran',
            'update jenis pendaftaran',
            'delete jenis pendaftaran',
        ];

        $statusAkademikGranularPermissions = [
            'view status akademik',
            'create status akademik',
            'update status akademik',
            'delete status akademik',
        ];

        $jenisMatkulGranularPermissions = [
            'view jenis mata kuliah',
            'create jenis mata kuliah',
            'update jenis mata kuliah',
            'delete jenis mata kuliah',
        ];

        // Buat permissions untuk menu administrasi
        $administrasiPermissions = [
            'view administrasi',
            'manage mahasiswa',
            'manage ktm',
            'manage grup mahasiswa',
            'manage dosen',
            'manage dosen wali',
            'manage fakultas',
            'manage prodi',
            'manage perguruan tinggi',
            'manage jenis mata kuliah',
            'manage jenis penilaian',
            'manage jenjang',
            'manage jalur masuk',
            'manage jenis pendaftaran',
            'manage status akademik',
            'manage ruangan',
            'manage survey',
            'manage pengumuman',
        ];

        // Buat permissions untuk menu laporan
        $laporanPermissions = [
            'view laporan',
            'view laporan mahasiswa aktif',
            'view laporan krs',
            'view laporan nilai',
        ];

        // Buat permissions untuk menu pengaturan
        $pengaturanPermissions = [
            'view pengaturan',
            // 'view pengguna' sengaja terpisah dari 'manage pengguna' (bukan dipecah penuh jadi
            // create/update/delete — lihat catatan keamanan di config/panel_access.php soal
            // spatieRoleId): melihat daftar/detail pengguna tidak membuka jalur privilege-escalation
            // apa pun, jadi aman didelegasikan ke staf tanpa memberi mereka akses kelola penuh.
            'view pengguna',
            'manage pengguna',
            'manage role',
            'manage permission',
            'manage sistem',
        ];

        $allPermissions = array_merge(
            $akademikPermissions,
            $keuanganPermissions,
            $tagihanGranularPermissions,
            $pembayaranGranularPermissions,
            $nilaiGranularPermissions,
            $strukturBiayaGranularPermissions,
            $komponenBiayaGranularPermissions,
            $aturanAksesKeuanganGranularPermissions,
            $kategoriBiayaGranularPermissions,
            $keringananBiayaGranularPermissions,
            $jenisKeringananBiayaGranularPermissions,
            $fakultasGranularPermissions,
            $prodiGranularPermissions,
            $jenjangGranularPermissions,
            $perguruanTinggiGranularPermissions,
            $ruanganGranularPermissions,
            $pengumumanGranularPermissions,
            $grupMahasiswaGranularPermissions,
            $surveyGranularPermissions,
            $dosenGranularPermissions,
            $dosenWaliGranularPermissions,
            $mahasiswaGranularPermissions,
            $semesterGranularPermissions,
            $jalurMasukGranularPermissions,
            $jenisDaftarGranularPermissions,
            $statusAkademikGranularPermissions,
            $jenisMatkulGranularPermissions,
            $administrasiPermissions,
            $laporanPermissions,
            $pengaturanPermissions
        );

        foreach ($allPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Buat atau update roles
        $superadminRole = AppRole::firstOrCreate(
            ['name' => 'Superadmin', 'guard_name' => 'web'],
            ['code' => 'superadmin']
        );
        $superadminRole->syncPermissions($allPermissions);

        $akademikRole = AppRole::firstOrCreate(
            ['name' => 'Akademik', 'guard_name' => 'web'],
            ['code' => 'akademik']
        );
        // Admin akademik: menu Akademik + Administrasi (+ laporan). Tidak menyentuh keuangan
        // maupun pengaturan pengguna.
        //
        // Semua 'view ...' di bawah adalah baseline granular (sama pola dengan 'view
        // tagihan'/'view pembayaran' di role Keuangan) — role ini sengaja TIDAK mendapat
        // create/update/delete secara default untuk modul-modul tsb, baik Nilai maupun seluruh
        // grup Administrasi (keputusan eksplisit: staf yang memang rutin mengelola data ini dari
        // panel admin diberi permission-nya langsung per user lewat tab Permission).
        $akademikRole->syncPermissions(array_merge(
            $akademikPermissions,
            $administrasiPermissions,
            $laporanPermissions,
            [
                'view nilai',
                'view fakultas',
                'view prodi',
                'view jenjang',
                'view perguruan tinggi',
                'view ruangan',
                'view pengumuman',
                'view grup mahasiswa',
                'view survey',
                'view dosen',
                'view dosen wali',
                'view mahasiswa',
                'view semester',
                'view jalur masuk',
                'view jenis pendaftaran',
                'view status akademik',
                'view jenis mata kuliah',
            ]
        ));

        $keuanganRole = AppRole::firstOrCreate(
            ['name' => 'Keuangan', 'guard_name' => 'web'],
            ['code' => 'keuangan']
        );
        // Admin keuangan: menu Keuangan saja. Kalau seorang staf keuangan tetap perlu membuka
        // modul lain (mis. data mahasiswa), berikan permission-nya langsung ke user
        // bersangkutan lewat tab Permission di halaman Pengguna — pengecekan memakai
        // $user->can() sehingga permission langsung otomatis memperluas aksesnya.
        //
        // 'view ...' disertakan sebagai baseline granular untuk tiap modul (lihat komentar di
        // $tagihanGranularPermissions dkk.) — role ini sengaja TIDAK mendapat
        // create/update/delete/approve secara default; itu escalation per-user, bukan bagian
        // dari role.
        $keuanganRole->syncPermissions(array_merge($keuanganPermissions, [
            'view tagihan',
            'view pembayaran',
            'view struktur biaya',
            'view komponen biaya',
            'view aturan akses keuangan',
            'view kategori biaya',
            'view keringanan biaya',
            'view jenis keringanan biaya',
        ]));
    }
}
