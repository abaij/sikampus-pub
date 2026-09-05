<?php

use App\Http\Controllers\DosenController;
use App\Http\Controllers\DosenWaliController;
use App\Http\Controllers\JadwalController;
use App\Http\Controllers\KecamatanController;
use App\Http\Controllers\KelasController;
use App\Http\Controllers\KelompokKelasController;
use App\Http\Controllers\KotaController;
use App\Http\Controllers\KrsController;
use App\Http\Controllers\MahasiswaController;
use App\Http\Controllers\MatkulController;
use App\Http\Controllers\NegaraController;
use App\Http\Controllers\NilaiController;
use App\Http\Controllers\PerkuliahanController;
use App\Http\Controllers\ProvinsiController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\TugasAkhirController;
use App\Http\Controllers\Web\AdminWebLoginController;
use App\Http\Controllers\Web\DosenBimbinganExportController;
use App\Http\Controllers\Web\DosenExportController;
use App\Http\Controllers\Web\ImpersonateController;
use App\Http\Controllers\Web\JurnalPerkuliahanCetakController;
use App\Http\Controllers\Web\KrsCetakController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\MahasiswaExportController;
use App\Http\Controllers\Web\NilaiExportController;
use App\Http\Controllers\Web\SuperadminEnvConfigController;
use App\Http\Controllers\Web\SuperadminMigrasiController;
use App\Http\Controllers\Web\SuperadminTestUploadController;
use App\Http\Controllers\Web\SuperadminUpdateController;
use App\Http\Controllers\Web\SuperadminWebLoginController;
use App\Http\Controllers\YudisiumController;
use App\Livewire\Admin\AturanAksesKeuangan\Form as AturanAksesKeuanganForm;
use App\Livewire\Admin\AturanAksesKeuangan\Index as AturanAksesKeuanganIndex;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\Dosen\Form as DosenForm;
use App\Livewire\Admin\Dosen\Import as DosenImport;
use App\Livewire\Admin\Dosen\Index as DosenIndex;
use App\Livewire\Admin\Dosen\Show as DosenShow;
use App\Livewire\Admin\DosenWali\Index as DosenWaliIndex;
use App\Livewire\Admin\DosenWali\Riwayat as DosenWaliRiwayat;
use App\Livewire\Admin\DosenWali\Show as DosenWaliShow;
use App\Livewire\Admin\Fakultas\Form as FakultasForm;
use App\Livewire\Admin\Fakultas\Index as FakultasIndex;
use App\Livewire\Admin\Jadwal\Form as JadwalForm;
use App\Livewire\Admin\Jadwal\Import as JadwalImport;
use App\Livewire\Admin\Jadwal\Index as JadwalIndex;
use App\Livewire\Admin\Jadwal\Show as JadwalShow;
use App\Livewire\Admin\JadwalUjian\Form as JadwalUjianForm;
use App\Livewire\Admin\JadwalUjian\Index as JadwalUjianIndex;
use App\Livewire\Admin\JadwalUjian\Show as JadwalUjianShow;
use App\Livewire\Admin\JalurMasuk\Form as JalurMasukForm;
use App\Livewire\Admin\JalurMasuk\Index as JalurMasukIndex;
use App\Livewire\Admin\JenisDaftar\Form as JenisDaftarForm;
use App\Livewire\Admin\JenisDaftar\Index as JenisDaftarIndex;
use App\Livewire\Admin\JenisKeringananBiaya\Form as JenisKeringananBiayaForm;
use App\Livewire\Admin\JenisKeringananBiaya\Index as JenisKeringananBiayaIndex;
use App\Livewire\Admin\JenisMatkul\Form as JenisMatkulForm;
use App\Livewire\Admin\JenisMatkul\Index as JenisMatkulIndex;
use App\Livewire\Admin\JenisPenilaian\Form as JenisPenilaianForm;
use App\Livewire\Admin\JenisPenilaian\Index as JenisPenilaianIndex;
use App\Livewire\Admin\Jenjang\Form as JenjangForm;
use App\Livewire\Admin\Jenjang\Index as JenjangIndex;
use App\Livewire\Admin\KategoriBiaya\Form as KategoriBiayaForm;
use App\Livewire\Admin\KategoriBiaya\Index as KategoriBiayaIndex;
use App\Livewire\Admin\KategoriBiaya\Show as KategoriBiayaShow;
use App\Livewire\Admin\Kelas\Form as KelasForm;
use App\Livewire\Admin\Kelas\Import as KelasImport;
use App\Livewire\Admin\Kelas\Index as KelasIndex;
use App\Livewire\Admin\Kelas\Show as KelasShow;
use App\Livewire\Admin\KelompokKelas\Form as KelompokKelasForm;
use App\Livewire\Admin\KelompokKelas\Import as KelompokKelasImport;
use App\Livewire\Admin\KelompokKelas\Index as KelompokKelasIndex;
use App\Livewire\Admin\KeringananBiaya\Form as KeringananBiayaForm;
use App\Livewire\Admin\KeringananBiaya\Index as KeringananBiayaIndex;
use App\Livewire\Admin\KomponenBiaya\Form as KomponenBiayaForm;
use App\Livewire\Admin\KomponenBiaya\Index as KomponenBiayaIndex;
use App\Livewire\Admin\KonversiNilai\Form as KonversiNilaiForm;
use App\Livewire\Admin\KonversiNilai\Index as KonversiNilaiIndex;
use App\Livewire\Admin\KonversiNilai\Show as KonversiNilaiShow;
use App\Livewire\Admin\Krs\Form as KrsForm;
use App\Livewire\Admin\Krs\Import as KrsImport;
use App\Livewire\Admin\Krs\Index as KrsIndex;
use App\Livewire\Admin\Krs\Show as KrsShow;
use App\Livewire\Admin\Ktm\Form as KtmForm;
use App\Livewire\Admin\Ktm\Index as KtmIndex;
use App\Livewire\Admin\Kurikulum\Form as KurikulumForm;
use App\Livewire\Admin\Kurikulum\Index as KurikulumIndex;
use App\Livewire\Admin\Kurikulum\Show as KurikulumShow;
use App\Livewire\Admin\Mahasiswa\Form as MahasiswaForm;
use App\Livewire\Admin\Mahasiswa\Import as MahasiswaImport;
use App\Livewire\Admin\Mahasiswa\Index as MahasiswaIndex;
use App\Livewire\Admin\Mahasiswa\Show as MahasiswaShow;
use App\Livewire\Admin\Matkul\Form as MatkulForm;
use App\Livewire\Admin\Matkul\Import as MatkulImport;
use App\Livewire\Admin\Matkul\Index as MatkulIndex;
use App\Livewire\Admin\Matkul\Show as MatkulShow;
use App\Livewire\Admin\Nilai\Form as NilaiForm;
use App\Livewire\Admin\Nilai\Import as NilaiImport;
use App\Livewire\Admin\Nilai\Index as NilaiIndex;
use App\Livewire\Admin\Nilai\Show as NilaiShow;
use App\Livewire\Admin\Pembayaran\Form as PembayaranForm;
use App\Livewire\Admin\Pembayaran\Index as PembayaranIndex;
use App\Livewire\Admin\Pembayaran\LaporanPelunasan as PembayaranLaporanPelunasan;
use App\Livewire\Admin\Pembayaran\Show as PembayaranShow;
use App\Livewire\Admin\Pengguna\Form as PenggunaForm;
use App\Livewire\Admin\Pengguna\Index as PenggunaIndex;
use App\Livewire\Admin\Pengguna\Show as PenggunaShow;
use App\Livewire\Admin\Pengumuman\Form as PengumumanForm;
use App\Livewire\Admin\Pengumuman\Index as PengumumanIndex;
use App\Livewire\Admin\PerguruanTinggi as AdminPerguruanTinggi;
use App\Livewire\Admin\Perkuliahan\Import as PerkuliahanImport;
use App\Livewire\Admin\Perkuliahan\Index as PerkuliahanIndex;
use App\Livewire\Admin\Perkuliahan\Nilai as PerkuliahanNilai;
use App\Livewire\Admin\Perkuliahan\Show as PerkuliahanShow;
use App\Livewire\Admin\Permission\Form as PermissionForm;
use App\Livewire\Admin\Permission\Index as PermissionIndex;
use App\Livewire\Admin\Prodi\Form as ProdiForm;
use App\Livewire\Admin\Prodi\Index as ProdiIndex;
use App\Livewire\Admin\Profil as AdminProfil;
use App\Livewire\Admin\RentangNilai\Form as RentangNilaiForm;
use App\Livewire\Admin\RentangNilai\Index as RentangNilaiIndex;
use App\Livewire\Admin\Role\Form as RoleForm;
use App\Livewire\Admin\Role\Index as RoleIndex;
use App\Livewire\Admin\Ruangan\Form as RuanganForm;
use App\Livewire\Admin\Ruangan\Index as RuanganIndex;
use App\Livewire\Admin\Semester\Form as SemesterForm;
use App\Livewire\Admin\Semester\Index as SemesterIndex;
use App\Livewire\Admin\Sistem\Kecamatan\Form as KecamatanForm;
use App\Livewire\Admin\Sistem\Kecamatan\Import as KecamatanImport;
use App\Livewire\Admin\Sistem\Kecamatan\Index as KecamatanIndex;
use App\Livewire\Admin\Sistem\Kota\Form as KotaForm;
use App\Livewire\Admin\Sistem\Kota\Import as KotaImport;
use App\Livewire\Admin\Sistem\Kota\Index as KotaIndex;
use App\Livewire\Admin\Sistem\Lisensi as SistemLisensi;
use App\Livewire\Admin\Sistem\Negara\Form as NegaraForm;
use App\Livewire\Admin\Sistem\Negara\Import as NegaraImport;
use App\Livewire\Admin\Sistem\Negara\Index as NegaraIndex;
use App\Livewire\Admin\Sistem\Pembaruan as SistemPembaruan;
use App\Livewire\Admin\Sistem\Pengaturan as SistemPengaturan;
use App\Livewire\Admin\Sistem\Plugin as SistemPlugin;
use App\Livewire\Admin\Sistem\Provinsi\Form as ProvinsiForm;
use App\Livewire\Admin\Sistem\Provinsi\Import as ProvinsiImport;
use App\Livewire\Admin\Sistem\Provinsi\Index as ProvinsiIndex;
use App\Livewire\Admin\StatusAkademik\Form as StatusAkademikForm;
use App\Livewire\Admin\StatusAkademik\Index as StatusAkademikIndex;
use App\Livewire\Admin\StrukturBiaya\Form as StrukturBiayaForm;
use App\Livewire\Admin\StrukturBiaya\Index as StrukturBiayaIndex;
use App\Livewire\Admin\Survey\Form as SurveyForm;
use App\Livewire\Admin\Survey\Index as SurveyIndex;
use App\Livewire\Admin\Survey\Show as SurveyShow;
use App\Livewire\Admin\Tagihan\Form as TagihanForm;
use App\Livewire\Admin\Tagihan\Generate as TagihanGenerate;
use App\Livewire\Admin\Tagihan\Index as TagihanIndex;
use App\Livewire\Admin\Tagihan\Show as TagihanShow;
use App\Livewire\Admin\Transkrip\Penandatangan as TranskripPenandatangan;
use App\Livewire\Admin\TugasAkhir\Import as TugasAkhirImport;
use App\Livewire\Admin\TugasAkhir\Index as TugasAkhirIndex;
use App\Livewire\Admin\TugasAkhir\Show as TugasAkhirShow;
use App\Livewire\Admin\TugasAkhir\UjianSidangShow;
use App\Livewire\Admin\Wisuda\Form as WisudaForm;
use App\Livewire\Admin\Wisuda\Index as WisudaIndex;
use App\Livewire\Admin\Wisuda\Show as WisudaShow;
use App\Livewire\Admin\Yudisium\Form as YudisiumForm;
use App\Livewire\Admin\Yudisium\Import as YudisiumImport;
use App\Livewire\Admin\Yudisium\Index as YudisiumIndex;
use App\Livewire\Admin\Yudisium\Show as YudisiumShow;
use App\Livewire\Auth\Aktivasi;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword as ResetPasswordLivewire;
use App\Livewire\Auth\VerifyEmail;
use App\Livewire\Dosen\Arsip\Index as DosenArsipIndex;
use App\Livewire\Dosen\Arsip\NilaiKelas as DosenArsipNilaiKelas;
use App\Livewire\Dosen\Dashboard as DosenDashboard;
use App\Livewire\Dosen\Jadwal\Detail as DosenJadwalDetail;
use App\Livewire\Dosen\Jadwal\Index as DosenJadwalIndex;
use App\Livewire\Dosen\Jadwal\Show as DosenJadwalShow;
use App\Livewire\Dosen\Kehadiran\Detail as DosenKehadiranDetail;
use App\Livewire\Dosen\Kehadiran\Index as DosenKehadiranIndex;
use App\Livewire\Dosen\Kehadiran\RekapKelas as DosenKehadiranRekapKelas;
use App\Livewire\Dosen\Kelas\Index as DosenKelasIndex;
use App\Livewire\Dosen\Krs\Index as DosenKrsIndex;
use App\Livewire\Dosen\Nilai\Index as DosenNilaiIndex;
use App\Livewire\Dosen\Nilai\Input as DosenNilaiInput;
use App\Livewire\Dosen\Nilai\Rekap as DosenNilaiRekap;
use App\Livewire\Dosen\Perwalian\Index as DosenPerwalianIndex;
use App\Livewire\Dosen\Perwalian\Show as DosenPerwalianShow;
use App\Livewire\Dosen\Profil as DosenProfil;
use App\Livewire\Dosen\Rps\Index as DosenRpsIndex;
use App\Livewire\Dosen\Rps\Show as DosenRpsShow;
use App\Livewire\Dosen\TugasAkhir\Index as DosenTugasAkhirIndex;
use App\Livewire\Dosen\TugasAkhir\Show as DosenTugasAkhirShow;
use App\Livewire\Dosen\UjianSidang\Index as DosenUjianSidangIndex;
use App\Livewire\Dosen\UjianSidang\Show as DosenUjianSidangShow;
use App\Livewire\Mahasiswa\BimbinganTugasAkhir\Index as MahasiswaBimbinganTugasAkhirIndex;
use App\Livewire\Mahasiswa\BimbinganTugasAkhir\Show as MahasiswaBimbinganTugasAkhirShow;
use App\Livewire\Mahasiswa\Dashboard as MahasiswaDashboard;
use App\Livewire\Mahasiswa\Jadwal\Detail as MahasiswaJadwalDetail;
use App\Livewire\Mahasiswa\Jadwal\Index as MahasiswaJadwalIndex;
use App\Livewire\Mahasiswa\Kehadiran\Index as MahasiswaKehadiranIndex;
use App\Livewire\Mahasiswa\KeringananBiaya\Index as MahasiswaKeringananBiayaIndex;
use App\Livewire\Mahasiswa\Krs\Index as MahasiswaKrsIndex;
use App\Livewire\Mahasiswa\Krs\Pengajuan as MahasiswaKrsPengajuan;
use App\Livewire\Mahasiswa\Ktm as MahasiswaKtm;
use App\Livewire\Mahasiswa\Nilai\Semester as MahasiswaNilaiSemester;
use App\Livewire\Mahasiswa\Nilai\Transkrip as MahasiswaNilaiTranskrip;
use App\Livewire\Mahasiswa\Pembayaran\Index as MahasiswaPembayaranIndex;
use App\Livewire\Mahasiswa\Perwalian\Index as MahasiswaPerwalianIndex;
use App\Livewire\Mahasiswa\Profil as MahasiswaProfil;
use App\Livewire\Mahasiswa\Survey\Index as MahasiswaSurveyIndex;
use App\Livewire\Mahasiswa\Survey\Isi as MahasiswaSurveyIsi;
use App\Livewire\Mahasiswa\Tagihan\Index as MahasiswaTagihanIndex;
use App\Livewire\Mahasiswa\TugasAkhir\Index as MahasiswaTugasAkhirIndex;
use App\Livewire\Mahasiswa\TugasAkhir\Pengajuan as MahasiswaTugasAkhirPengajuan;
use App\Livewire\Mahasiswa\TugasAkhir\Show as MahasiswaTugasAkhirShow;
use App\Livewire\Mahasiswa\UjianSidang\Index as MahasiswaUjianSidangIndex;
use App\Livewire\Mahasiswa\UjianSidang\Pengajuan as MahasiswaUjianSidangPengajuan;
use App\Livewire\Mahasiswa\UjianSidang\Show as MahasiswaUjianSidangShow;
use App\Livewire\Mahasiswa\YudisiumWisuda\Index as MahasiswaYudisiumWisudaIndex;
use App\Livewire\Prodi\Dashboard as ProdiDashboard;
use App\Livewire\Prodi\Dosen\Index as ProdiDosenIndex;
use App\Livewire\Prodi\Dosen\Show as ProdiDosenShow;
use App\Livewire\Prodi\JadwalKuliah\Index as ProdiJadwalKuliahIndex;
use App\Livewire\Prodi\KonversiNilai\Index as ProdiKonversiNilaiIndex;
use App\Livewire\Prodi\Krs\Index as ProdiKrsIndex;
use App\Livewire\Prodi\Kurikulum\Index as ProdiKurikulumIndex;
use App\Livewire\Prodi\Kurikulum\Show as ProdiKurikulumShow;
use App\Livewire\Prodi\Mahasiswa\Index as ProdiMahasiswaIndex;
use App\Livewire\Prodi\Mahasiswa\Show as ProdiMahasiswaShow;
use App\Livewire\Prodi\Matkul\Index as ProdiMatkulIndex;
use App\Livewire\Prodi\Matkul\Show as ProdiMatkulShow;
use Illuminate\Support\Facades\Route;

// Login gabungan — satu pintu masuk untuk semua tipe akun (admin/akademik/keuangan, dosen,
// mahasiswa), user dikenali lewat email atau username. Tujuan redirect ditentukan oleh
// User::webDashboardRouteName().
Route::get('/', [LoginController::class, 'create'])->name('login');
Route::post('/', [LoginController::class, 'store']);

// Aktivasi mandiri (dosen/mahasiswa yang belum pernah membuat akun) — publik, tanpa auth.
// Lihat catatan di app/Livewire/Auth/Aktivasi.php soal hubungannya dengan ActivationController
// (API, dipakai oleh siak-frontend) yang menjalankan alur data yang sama.
Route::livewire('/aktivasi', Aktivasi::class)->name('aktivasi');
Route::livewire('/verify-email', VerifyEmail::class)->name('verify-email');
Route::livewire('/forgot-password', ForgotPassword::class)->name('forgot-password');
Route::livewire('/reset-password', ResetPasswordLivewire::class)->name('reset-password');

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// "Kembali ke admin" (fitur "Login as", lihat App\Http\Controllers\Web\ImpersonateController) —
// sengaja di luar grup 'admin' & role.admin.web: selama impersonate, identitas sesi adalah
// dosen/mahasiswa target, jadi rute ini harus tetap bisa dituju walau role.admin.web menolaknya.
Route::post('/impersonate/stop', [ImpersonateController::class, 'stop'])
    ->middleware('auth')
    ->name('impersonate.stop');

Route::get('/dashboard', [SuperadminWebLoginController::class, 'dashboard'])
    ->middleware(['auth', 'superadmin.web'])
    ->name('dashboard');

Route::middleware(['auth', 'superadmin.web'])->group(function (): void {
    Route::get('/konfigurasi', [SuperadminEnvConfigController::class, 'edit'])->name('superadmin.konfigurasi');
    Route::put('/konfigurasi', [SuperadminEnvConfigController::class, 'update'])->name('superadmin.konfigurasi.update');
    Route::get('/migrasi', [SuperadminMigrasiController::class, 'index'])->name('superadmin.migrasi');
    Route::post('/migrasi', [SuperadminMigrasiController::class, 'run'])->name('superadmin.migrasi.run');

    // Wizard pembaruan. Rute-rute ini DIKECUALIKAN dari mode pemeliharaan di bootstrap/app.php —
    // lihat alasannya di sana; tetap dijaga middleware superadmin.web dari grup ini.
    Route::get('/pembaruan', [SuperadminUpdateController::class, 'index'])->name('superadmin.pembaruan');
    Route::post('/pembaruan/mulai', [SuperadminUpdateController::class, 'start'])->name('superadmin.pembaruan.mulai');
    Route::post('/pembaruan/langkah', [SuperadminUpdateController::class, 'step'])->name('superadmin.pembaruan.langkah');
    Route::post('/pembaruan/batal', [SuperadminUpdateController::class, 'cancel'])->name('superadmin.pembaruan.batal');
    Route::post('/pembaruan/angkat-pemeliharaan', [SuperadminUpdateController::class, 'lift'])->name('superadmin.pembaruan.angkat');
    Route::get('/test-upload', [SuperadminTestUploadController::class, 'create'])->name('superadmin.test-upload');
    Route::post('/test-upload', [SuperadminTestUploadController::class, 'store'])->name('superadmin.test-upload.store');
});

// Area dosen (sidebar, lihat resources/views/layouts/dosen.blade.php + dosen/partials/sidebar).
// Dashboard, Akun, Kelas & Jadwal Mengajar sudah fungsional; modul lain masih menunjuk ke
// 'dosen.coming-soon' sampai masing-masing diport dari siak-frontend
// (lihat .claude/skills/siak-livewire-module).
Route::middleware(['auth', 'role.dosen.web'])->group(function (): void {
    Route::livewire('/dosen/dashboard', DosenDashboard::class)->name('dosen.dashboard');
    Route::livewire('/dosen/profil', DosenProfil::class)->name('dosen.profil');

    Route::livewire('/dosen/kelas', DosenKelasIndex::class)->name('dosen.kelas');

    // Rute literal ('/dosen/jadwal', '{kelasId}/jurnal-perkuliahan-pdf') harus di atas rute
    // berparameter ('{kelasId}', '{kelasId}/{jadwalId}') — kalau tidak, 'jurnal-perkuliahan-pdf'
    // akan tertangkap sebagai nilai {jadwalId}.
    Route::livewire('/dosen/jadwal', DosenJadwalIndex::class)->name('dosen.jadwal');
    Route::livewire('/dosen/jadwal/{kelasId}', DosenJadwalShow::class)->name('dosen.jadwal.show');
    Route::get('/dosen/jadwal/{kelasId}/jurnal-perkuliahan-pdf', [JurnalPerkuliahanCetakController::class, 'show'])->name('dosen.jadwal.jurnal-perkuliahan');
    Route::livewire('/dosen/jadwal/{kelasId}/{jadwalId}', DosenJadwalDetail::class)->name('dosen.jadwal.detail');

    // Rute literal ('/dosen/nilai') harus di atas rute berparameter ('{kelasId}', '{kelasId}/rekap').
    Route::livewire('/dosen/nilai', DosenNilaiIndex::class)->name('dosen.nilai');
    Route::livewire('/dosen/nilai/{kelasId}', DosenNilaiInput::class)->name('dosen.nilai.input');
    Route::livewire('/dosen/nilai/{kelasId}/rekap', DosenNilaiRekap::class)->name('dosen.nilai.rekap');

    // Rute literal ('/dosen/rps') harus di atas rute berparameter ('{kelasId}').
    Route::livewire('/dosen/rps', DosenRpsIndex::class)->name('dosen.rps');
    Route::livewire('/dosen/rps/{kelasId}', DosenRpsShow::class)->name('dosen.rps.show');

    Route::livewire('/dosen/krs', DosenKrsIndex::class)->name('dosen.krs');

    // Rute literal ('/dosen/perwalian') harus di atas rute berparameter ('{idMahasiswa}', '{idMahasiswa}/bimbingan/export').
    Route::livewire('/dosen/perwalian', DosenPerwalianIndex::class)->name('dosen.perwalian');
    Route::get('/dosen/perwalian/{idMahasiswa}/bimbingan/export', [DosenBimbinganExportController::class, 'excel'])->name('dosen.perwalian.bimbingan.export');
    Route::livewire('/dosen/perwalian/{idMahasiswa}', DosenPerwalianShow::class)->name('dosen.perwalian.show');

    // Rute literal ('/dosen/tugas-akhir', '/dosen/ujian-sidang') harus di atas rute berparameter ('{id}').
    Route::livewire('/dosen/tugas-akhir', DosenTugasAkhirIndex::class)->name('dosen.tugas-akhir');
    Route::livewire('/dosen/tugas-akhir/{id}', DosenTugasAkhirShow::class)->name('dosen.tugas-akhir.show');
    Route::livewire('/dosen/ujian-sidang', DosenUjianSidangIndex::class)->name('dosen.ujian-sidang');
    Route::livewire('/dosen/ujian-sidang/{id}', DosenUjianSidangShow::class)->name('dosen.ujian-sidang.show');

    // Rute literal ('/dosen/arsip') harus di atas rute berparameter ('/nilai/{id}').
    Route::livewire('/dosen/arsip', DosenArsipIndex::class)->name('dosen.arsip');
    Route::livewire('/dosen/arsip/nilai/{id}', DosenArsipNilaiKelas::class)->name('dosen.arsip.nilai');

    // Rute literal ('/dosen/kehadiran', '/dosen/kehadiran/rekap/{id}') harus di atas rute
    // berparameter tunggal ('/dosen/kehadiran/{id}').
    Route::livewire('/dosen/kehadiran', DosenKehadiranIndex::class)->name('dosen.kehadiran');
    Route::livewire('/dosen/kehadiran/rekap/{id}', DosenKehadiranRekapKelas::class)->name('dosen.kehadiran.rekap');
    Route::livewire('/dosen/kehadiran/{id}', DosenKehadiranDetail::class)->name('dosen.kehadiran.detail');
});

// Portal Administrasi Prodi (sidebar, lihat resources/views/layouts/prodi.blade.php +
// prodi/partials/sidebar) — hanya dosen yang menjadi Kepala Prodi/Sekretaris Prodi
// (User::hasProdiScope()), diakses lewat tombol "Administrasi Prodi" di sidebar dosen. Cermin
// dari grup route API 'prodi/*' (routes/api.php, middleware role.admin.prodi), yang read-only
// kecuali approval/transfer-nilai konversi nilai dan update bobot penilaian kurikulum-matkul.
Route::middleware(['auth', 'role.admin.prodi.web'])->group(function (): void {
    Route::livewire('/prodi', ProdiDashboard::class)->name('prodi.dashboard');

    // Rute literal ('/prodi/kurikulum') harus di atas rute berparameter ('{id}').
    Route::livewire('/prodi/kurikulum', ProdiKurikulumIndex::class)->name('prodi.kurikulum');
    Route::livewire('/prodi/kurikulum/{id}', ProdiKurikulumShow::class)->name('prodi.kurikulum.show');

    Route::livewire('/prodi/jadwal-kuliah', ProdiJadwalKuliahIndex::class)->name('prodi.jadwal-kuliah');
    Route::livewire('/prodi/krs', ProdiKrsIndex::class)->name('prodi.krs');
    Route::livewire('/prodi/konversi-nilai', ProdiKonversiNilaiIndex::class)->name('prodi.konversi-nilai');

    // Rute literal ('/prodi/matkul') harus di atas rute berparameter ('{id}').
    Route::livewire('/prodi/matkul', ProdiMatkulIndex::class)->name('prodi.matkul');
    Route::livewire('/prodi/matkul/{id}', ProdiMatkulShow::class)->name('prodi.matkul.show');

    // Rute literal ('/prodi/mahasiswa') harus di atas rute berparameter ('{id}').
    Route::livewire('/prodi/mahasiswa', ProdiMahasiswaIndex::class)->name('prodi.mahasiswa');
    Route::livewire('/prodi/mahasiswa/{id}', ProdiMahasiswaShow::class)->name('prodi.mahasiswa.show');

    // Rute literal ('/prodi/dosen') harus di atas rute berparameter ('{id}').
    Route::livewire('/prodi/dosen', ProdiDosenIndex::class)->name('prodi.dosen');
    Route::livewire('/prodi/dosen/{id}', ProdiDosenShow::class)->name('prodi.dosen.show');
});

// Area mahasiswa (sidebar, lihat resources/views/layouts/mahasiswa.blade.php +
// mahasiswa/partials/sidebar). Semua modul sudah diport dari siak-frontend (lihat
// .claude/skills/siak-livewire-module).
Route::middleware(['auth', 'role.mahasiswa.web'])->group(function (): void {
    Route::livewire('/mahasiswa/dashboard', MahasiswaDashboard::class)->name('mahasiswa.dashboard');
    Route::livewire('/mahasiswa/profil', MahasiswaProfil::class)->name('mahasiswa.profil');

    // Rute literal ('/mahasiswa/jadwal') harus di atas rute berparameter ('{id}').
    Route::livewire('/mahasiswa/jadwal', MahasiswaJadwalIndex::class)->name('mahasiswa.jadwal');
    Route::livewire('/mahasiswa/jadwal/{id}', MahasiswaJadwalDetail::class)->name('mahasiswa.jadwal.detail');

    Route::livewire('/mahasiswa/kehadiran', MahasiswaKehadiranIndex::class)->name('mahasiswa.kehadiran');

    Route::livewire('/mahasiswa/ktm', MahasiswaKtm::class)->name('mahasiswa.ktm');

    // Rute literal ('/mahasiswa/krs/pengajuan') harus di atas rute yang lebih pendek ('/mahasiswa/krs').
    Route::livewire('/mahasiswa/krs/pengajuan', MahasiswaKrsPengajuan::class)->name('mahasiswa.krs.pengajuan');
    Route::livewire('/mahasiswa/krs', MahasiswaKrsIndex::class)->name('mahasiswa.krs');

    Route::livewire('/mahasiswa/bimbingan-akademik', MahasiswaPerwalianIndex::class)->name('mahasiswa.bimbingan-akademik');

    Route::livewire('/mahasiswa/nilai/semester', MahasiswaNilaiSemester::class)->name('mahasiswa.nilai.semester');
    Route::livewire('/mahasiswa/nilai/transkrip', MahasiswaNilaiTranskrip::class)->name('mahasiswa.nilai.transkrip');

    // Rute literal ('/tugas-akhir/pengajuan') harus di atas rute berparameter ('/tugas-akhir/{id}').
    Route::livewire('/mahasiswa/akhir-studi/tugas-akhir', MahasiswaTugasAkhirIndex::class)->name('mahasiswa.akhir-studi.tugas-akhir');
    Route::livewire('/mahasiswa/akhir-studi/tugas-akhir/pengajuan', MahasiswaTugasAkhirPengajuan::class)->name('mahasiswa.akhir-studi.tugas-akhir.pengajuan');
    Route::livewire('/mahasiswa/akhir-studi/tugas-akhir/{id}', MahasiswaTugasAkhirShow::class)->name('mahasiswa.akhir-studi.tugas-akhir.show');
    Route::livewire('/mahasiswa/akhir-studi/bimbingan-tugas-akhir', MahasiswaBimbinganTugasAkhirIndex::class)->name('mahasiswa.akhir-studi.bimbingan-tugas-akhir');
    Route::livewire('/mahasiswa/akhir-studi/bimbingan-tugas-akhir/{id}', MahasiswaBimbinganTugasAkhirShow::class)->name('mahasiswa.akhir-studi.bimbingan-tugas-akhir.show');
    // Rute literal ('/ujian-sidang/pengajuan') harus di atas rute berparameter ('/ujian-sidang/{id}').
    Route::livewire('/mahasiswa/akhir-studi/ujian-sidang', MahasiswaUjianSidangIndex::class)->name('mahasiswa.akhir-studi.ujian-sidang');
    Route::livewire('/mahasiswa/akhir-studi/ujian-sidang/pengajuan', MahasiswaUjianSidangPengajuan::class)->name('mahasiswa.akhir-studi.ujian-sidang.pengajuan');
    Route::livewire('/mahasiswa/akhir-studi/ujian-sidang/{id}', MahasiswaUjianSidangShow::class)->name('mahasiswa.akhir-studi.ujian-sidang.show');
    Route::livewire('/mahasiswa/akhir-studi/yudisium-wisuda', MahasiswaYudisiumWisudaIndex::class)->name('mahasiswa.akhir-studi.yudisium-wisuda');

    Route::livewire('/mahasiswa/tagihan', MahasiswaTagihanIndex::class)->name('mahasiswa.tagihan');
    Route::livewire('/mahasiswa/pembayaran', MahasiswaPembayaranIndex::class)->name('mahasiswa.pembayaran');
    Route::livewire('/mahasiswa/keringanan-biaya', MahasiswaKeringananBiayaIndex::class)->name('mahasiswa.keringanan-biaya');

    Route::livewire('/mahasiswa/survey', MahasiswaSurveyIndex::class)->name('mahasiswa.survey');
    Route::livewire('/mahasiswa/survey/{id}/isi', MahasiswaSurveyIsi::class)->name('mahasiswa.survey.isi');
});

// Panel admin (Livewire) — superadmin/akademik/keuangan. Login-nya sendiri sudah disatukan
// di atas; grup ini menyisakan logout dan halaman-halaman panel.
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/logout', [AdminWebLoginController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

    // role.admin.web = boleh masuk panel; panel.permission = boleh masuk modul yang mana
    // (peta rute→permission di config/panel_access.php, dipakai juga oleh navbar).
    Route::middleware(['auth', 'role.admin.web', 'panel.permission'])->group(function (): void {
        Route::livewire('/dashboard', AdminDashboard::class)->name('dashboard');

        // Menu Akademik
        Route::livewire('akademik/matkul', MatkulIndex::class)->name('akademik.matkul');
        Route::livewire('akademik/matkul/create', MatkulForm::class)->name('akademik.matkul.create');
        // Rute literal (template/import) harus didaftarkan sebelum 'akademik/matkul/{id}' supaya
        // tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::get('akademik/matkul/template/download', [MatkulController::class, 'downloadTemplate'])->name('akademik.matkul.template');
        Route::livewire('akademik/matkul/import', MatkulImport::class)->name('akademik.matkul.import');
        Route::livewire('akademik/matkul/{id}/edit', MatkulForm::class)->name('akademik.matkul.edit');
        Route::livewire('akademik/matkul/{id}', MatkulShow::class)->name('akademik.matkul.show');

        Route::livewire('akademik/jenis-penilaian', JenisPenilaianIndex::class)->name('akademik.jenis-penilaian');
        Route::livewire('akademik/jenis-penilaian/create', JenisPenilaianForm::class)->name('akademik.jenis-penilaian.create');
        Route::livewire('akademik/jenis-penilaian/{id}/edit', JenisPenilaianForm::class)->name('akademik.jenis-penilaian.edit');

        Route::livewire('akademik/kurikulum', KurikulumIndex::class)->name('akademik.kurikulum');
        Route::livewire('akademik/kurikulum/create', KurikulumForm::class)->name('akademik.kurikulum.create');
        Route::livewire('akademik/kurikulum/{id}/edit', KurikulumForm::class)->name('akademik.kurikulum.edit');
        Route::livewire('akademik/kurikulum/{id}', KurikulumShow::class)->name('akademik.kurikulum.show');

        Route::livewire('akademik/krs', KrsIndex::class)->name('akademik.krs');
        Route::livewire('akademik/krs/create', KrsForm::class)->name('akademik.krs.create');
        // Rute literal (template/import) harus didaftarkan sebelum 'akademik/krs/{id}' supaya
        // tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::get('akademik/krs/template/download', [KrsController::class, 'downloadTemplate'])->name('akademik.krs.template');
        Route::livewire('akademik/krs/import', KrsImport::class)->name('akademik.krs.import');
        Route::livewire('akademik/krs/{id}/edit', KrsForm::class)->name('akademik.krs.edit');
        Route::get('akademik/krs/{id}/cetak', [KrsCetakController::class, 'show'])->name('akademik.krs.cetak');
        Route::livewire('akademik/krs/{id}', KrsShow::class)->name('akademik.krs.show');

        // Rute literal (template/import) harus didaftarkan sebelum 'akademik/nilai/{id}' supaya
        // tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('akademik/nilai', NilaiIndex::class)->name('akademik.nilai');
        Route::get('akademik/nilai/template/download', [NilaiController::class, 'downloadTemplate'])->name('akademik.nilai.template');
        Route::livewire('akademik/nilai/import', NilaiImport::class)->name('akademik.nilai.import');
        Route::get('akademik/nilai/{id}/export', [NilaiExportController::class, 'excel'])->name('akademik.nilai.export');
        Route::get('akademik/nilai/{id}/cetak', [NilaiExportController::class, 'pdf'])->name('akademik.nilai.cetak');
        Route::get('akademik/nilai/{id}/transkrip', [NilaiExportController::class, 'transkrip'])->name('akademik.nilai.transkrip');
        Route::livewire('akademik/nilai/{id}/{idKrs}/edit', NilaiForm::class)->name('akademik.nilai.edit');
        Route::livewire('akademik/nilai/{id}', NilaiShow::class)->name('akademik.nilai.show');

        Route::livewire('akademik/rentang-nilai', RentangNilaiIndex::class)->name('akademik.rentang-nilai');
        Route::livewire('akademik/rentang-nilai/create', RentangNilaiForm::class)->name('akademik.rentang-nilai.create');
        Route::livewire('akademik/rentang-nilai/{id}/edit', RentangNilaiForm::class)->name('akademik.rentang-nilai.edit');

        Route::livewire('akademik/konversi-nilai', KonversiNilaiIndex::class)->name('akademik.konversi-nilai');
        Route::livewire('akademik/konversi-nilai/create', KonversiNilaiForm::class)->name('akademik.konversi-nilai.create');
        Route::livewire('akademik/konversi-nilai/{id}', KonversiNilaiShow::class)->name('akademik.konversi-nilai.show');

        // Rute literal (create/template/import) harus didaftarkan sebelum 'akademik/kelas/{id}'
        // supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('akademik/kelas', KelasIndex::class)->name('akademik.kelas');
        Route::livewire('akademik/kelas/create', KelasForm::class)->name('akademik.kelas.create');
        Route::get('akademik/kelas/template/download', [KelasController::class, 'downloadTemplate'])->name('akademik.kelas.template');
        Route::livewire('akademik/kelas/import', KelasImport::class)->name('akademik.kelas.import');
        Route::livewire('akademik/kelas/{id}/edit', KelasForm::class)->name('akademik.kelas.edit');
        Route::livewire('akademik/kelas/{id}', KelasShow::class)->name('akademik.kelas.show');

        // Rute literal (create/template/import) harus didaftarkan sebelum 'akademik/jadwal/{id}'
        // supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('akademik/jadwal', JadwalIndex::class)->name('akademik.jadwal');
        Route::livewire('akademik/jadwal/create', JadwalForm::class)->name('akademik.jadwal.create');
        Route::get('akademik/jadwal/template/download', [JadwalController::class, 'downloadTemplate'])->name('akademik.jadwal.template');
        Route::livewire('akademik/jadwal/import', JadwalImport::class)->name('akademik.jadwal.import');
        Route::livewire('akademik/jadwal/{id}/edit', JadwalForm::class)->name('akademik.jadwal.edit');
        Route::livewire('akademik/jadwal/{id}', JadwalShow::class)->name('akademik.jadwal.show');

        Route::livewire('akademik/jadwal-ujian', JadwalUjianIndex::class)->name('akademik.jadwal-ujian');
        Route::livewire('akademik/jadwal-ujian/create', JadwalUjianForm::class)->name('akademik.jadwal-ujian.create');
        Route::livewire('akademik/jadwal-ujian/{id}/edit', JadwalUjianForm::class)->name('akademik.jadwal-ujian.edit');
        Route::livewire('akademik/jadwal-ujian/{id}', JadwalUjianShow::class)->name('akademik.jadwal-ujian.show');

        // Modul monitoring, tanpa create/edit — cermin dari halaman admin/perkuliahan di frontend
        // (daftar kelas + detail sesi & rekap kehadiran per kelas).
        // Rute literal (template/import/nilai) harus didaftarkan sebelum 'akademik/perkuliahan/{id}'
        // supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('akademik/perkuliahan', PerkuliahanIndex::class)->name('akademik.perkuliahan');
        Route::get('akademik/perkuliahan/template/download', [PerkuliahanController::class, 'downloadImportTemplate'])->name('akademik.perkuliahan.template');
        Route::livewire('akademik/perkuliahan/import', PerkuliahanImport::class)->name('akademik.perkuliahan.import');
        Route::livewire('akademik/perkuliahan/nilai/{id}', PerkuliahanNilai::class)->name('akademik.perkuliahan.nilai');
        Route::livewire('akademik/perkuliahan/{id}', PerkuliahanShow::class)->name('akademik.perkuliahan.show');

        // Rute literal (template/import) harus didaftarkan sebelum 'akademik/tugas-akhir/{id}'
        // supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('akademik/tugas-akhir', TugasAkhirIndex::class)->name('akademik.tugas-akhir');
        Route::get('akademik/tugas-akhir/template/download', [TugasAkhirController::class, 'downloadTemplate'])->name('akademik.tugas-akhir.template');
        Route::livewire('akademik/tugas-akhir/import', TugasAkhirImport::class)->name('akademik.tugas-akhir.import');
        Route::livewire('akademik/tugas-akhir/{id}/ujian-sidang/{sidangId}', UjianSidangShow::class)->name('akademik.tugas-akhir.ujian-sidang');
        Route::livewire('akademik/tugas-akhir/{id}', TugasAkhirShow::class)->name('akademik.tugas-akhir.show');

        // Rute literal (template/import) harus didaftarkan sebelum 'akademik/yudisium/{id}' supaya
        // tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('akademik/yudisium', YudisiumIndex::class)->name('akademik.yudisium');
        Route::livewire('akademik/yudisium/create', YudisiumForm::class)->name('akademik.yudisium.create');
        Route::get('akademik/yudisium/template/download', [YudisiumController::class, 'downloadTemplate'])->name('akademik.yudisium.template');
        Route::livewire('akademik/yudisium/import', YudisiumImport::class)->name('akademik.yudisium.import');
        Route::livewire('akademik/yudisium/{id}', YudisiumShow::class)->name('akademik.yudisium.show');

        Route::livewire('akademik/wisuda', WisudaIndex::class)->name('akademik.wisuda');
        Route::livewire('akademik/wisuda/create', WisudaForm::class)->name('akademik.wisuda.create');
        Route::livewire('akademik/wisuda/{id}/edit', WisudaForm::class)->name('akademik.wisuda.edit');
        Route::livewire('akademik/wisuda/{id}', WisudaShow::class)->name('akademik.wisuda.show');

        // Menu Administrasi
        // Rute literal (template/download, import) harus didaftarkan sebelum 'administrasi/dosen/{id}'
        // supaya tidak tertangkap sebagai id — sama seperti pola di grup Mahasiswa di bawah.
        Route::livewire('administrasi/dosen', DosenIndex::class)->name('administrasi.dosen');
        Route::livewire('administrasi/dosen/create', DosenForm::class)->name('administrasi.dosen.create');
        Route::get('administrasi/dosen/template/download', [DosenController::class, 'downloadTemplate'])->name('administrasi.dosen.template');
        Route::get('administrasi/dosen/export', [DosenExportController::class, 'excel'])->name('administrasi.dosen.export');
        Route::livewire('administrasi/dosen/import', DosenImport::class)->name('administrasi.dosen.import');
        Route::livewire('administrasi/dosen/{id}/edit', DosenForm::class)->name('administrasi.dosen.edit');
        Route::livewire('administrasi/dosen/{id}', DosenShow::class)->name('administrasi.dosen.show');

        // Rute literal (template/download) harus didaftarkan sebelum 'administrasi/dosen-wali/{id}'
        // supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('administrasi/dosen-wali', DosenWaliIndex::class)->name('administrasi.dosen-wali');
        Route::get('administrasi/dosen-wali/template/download', [DosenWaliController::class, 'downloadTemplate'])->name('administrasi.dosen-wali.template');
        Route::livewire('administrasi/dosen-wali/{id}/bimbingan/{dosenWaliId}', DosenWaliRiwayat::class)->name('administrasi.dosen-wali.riwayat');
        Route::livewire('administrasi/dosen-wali/{id}', DosenWaliShow::class)->name('administrasi.dosen-wali.show');

        // Rute literal (import, template/download, export) harus didaftarkan sebelum
        // 'administrasi/mahasiswa/{id}' supaya tidak tertangkap sebagai id
        // (lihat catatan di skill siak-livewire-module).
        Route::livewire('administrasi/mahasiswa', MahasiswaIndex::class)->name('administrasi.mahasiswa');
        Route::livewire('administrasi/mahasiswa/create', MahasiswaForm::class)->name('administrasi.mahasiswa.create');
        Route::get('administrasi/mahasiswa/template/download', [MahasiswaController::class, 'downloadTemplate'])->name('administrasi.mahasiswa.template');
        Route::get('administrasi/mahasiswa/export', [MahasiswaExportController::class, 'excel'])->name('administrasi.mahasiswa.export');
        Route::livewire('administrasi/mahasiswa/import', MahasiswaImport::class)->name('administrasi.mahasiswa.import');
        Route::livewire('administrasi/mahasiswa/{id}/edit', MahasiswaForm::class)->name('administrasi.mahasiswa.edit');
        Route::livewire('administrasi/mahasiswa/{id}', MahasiswaShow::class)->name('administrasi.mahasiswa.show');

        // Rute literal (create/template/import) harus didaftarkan sebelum 'administrasi/kelas-mahasiswa/{id}'
        // supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('administrasi/kelas-mahasiswa', KelompokKelasIndex::class)->name('administrasi.kelas-mahasiswa');
        Route::livewire('administrasi/kelas-mahasiswa/create', KelompokKelasForm::class)->name('administrasi.kelas-mahasiswa.create');
        Route::get('administrasi/kelas-mahasiswa/template/download', [KelompokKelasController::class, 'downloadTemplate'])->name('administrasi.kelas-mahasiswa.template');
        Route::livewire('administrasi/kelas-mahasiswa/import', KelompokKelasImport::class)->name('administrasi.kelas-mahasiswa.import');
        Route::livewire('administrasi/kelas-mahasiswa/{id}/edit', KelompokKelasForm::class)->name('administrasi.kelas-mahasiswa.edit');

        // Rute literal ('create') harus di atas rute berparameter ('{id}/edit').
        Route::livewire('administrasi/ktm', KtmIndex::class)->name('administrasi.ktm');
        Route::livewire('administrasi/ktm/create', KtmForm::class)->name('administrasi.ktm.create');
        Route::livewire('administrasi/ktm/{id}/edit', KtmForm::class)->name('administrasi.ktm.edit');

        Route::livewire('administrasi/ruangan', RuanganIndex::class)->name('administrasi.ruangan');
        Route::livewire('administrasi/ruangan/create', RuanganForm::class)->name('administrasi.ruangan.create');
        Route::livewire('administrasi/ruangan/{id}/edit', RuanganForm::class)->name('administrasi.ruangan.edit');

        Route::livewire('administrasi/survey', SurveyIndex::class)->name('administrasi.survey');
        Route::livewire('administrasi/survey/create', SurveyForm::class)->name('administrasi.survey.create');
        Route::livewire('administrasi/survey/{id}/edit', SurveyForm::class)->name('administrasi.survey.edit');
        Route::get('administrasi/survey/{survey}/statistik/export', [SurveyController::class, 'exportStatistik'])->name('administrasi.survey.statistik.export');
        Route::livewire('administrasi/survey/{id}', SurveyShow::class)->name('administrasi.survey.show');

        Route::livewire('administrasi/pengumuman', PengumumanIndex::class)->name('administrasi.pengumuman');
        Route::livewire('administrasi/pengumuman/create', PengumumanForm::class)->name('administrasi.pengumuman.create');
        Route::livewire('administrasi/pengumuman/{id}/edit', PengumumanForm::class)->name('administrasi.pengumuman.edit');

        // Dashboard keuangan digabung ke dashboard admin utama (admin.dashboard) — bagian mana
        // yang tampil diatur oleh role user, bukan oleh route terpisah (lihat Dashboard::mount()).
        // Rute literal (create, generate) harus didaftarkan sebelum 'keuangan/tagihan/{id}'
        // supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('keuangan/tagihan', TagihanIndex::class)->name('keuangan.tagihan');
        Route::livewire('keuangan/tagihan/create', TagihanForm::class)->name('keuangan.tagihan.create');
        Route::livewire('keuangan/tagihan/{id}/edit', TagihanForm::class)->name('keuangan.tagihan.edit');

        Route::livewire('keuangan/tagihan/generate', TagihanGenerate::class)->name('keuangan.tagihan.generate');

        Route::livewire('keuangan/tagihan/{id}', TagihanShow::class)->name('keuangan.tagihan.show');
        // Rute literal (create, laporan-pelunasan) harus didaftarkan sebelum
        // 'keuangan/pembayaran/{id}' supaya tidak tertangkap sebagai id (lihat catatan di skill
        // siak-livewire-module).
        Route::livewire('keuangan/pembayaran', PembayaranIndex::class)->name('keuangan.pembayaran');
        Route::livewire('keuangan/pembayaran/create', PembayaranForm::class)->name('keuangan.pembayaran.create');

        Route::livewire('keuangan/pembayaran/laporan-pelunasan', PembayaranLaporanPelunasan::class)->name('keuangan.pembayaran.laporan-pelunasan');

        Route::livewire('keuangan/pembayaran/{id}/edit', PembayaranForm::class)->name('keuangan.pembayaran.edit');
        Route::livewire('keuangan/pembayaran/{id}', PembayaranShow::class)->name('keuangan.pembayaran.show');
        // Rute literal (create) harus didaftarkan sebelum '{id}/edit' dan '{id}' supaya tidak
        // tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('keuangan/keringanan-biaya', KeringananBiayaIndex::class)->name('keuangan.keringanan-biaya');
        Route::livewire('keuangan/keringanan-biaya/create', KeringananBiayaForm::class)->name('keuangan.keringanan-biaya.create');
        Route::livewire('keuangan/keringanan-biaya/{id}/edit', KeringananBiayaForm::class)->name('keuangan.keringanan-biaya.edit');

        Route::livewire('keuangan/jenis-keringanan-biaya', JenisKeringananBiayaIndex::class)->name('keuangan.jenis-keringanan-biaya');
        Route::livewire('keuangan/jenis-keringanan-biaya/create', JenisKeringananBiayaForm::class)->name('keuangan.jenis-keringanan-biaya.create');
        Route::livewire('keuangan/jenis-keringanan-biaya/{id}/edit', JenisKeringananBiayaForm::class)->name('keuangan.jenis-keringanan-biaya.edit');

        Route::livewire('keuangan/aturan-akses-keuangan', AturanAksesKeuanganIndex::class)->name('keuangan.aturan-akses-keuangan');
        Route::livewire('keuangan/aturan-akses-keuangan/create', AturanAksesKeuanganForm::class)->name('keuangan.aturan-akses-keuangan.create');
        Route::livewire('keuangan/aturan-akses-keuangan/{id}/edit', AturanAksesKeuanganForm::class)->name('keuangan.aturan-akses-keuangan.edit');
        // Rute literal (create) harus didaftarkan sebelum 'keuangan/struktur-biaya/{id}/edit'
        // supaya konsisten dengan modul lain (lihat catatan di skill siak-livewire-module).
        Route::livewire('keuangan/struktur-biaya', StrukturBiayaIndex::class)->name('keuangan.struktur-biaya');
        Route::livewire('keuangan/struktur-biaya/create', StrukturBiayaForm::class)->name('keuangan.struktur-biaya.create');
        Route::livewire('keuangan/struktur-biaya/{id}/edit', StrukturBiayaForm::class)->name('keuangan.struktur-biaya.edit');

        Route::livewire('keuangan/komponen-biaya', KomponenBiayaIndex::class)->name('keuangan.komponen-biaya');
        Route::livewire('keuangan/komponen-biaya/create', KomponenBiayaForm::class)->name('keuangan.komponen-biaya.create');
        Route::livewire('keuangan/komponen-biaya/{id}/edit', KomponenBiayaForm::class)->name('keuangan.komponen-biaya.edit');

        // Rute literal (create) harus didaftarkan sebelum 'keuangan/kategori-biaya/{id}' supaya
        // tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('keuangan/kategori-biaya', KategoriBiayaIndex::class)->name('keuangan.kategori-biaya');
        Route::livewire('keuangan/kategori-biaya/create', KategoriBiayaForm::class)->name('keuangan.kategori-biaya.create');
        Route::livewire('keuangan/kategori-biaya/{id}/edit', KategoriBiayaForm::class)->name('keuangan.kategori-biaya.edit');
        Route::livewire('keuangan/kategori-biaya/{id}', KategoriBiayaShow::class)->name('keuangan.kategori-biaya.show');

        Route::livewire('fakultas', FakultasIndex::class)->name('fakultas.index');
        Route::livewire('fakultas/create', FakultasForm::class)->name('fakultas.create');
        Route::livewire('fakultas/{id}/edit', FakultasForm::class)->name('fakultas.edit');

        Route::livewire('prodi', ProdiIndex::class)->name('prodi.index');
        Route::livewire('prodi/create', ProdiForm::class)->name('prodi.create');
        Route::livewire('prodi/{id}/edit', ProdiForm::class)->name('prodi.edit');

        Route::livewire('perguruan-tinggi', AdminPerguruanTinggi::class)->name('perguruan-tinggi');

        Route::livewire('jenjang', JenjangIndex::class)->name('jenjang.index');
        Route::livewire('jenjang/create', JenjangForm::class)->name('jenjang.create');
        Route::livewire('jenjang/{id}/edit', JenjangForm::class)->name('jenjang.edit');

        Route::livewire('jalur-masuk', JalurMasukIndex::class)->name('jalur-masuk.index');
        Route::livewire('jalur-masuk/create', JalurMasukForm::class)->name('jalur-masuk.create');
        Route::livewire('jalur-masuk/{id}/edit', JalurMasukForm::class)->name('jalur-masuk.edit');

        Route::livewire('semester', SemesterIndex::class)->name('semester.index');
        Route::livewire('semester/create', SemesterForm::class)->name('semester.create');
        Route::livewire('semester/{id}/edit', SemesterForm::class)->name('semester.edit');

        Route::livewire('jenis-daftar', JenisDaftarIndex::class)->name('jenis-daftar.index');
        Route::livewire('jenis-daftar/create', JenisDaftarForm::class)->name('jenis-daftar.create');
        Route::livewire('jenis-daftar/{id}/edit', JenisDaftarForm::class)->name('jenis-daftar.edit');

        Route::livewire('jenis-matkul', JenisMatkulIndex::class)->name('jenis-matkul.index');
        Route::livewire('jenis-matkul/create', JenisMatkulForm::class)->name('jenis-matkul.create');
        Route::livewire('jenis-matkul/{id}/edit', JenisMatkulForm::class)->name('jenis-matkul.edit');

        // Pengaturan penandatangan transkrip — bukan CRUD, satu form key/value seperti
        // sistem/pengaturan (SMTP). Tidak dibatasi role.admin.superadmin: isinya identitas
        // pejabat penandatangan, bukan kredensial, dan yang mencetak transkrip adalah bagian
        // akademik. Penjagaannya lewat permission 'manage nilai' di config/panel_access.php.
        Route::livewire('akademik/penandatangan-transkrip', TranskripPenandatangan::class)->name('akademik.penandatangan-transkrip');

        Route::livewire('status-akademik', StatusAkademikIndex::class)->name('status-akademik.index');
        Route::livewire('status-akademik/create', StatusAkademikForm::class)->name('status-akademik.create');
        Route::livewire('status-akademik/{id}/edit', StatusAkademikForm::class)->name('status-akademik.edit');

        // Menu Pengguna — rute literal (create/role/permission) harus didaftarkan sebelum
        // 'pengguna/{id}' supaya tidak tertangkap sebagai id (lihat catatan di skill siak-livewire-module).
        Route::livewire('pengguna', PenggunaIndex::class)->name('pengguna.index');
        Route::livewire('pengguna/create', PenggunaForm::class)->name('pengguna.create');

        Route::livewire('pengguna/role', RoleIndex::class)->name('pengguna.role.index');
        Route::livewire('pengguna/role/create', RoleForm::class)->name('pengguna.role.create');
        Route::livewire('pengguna/role/{id}/edit', RoleForm::class)->name('pengguna.role.edit');

        Route::livewire('pengguna/permission', PermissionIndex::class)->name('pengguna.permission.index');
        Route::livewire('pengguna/permission/create', PermissionForm::class)->name('pengguna.permission.create');
        Route::livewire('pengguna/permission/{id}/edit', PermissionForm::class)->name('pengguna.permission.edit');

        Route::livewire('pengguna/{id}/edit', PenggunaForm::class)->name('pengguna.edit');
        Route::livewire('pengguna/{id}', PenggunaShow::class)->name('pengguna.show');

        // "Login as" (App\Http\Controllers\Web\ImpersonateController) — dibatasi Superadmin saja
        // lewat middleware tambahan, sama posturnya dengan grup "Menu Sistem" di bawah: bisa
        // menyamar jadi user lain jelas lebih sensitif daripada sekadar mengelola akun via CRUD
        // biasa, jadi tidak didelegasikan lewat permission ('manage pengguna') seperti CRUD
        // pengguna lainnya.
        Route::middleware('role.admin.superadmin')->group(function (): void {
            Route::post('pengguna/{id}/impersonate', [ImpersonateController::class, 'start'])->name('pengguna.impersonate.start');
        });

        // Menu Sistem — kredensial SMTP dianggap privilege-sensitive (bisa dipakai untuk
        // menyadap email reset password, dst), jadi dibatasi Superadmin saja lewat middleware
        // tambahan, bukan admin_akademik/admin_keuangan yang juga lolos role.admin.web.
        Route::middleware('role.admin.superadmin')->group(function (): void {
            Route::livewire('sistem/pengaturan', SistemPengaturan::class)->name('sistem.pengaturan');
            Route::livewire('sistem/lisensi', SistemLisensi::class)->name('sistem.lisensi');
            Route::livewire('sistem/pembaruan', SistemPembaruan::class)->name('sistem.pembaruan');
            Route::livewire('sistem/plugin', SistemPlugin::class)->name('sistem.plugin');

            // Data wilayah (Negara/Provinsi/Kota/Kecamatan) — rute literal (create/template/import)
            // harus didaftarkan sebelum 'sistem/{modul}/{id}' supaya tidak tertangkap sebagai id
            // (lihat catatan di skill siak-livewire-module).
            Route::livewire('sistem/negara', NegaraIndex::class)->name('sistem.negara');
            Route::livewire('sistem/negara/create', NegaraForm::class)->name('sistem.negara.create');
            Route::get('sistem/negara/template/download', [NegaraController::class, 'downloadTemplate'])->name('sistem.negara.template');
            Route::livewire('sistem/negara/import', NegaraImport::class)->name('sistem.negara.import');
            Route::livewire('sistem/negara/{id}/edit', NegaraForm::class)->name('sistem.negara.edit');

            Route::livewire('sistem/provinsi', ProvinsiIndex::class)->name('sistem.provinsi');
            Route::livewire('sistem/provinsi/create', ProvinsiForm::class)->name('sistem.provinsi.create');
            Route::get('sistem/provinsi/template/download', [ProvinsiController::class, 'downloadTemplate'])->name('sistem.provinsi.template');
            Route::livewire('sistem/provinsi/import', ProvinsiImport::class)->name('sistem.provinsi.import');
            Route::livewire('sistem/provinsi/{id}/edit', ProvinsiForm::class)->name('sistem.provinsi.edit');

            Route::livewire('sistem/kota', KotaIndex::class)->name('sistem.kota');
            Route::livewire('sistem/kota/create', KotaForm::class)->name('sistem.kota.create');
            Route::get('sistem/kota/template/download', [KotaController::class, 'downloadTemplate'])->name('sistem.kota.template');
            Route::livewire('sistem/kota/import', KotaImport::class)->name('sistem.kota.import');
            Route::livewire('sistem/kota/{id}/edit', KotaForm::class)->name('sistem.kota.edit');

            Route::livewire('sistem/kecamatan', KecamatanIndex::class)->name('sistem.kecamatan');
            Route::livewire('sistem/kecamatan/create', KecamatanForm::class)->name('sistem.kecamatan.create');
            Route::get('sistem/kecamatan/template/download', [KecamatanController::class, 'downloadTemplate'])->name('sistem.kecamatan.template');
            Route::livewire('sistem/kecamatan/import', KecamatanImport::class)->name('sistem.kecamatan.import');
            Route::livewire('sistem/kecamatan/{id}/edit', KecamatanForm::class)->name('sistem.kecamatan.edit');
        });

        Route::livewire('profil', AdminProfil::class)->name('profil');
    });
});
