<?php

use App\Http\Controllers\ActivationController;
use App\Http\Controllers\AgamaController;
use App\Http\Controllers\AturanAksesKeuanganController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DosenController;
use App\Http\Controllers\DosenWaliBimbinganController;
use App\Http\Controllers\DosenWaliController;
use App\Http\Controllers\FakultasController;
use App\Http\Controllers\GrupMahasiswaController;
use App\Http\Controllers\JadwalController;
use App\Http\Controllers\JadwalDosenController;
use App\Http\Controllers\JalurMasukController;
use App\Http\Controllers\JenisDaftarController;
use App\Http\Controllers\JenisKeluarController;
use App\Http\Controllers\JenisKeringananBiayaController;
use App\Http\Controllers\JenisKuliahController;
use App\Http\Controllers\JenisMatkulController;
use App\Http\Controllers\JenisPenilaianController;
use App\Http\Controllers\JenjangController;
use App\Http\Controllers\KategoriBiayaController;
use App\Http\Controllers\KategoriBiayaMahasiswaController;
use App\Http\Controllers\KecamatanController;
use App\Http\Controllers\KehadiranController;
use App\Http\Controllers\KelasController;
use App\Http\Controllers\KelompokKelasController;
use App\Http\Controllers\KeringananBiayaController;
use App\Http\Controllers\KomponenBiayaController;
use App\Http\Controllers\KonversiNilaiController;
use App\Http\Controllers\KotaController;
use App\Http\Controllers\KrsController;
use App\Http\Controllers\KtmController;
use App\Http\Controllers\KurikulumController;
use App\Http\Controllers\KurikulumMatkulController;
use App\Http\Controllers\LaporanController;
use App\Http\Controllers\MahasiswaController;
use App\Http\Controllers\MateriPerkuliahanController;
use App\Http\Controllers\MatkulController;
use App\Http\Controllers\MatkulPrasyaratController;
use App\Http\Controllers\NegaraController;
use App\Http\Controllers\NilaiController;
use App\Http\Controllers\NotifikasiController;
use App\Http\Controllers\PembayaranController;
use App\Http\Controllers\PengumumanController;
use App\Http\Controllers\PerkuliahanController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Pmb\AuthController as PmbAuthController;
use App\Http\Controllers\ProdiController;
use App\Http\Controllers\ProvinsiController;
use App\Http\Controllers\RentangNilaiController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RuanganController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StatusAkademikController;
use App\Http\Controllers\StrukturBiayaController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\SurveyQuestionController;
use App\Http\Controllers\TagihanController;
use App\Http\Controllers\TugasAkhirController;
use App\Http\Controllers\TugasController;
use App\Http\Controllers\UjianController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WisudaController;
use App\Http\Controllers\YudisiumController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
});

// Route untuk data lokasi (negara, provinsi, kota, kecamatan) dan agama - tidak perlu autentikasi
Route::get('negara', [NegaraController::class, 'index']);
Route::get('provinsi', [ProvinsiController::class, 'index']);
Route::get('kota', [KotaController::class, 'index']);
Route::get('kecamatan', [KecamatanController::class, 'index']);
Route::get('agama', [AgamaController::class, 'index']);

// Route untuk aktivasi akun - tidak perlu autentikasi
Route::post('activation/check', [ActivationController::class, 'checkIdentifier']);
Route::post('activation/register', [ActivationController::class, 'register']);
Route::post('activation/verify-email', [ActivationController::class, 'verifyEmail']);
Route::post('activation/resend-verification', [ActivationController::class, 'resendVerification']);

// Route public untuk mendapatkan informasi perguruan tinggi (untuk halaman login)
Route::get('public/univ-info', [SettingController::class, 'getUnivInfo']);

// Route public untuk pengumuman aktif (tanpa autentikasi)
Route::get('public/pengumuman', [PengumumanController::class, 'getPublicPengumuman']);

// Route untuk aplikasi partner (mis. Siska) - autentikasi dengan API key, bukan Sanctum
Route::middleware('partner.api.key')->prefix('partner')->group(function (): void {
    Route::get('ping', function () {
        return response()->json(['message' => 'ok']);
    });
    Route::get('mahasiswa', [MahasiswaController::class, 'index']);
    Route::get('mahasiswa/nim/{nim}', [MahasiswaController::class, 'showByNim']);
    Route::get('mahasiswa/{mahasiswa}', [MahasiswaController::class, 'show']);
    Route::get('prodi', [ProdiController::class, 'index']);
    Route::get('prodi/{prodi}', [ProdiController::class, 'show']);
    Route::get('jenjang', [JenjangController::class, 'index']);
    Route::get('jenjang/{jenjang}', [JenjangController::class, 'show']);
    Route::get('jalur-masuk', [JalurMasukController::class, 'index']);
    Route::get('jalur-masuk/{jalurMasuk}', [JalurMasukController::class, 'show']);
    Route::get('jenis-daftar', [JenisDaftarController::class, 'index']);
    Route::get('jenis-daftar/{jenisDaftar}', [JenisDaftarController::class, 'show']);
    Route::get('semester', [SemesterController::class, 'index']);
    Route::get('semester/{semester}', [SemesterController::class, 'show']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    /** Satu endpoint: duplikat di grup mahasiswa + dosen akan saling menimpa (yang terakhir = dosen saja). */
    Route::get('semester/list', [SemesterController::class, 'getList']);
    /** Dipakai bersama oleh mahasiswa dan admin keuangan (dropdown periode); data semester tidak sensitif. */
    Route::get('keuangan/semester', [SemesterController::class, 'index']);

    Route::get('notifikasi', [NotifikasiController::class, 'index']);
    Route::get('notifikasi/unread-count', [NotifikasiController::class, 'unreadCount']);
    Route::post('notifikasi/{notifikasi}/read', [NotifikasiController::class, 'markAsRead']);
    Route::post('notifikasi/read-all', [NotifikasiController::class, 'markAllAsRead']);

    // Route untuk mahasiswa - HARUS diletakkan sebelum route admin
    Route::middleware('role.mahasiswa')->group(function (): void {
        Route::get('jadwal-kuliah/{idJadwal}/detail', [KrsController::class, 'getJadwalDetailMahasiswa']);
        Route::get('jadwal-kuliah/{idJadwal}/tugas', [TugasController::class, 'getByJadwalForMahasiswa']);
        Route::get('kehadiran/mahasiswa/rekap/kelas/{idKelas}', [KehadiranController::class, 'getRekapByKelasForMahasiswa']);
        Route::post('tugas/{idTugas}/kumpulkan', [TugasController::class, 'submitKumpulkanMahasiswa']);
        Route::get('jadwal-kuliah', [KrsController::class, 'getJadwalKuliah']);
        Route::get('krs-saya/export-pdf', [KrsController::class, 'exportKrsPdf']);
        Route::get('krs-saya', [KrsController::class, 'getKrsBySemester']);
        Route::get('krs/pengajuan/jadwal', [KrsController::class, 'getJadwalPengajuan']);
        Route::post('krs/pengajuan', [KrsController::class, 'submitPengajuanKrs']);
        Route::delete('krs/pengajuan/{id}', [KrsController::class, 'cancelPengajuanKrs']);
        Route::get('transkrip', [NilaiController::class, 'getTranskripLengkap']);
        Route::get('nilai-semester', [NilaiController::class, 'getTranskripMahasiswa']);
        Route::get('ip-per-semester', [NilaiController::class, 'getIpPerSemester']);
        Route::get('tagihan-saya', [TagihanController::class, 'getTagihanMahasiswa']);
        /** Path selain mahasiswa/... agar tidak bentrok dengan apiResource mahasiswa/{mahasiswa} (admin). */
        Route::get('keuangan/cek-akses', [TagihanController::class, 'cekAksesKeuanganMahasiswa']);
        Route::get('keuangan/jenis-keringanan-biaya', [JenisKeringananBiayaController::class, 'indexAktifForMahasiswa']);
        Route::get('keringanan-biaya-saya', [KeringananBiayaController::class, 'indexSaya']);
        Route::post('keringanan-biaya-saya', [KeringananBiayaController::class, 'storeSaya']);
        Route::get('keringanan-biaya-saya/{id}', [KeringananBiayaController::class, 'showSaya'])->whereNumber('id');
        Route::get('pembayaran-saya', [PembayaranController::class, 'getPembayaranMahasiswa']);
        Route::post('pembayaran-saya', [PembayaranController::class, 'storeByMahasiswa']);
        Route::get('survey/aktif', [SurveyController::class, 'getSurveyAktifForMahasiswa']);
        Route::get('survey/{id}/pertanyaan', [SurveyQuestionController::class, 'getBySurvey']);
        Route::get('survey/{id}/response/{idKrs}', [SurveyController::class, 'getSurveyResponse']);
        Route::post('survey/submit', [SurveyController::class, 'submitSurveyResponse']);
        Route::get('mahasiswa/profile', [MahasiswaController::class, 'getMyProfile']);
        Route::put('mahasiswa/profile', [MahasiswaController::class, 'updateMyProfile']);
        Route::put('mahasiswa/password', [MahasiswaController::class, 'updateMyPassword']);
        Route::post('mahasiswa/foto', [MahasiswaController::class, 'uploadMyFoto']);
        Route::get('mahasiswa/ktm', [KtmController::class, 'myShow']);
        Route::post('mahasiswa/ktm', [KtmController::class, 'myStore']);
        Route::post('mahasiswa/ktm/regenerate', [KtmController::class, 'myRegenerate']);
        Route::get('pengumuman/aktif', [PengumumanController::class, 'getAktifForMahasiswa']);
        Route::get('bimbingan-akademik', [DosenWaliController::class, 'getBimbinganAkademikMahasiswa']);
        Route::post('bimbingan-akademik', [DosenWaliBimbinganController::class, 'storeForBimbinganAkademikMahasiswa']);
        Route::patch('bimbingan-akademik/{bimbingan}', [DosenWaliBimbinganController::class, 'updateForBimbinganAkademikMahasiswa'])
            ->whereNumber('bimbingan');
        Route::get('mahasiswa/tugas-akhir', [TugasAkhirController::class, 'listTugasAkhirMahasiswa']);
        Route::get('mahasiswa/tugas-akhir/{tugasAkhir}', [TugasAkhirController::class, 'showTugasAkhirMahasiswa'])
            ->whereNumber('tugasAkhir');
        Route::get('mahasiswa/tugas-akhir/pengajuan', [TugasAkhirController::class, 'pengajuanContextMahasiswa']);
        Route::post('mahasiswa/tugas-akhir/pengajuan', [TugasAkhirController::class, 'storePengajuanMahasiswa']);
        Route::put('mahasiswa/tugas-akhir/pengajuan', [TugasAkhirController::class, 'updatePengajuanMahasiswa']);
        Route::get('mahasiswa/tugas-akhir/bimbingan', [TugasAkhirController::class, 'bimbinganIndexMahasiswa']);
        Route::get('mahasiswa/tugas-akhir/{tugasAkhir}/bimbingan', [TugasAkhirController::class, 'bimbinganRiwayatByTugasAkhirMahasiswa'])
            ->whereNumber('tugasAkhir');
        Route::post('mahasiswa/tugas-akhir/{tugasAkhir}/bimbingan', [TugasAkhirController::class, 'storeBimbinganMahasiswa'])
            ->whereNumber('tugasAkhir');
        Route::patch('mahasiswa/tugas-akhir/bimbingan/{bimbingan}', [TugasAkhirController::class, 'updateBimbinganCatatanMahasiswa'])
            ->whereNumber('bimbingan');
        Route::get('mahasiswa/tugas-akhir/ujian-sidang', [TugasAkhirController::class, 'ujianSidangContextMahasiswa']);
        Route::get('mahasiswa/tugas-akhir/ujian-sidang/{ujianSidang}', [TugasAkhirController::class, 'showUjianSidangMahasiswa'])
            ->whereNumber('ujianSidang');
        Route::post('mahasiswa/tugas-akhir/ujian-sidang', [TugasAkhirController::class, 'storeUjianSidangMahasiswa']);
        Route::get('mahasiswa/akhir-studi/yudisium-wisuda', [WisudaController::class, 'getMyYudisiumWisuda']);
        Route::post('mahasiswa/akhir-studi/yudisium-wisuda/daftar', [WisudaController::class, 'daftarWisudaMahasiswa']);
        Route::post('mahasiswa/akhir-studi/yudisium-wisuda/foto', [WisudaController::class, 'uploadMyFotoWisuda']);
    });

    // Route untuk dosen - HARUS diletakkan sebelum route admin
    Route::middleware('role.dosen')->group(function (): void {
        Route::get('jadwal-mengajar', [JadwalDosenController::class, 'getMyJadwal']);
        Route::get('kelas-ampu/{kelasId}/rincian-jadwal', [JadwalDosenController::class, 'getRincianJadwalKelasAmpu']);
        Route::get('kelas-ampu/{kelasId}/jurnal-perkuliahan-pdf', [JadwalDosenController::class, 'downloadJurnalPerkuliahanPdf']);
        Route::get('jadwal-ampu/opsi-edit', [JadwalDosenController::class, 'getOpsiEditJadwalAmpu']);
        Route::put('jadwal-ampu/{jadwalId}', [JadwalDosenController::class, 'updateJadwalAmpu'])->whereNumber('jadwalId');
        Route::put('jadwal-ampu/{jadwalId}/bahasan', [JadwalDosenController::class, 'updateBahasanJadwalAmpu'])->whereNumber('jadwalId');
        Route::get('kelas-ampu/{kelasId}/mahasiswa-krs', [JadwalDosenController::class, 'getMahasiswaKrsKelasAmpu']);
        Route::get('kelas-ampu', [JadwalDosenController::class, 'getKelasAmpu']);
        Route::get('rps/kelas-sebagai-pic', [JadwalDosenController::class, 'getKelasSebagaiPicUntukRps']);
        Route::get('rps/kelas/{kelasId}', [JadwalDosenController::class, 'getRpsByKelas'])->whereNumber('kelasId');
        Route::get('rps/kelas/{kelasId}/sumber-duplikat', [JadwalDosenController::class, 'getRpsSumberDuplikat'])->whereNumber('kelasId');
        Route::post('rps/kelas/{kelasId}/duplikat-dari', [JadwalDosenController::class, 'duplikatRpsDariKelasLain'])->whereNumber('kelasId');
        Route::get('rps/kelas/{kelasId}/pdf', [JadwalDosenController::class, 'downloadRpsPdf'])->whereNumber('kelasId');
        Route::put('rps/kelas/{kelasId}', [JadwalDosenController::class, 'upsertRpsByKelas'])->whereNumber('kelasId');
        Route::post('rps/kelas/{kelasId}/cpl', [JadwalDosenController::class, 'storeRpsCpl'])->whereNumber('kelasId');
        Route::put('rps/cpl/{cplId}', [JadwalDosenController::class, 'updateRpsCpl'])->whereNumber('cplId');
        Route::delete('rps/cpl/{cplId}', [JadwalDosenController::class, 'destroyRpsCpl'])->whereNumber('cplId');
        Route::post('rps/kelas/{kelasId}/cpmk', [JadwalDosenController::class, 'storeRpsCpmk'])->whereNumber('kelasId');
        Route::put('rps/cpmk/{cpmkId}', [JadwalDosenController::class, 'updateRpsCpmk'])->whereNumber('cpmkId');
        Route::delete('rps/cpmk/{cpmkId}', [JadwalDosenController::class, 'destroyRpsCpmk'])->whereNumber('cpmkId');
        Route::post('rps/cpmk/{cpmkId}/subcpmk', [JadwalDosenController::class, 'storeRpsSubcpmk'])->whereNumber('cpmkId');
        Route::put('rps/subcpmk/{subcpmkId}', [JadwalDosenController::class, 'updateRpsSubcpmk'])->whereNumber('subcpmkId');
        Route::delete('rps/subcpmk/{subcpmkId}', [JadwalDosenController::class, 'destroyRpsSubcpmk'])->whereNumber('subcpmkId');
        Route::post('rps/kelas/{kelasId}/pembelajaran', [JadwalDosenController::class, 'storeRpsPembelajaran'])->whereNumber('kelasId');
        Route::put('rps/pembelajaran/{pembelajaranId}', [JadwalDosenController::class, 'updateRpsPembelajaran'])->whereNumber('pembelajaranId');
        Route::delete('rps/pembelajaran/{pembelajaranId}', [JadwalDosenController::class, 'destroyRpsPembelajaran'])->whereNumber('pembelajaranId');
        Route::get('perkuliahan/kelas/{id}', [PerkuliahanController::class, 'getByKelas']);
        Route::post('perkuliahan', [PerkuliahanController::class, 'store']);
        Route::post('perkuliahan/{id}/selesai-sesi', [PerkuliahanController::class, 'selesaiSesi']);
        Route::put('perkuliahan/{id}', [PerkuliahanController::class, 'update']);
        Route::delete('perkuliahan/{id}', [PerkuliahanController::class, 'destroy']);
        Route::get('materi-perkuliahan/jadwal/{id}', [MateriPerkuliahanController::class, 'getByJadwal']);
        Route::get('materi-perkuliahan/perkuliahan/{id}', [MateriPerkuliahanController::class, 'getByPerkuliahan']);
        Route::post('materi-perkuliahan', [MateriPerkuliahanController::class, 'store']);
        Route::put('materi-perkuliahan/{id}', [MateriPerkuliahanController::class, 'update']);
        Route::delete('materi-perkuliahan/{id}', [MateriPerkuliahanController::class, 'destroy']);
        Route::get('perkuliahan/pertemuan-count', [PerkuliahanController::class, 'getPertemuanCount']);
        Route::get('perkuliahan/my', [PerkuliahanController::class, 'getMyPerkuliahan']);
        Route::get('kehadiran/perkuliahan/{id}', [KehadiranController::class, 'getByPerkuliahan']);
        Route::get('kehadiran/rekap/kelas/{id}', [KehadiranController::class, 'getRekapByKelas']);
        Route::post('kehadiran/perkuliahan/{id}', [KehadiranController::class, 'storeOrUpdate']);
        Route::delete('kehadiran/{id}', [KehadiranController::class, 'destroy']);
        Route::get('krs/mahasiswa-count', [KrsController::class, 'getMahasiswaCount']);
        Route::get('krs/mahasiswa-bimbingan', [KrsController::class, 'getMahasiswaBimbingan']);
        Route::get('krs/pending/{id}', [KrsController::class, 'getKrsPending']);
        Route::post('krs/approve', [KrsController::class, 'approveKrs']);
        Route::get('perwalian/bimbingan-akademik', [DosenWaliController::class, 'getMyBimbingan']);
        Route::get('perwalian/bimbingan-akademik/{idMahasiswa}/biodata', [DosenWaliController::class, 'getMyBimbinganMahasiswaBiodata'])
            ->whereNumber('idMahasiswa');
        Route::get('perwalian/bimbingan-akademik/{idMahasiswa}/krs-by-semester', [KrsController::class, 'getKrsBySemesterForBimbinganWali'])
            ->whereNumber('idMahasiswa');
        Route::get('perwalian/bimbingan-akademik/{idMahasiswa}/transkrip-sementara', [NilaiController::class, 'getTranskripLengkapForBimbinganWali'])
            ->whereNumber('idMahasiswa');
        Route::post('perwalian/bimbingan-akademik/{idMahasiswa}/bimbingan', [DosenWaliBimbinganController::class, 'storeForBimbinganAkademikWali'])
            ->whereNumber('idMahasiswa');
        Route::post('perwalian/bimbingan-akademik/{idMahasiswa}/bimbingan/{bimbingan}', [DosenWaliBimbinganController::class, 'updateForBimbinganAkademikWali'])
            ->whereNumber('idMahasiswa')
            ->whereNumber('bimbingan');
        Route::get('perwalian/bimbingan-akademik/{idMahasiswa}/bimbingan/export', [DosenWaliBimbinganController::class, 'exportExcelForBimbinganAkademikWali'])
            ->whereNumber('idMahasiswa');
        Route::get('perwalian/bimbingan-akademik/{idMahasiswa}', [DosenWaliController::class, 'getMyBimbinganRiwayat'])
            ->whereNumber('idMahasiswa');
        Route::get('nilai/mata-kuliah', [NilaiController::class, 'getMyMataKuliah']);
        Route::get('nilai/kelas/{id}', [NilaiController::class, 'getMahasiswaByKelas']);
        Route::get('nilai/jenis-penilaian', [NilaiController::class, 'getJenisPenilaian']);
        Route::post('nilai/komponen', [NilaiController::class, 'storeNilaiKomponen']);
        Route::post('nilai/kelas/{id}/kalkulasi-akhir', [NilaiController::class, 'kalkulasiNilaiAkhir']);
        Route::post('nilai/kelas/{id}/kalkulasi-preview', [NilaiController::class, 'kalkulasiPreview']);
        Route::post('nilai/kelas/{id}/finalize', [NilaiController::class, 'finalizeNilai']);
        Route::post('nilai/revisi', [NilaiController::class, 'storeRevisiNilai']);
        Route::put('nilai/update-by-krs', [NilaiController::class, 'updateNilaiByKrs']);
        Route::get('dosen/profile', [DosenController::class, 'getMyProfile']);
        Route::put('dosen/profile', [DosenController::class, 'updateMyProfile']);
        Route::put('dosen/password', [DosenController::class, 'updateMyPassword']);
        Route::post('dosen/foto', [DosenController::class, 'uploadMyFoto']);
        Route::get('dosen/tugas-akhir/bimbingan', [TugasAkhirController::class, 'listTugasAkhirBimbinganDosen']);
        Route::get('dosen/tugas-akhir/{tugasAkhir}', [TugasAkhirController::class, 'showTugasAkhirDetailDosen']);
        Route::post('dosen/tugas-akhir/{tugasAkhir}/bimbingan', [TugasAkhirController::class, 'storeTugasAkhirBimbinganDosen']);
        Route::get('dosen/ujian-sidang/penguji', [TugasAkhirController::class, 'listUjianSidangPengujiDosen']);
        Route::get(
            'dosen/ujian-sidang/penguji/{pengujiSidang}/preview-finalisasi-nilai',
            [TugasAkhirController::class, 'previewFinalisasiNilaiUjianSidangDosen']
        );
        Route::post(
            'dosen/ujian-sidang/penguji/{pengujiSidang}/finalisasi-nilai',
            [TugasAkhirController::class, 'finalisasiNilaiUjianSidangDosen']
        );
        Route::get('dosen/ujian-sidang/penguji/{pengujiSidang}', [TugasAkhirController::class, 'showUjianSidangPengujiDosen']);
        Route::patch('dosen/ujian-sidang/penguji/{pengujiSidang}', [TugasAkhirController::class, 'updateUjianSidangPengujiDosen']);
        Route::get('tugas/jadwal/{jadwalId}/pengumpulan', [TugasController::class, 'getPengumpulanByJadwalForDosen']);
        Route::put('tugas/pengumpulan/{id}/status', [TugasController::class, 'updatePengumpulanStatusForDosen']);
        Route::get('tugas/jadwal/{id}', [TugasController::class, 'getByJadwal']);
        Route::get('tugas/kelas/{id}', [TugasController::class, 'getByKelas']);
        Route::post('tugas', [TugasController::class, 'store']);
        Route::put('tugas/{id}', [TugasController::class, 'update']);
        Route::delete('tugas/{id}', [TugasController::class, 'destroy']);
    });

    // Route khusus portal /prodi (middleware: punya scope prodi). Panel admin utama memakai role.admin + filter scope di controller.
    Route::prefix('prodi')->middleware('role.admin.prodi')->group(function (): void {
        Route::get('mahasiswa', [MahasiswaController::class, 'index']);
        Route::get('mahasiswa/{id}', [MahasiswaController::class, 'showProdi']);
        Route::get('matkul', [MatkulController::class, 'index']);
        Route::get('matkul/{matkul}', [MatkulController::class, 'show']);
        Route::get('kurikulum', [KurikulumController::class, 'index']);
        Route::get('kurikulum/{kurikulum}', [KurikulumController::class, 'show']);
        Route::get('kurikulum-matkul/{id}', [KurikulumMatkulController::class, 'show']);
        Route::put('kurikulum-matkul/{id}/bobot-penilaian', [KurikulumMatkulController::class, 'updateBobotPenilaian']);
        Route::get('jenis-penilaian', [JenisPenilaianController::class, 'index']);
        Route::get('settings/prefix/{prefix}', [SettingController::class, 'getByPrefix']);
        Route::get('semester', [SemesterController::class, 'index']);
        Route::get('grup-mahasiswa', [GrupMahasiswaController::class, 'index']);
        Route::get('status-akademik', [StatusAkademikController::class, 'index']);
        Route::get('dosen', [DosenController::class, 'index']);
        Route::get('dosen/{dosen}', [DosenController::class, 'show']);
        Route::get('jadwal-kuliah', [JadwalController::class, 'indexProdi']);
        Route::get('jadwal-kuliah/options/kelas', [JadwalController::class, 'getKelasOptionsProdi']);
        Route::get('krs', [KrsController::class, 'indexProdi']);
        Route::get('krs/mahasiswa/{id}/by-semester', [KrsController::class, 'getKrsBySemesterForMahasiswaProdi']);
        Route::get('nilai/mahasiswa/{id}/by-semester', [NilaiController::class, 'getNilaiBySemesterForMahasiswaProdi']);
        Route::get('tagihan/mahasiswa/{id}/by-semester', [TagihanController::class, 'getTagihanBySemesterForMahasiswaProdi']);
        Route::get('konversi-nilai', [KonversiNilaiController::class, 'indexProdi']);
        Route::get('konversi-nilai/{id}', [KonversiNilaiController::class, 'showProdi']);
        Route::post('konversi-nilai/{id}/transfer-nilai', [KonversiNilaiController::class, 'transferToNilaiProdi']);
        Route::patch('konversi-nilai/{id}/approval', [KonversiNilaiController::class, 'setApprovalProdi']);
    });

    // Route panel admin (termasuk admin ber-scope prodi saja; pembatasan data di controller)
    Route::middleware('role.admin')->group(function (): void {
        Route::get('dashboard/mahasiswa-krs-stats', [DashboardController::class, 'getMahasiswaKrsStats']);
        Route::get('dashboard/mahasiswa-per-prodi', [DashboardController::class, 'getMahasiswaPerProdiBySemesterMasuk']);
        Route::get('dashboard/antrian-tindakan', [DashboardController::class, 'getAntrianTindakan']);
        Route::get('dashboard/nilai-belum-finalisasi', [DashboardController::class, 'getNilaiBelumFinalisasi']);

        // Route laporan
        Route::get('laporan/mahasiswa-aktif', [LaporanController::class, 'getMahasiswaAktif']);
        Route::get('laporan/mahasiswa-aktif/export', [LaporanController::class, 'exportMahasiswaAktif']);
        Route::get('laporan/persetujuan-krs', [LaporanController::class, 'getPersetujuanKrs']);
        Route::get('laporan/persetujuan-krs/export', [LaporanController::class, 'exportPersetujuanKrs']);
        Route::get('laporan/pengisian-nilai', [LaporanController::class, 'getPengisianNilai']);
        Route::get('laporan/pengisian-nilai/export', [LaporanController::class, 'exportPengisianNilai']);
        Route::get('laporan/pelunasan-tagihan', [LaporanController::class, 'getPelunasanTagihan']);
        Route::get('laporan/pelunasan-tagihan/export', [LaporanController::class, 'exportPelunasanTagihan']);

        Route::apiResource('fakultas', FakultasController::class);

        Route::apiResource('dosen', DosenController::class);
        Route::get('dosen/template/download', [DosenController::class, 'downloadTemplate']);
        Route::post('dosen/import', [DosenController::class, 'import']);
        Route::get('dosen/{id}/jadwal', [JadwalDosenController::class, 'getByDosen']);
        Route::get('dosen/{id}/kelas-diampu', [JadwalDosenController::class, 'getKelasDiampuByDosen']);

        Route::get('dosen-wali/options/mahasiswa', [DosenWaliController::class, 'getMahasiswaOptions']);
        Route::get('dosen-wali/template/download', [DosenWaliController::class, 'downloadTemplate']);
        Route::post('dosen-wali/import', [DosenWaliController::class, 'import']);
        Route::post('dosen-wali/set-kuota-bulk', [DosenWaliController::class, 'setKuotaBulk']);
        Route::get('dosen-wali/{dosenWali}/bimbingan', [DosenWaliBimbinganController::class, 'index']);
        Route::post('dosen-wali/{dosenWali}/bimbingan', [DosenWaliBimbinganController::class, 'store']);
        Route::put('dosen-wali-bimbingan/{dosenWaliBimbingan}', [DosenWaliBimbinganController::class, 'update']);
        Route::post('dosen-wali-bimbingan/{dosenWaliBimbingan}', [DosenWaliBimbinganController::class, 'update']);
        Route::delete('dosen-wali-bimbingan/{dosenWaliBimbingan}', [DosenWaliBimbinganController::class, 'destroy']);
        Route::apiResource('dosen-wali', DosenWaliController::class);

        Route::apiResource('prodi', ProdiController::class);

        Route::apiResource('jenjang', JenjangController::class);
        Route::get('wisuda/{wisuda}/calon-peserta', [WisudaController::class, 'eligibleMahasiswa']);
        Route::get('wisuda/{wisuda}/peserta/export-pdf', [WisudaController::class, 'exportPesertaPdf']);
        Route::get('wisuda/{wisuda}/peserta/export-excel', [WisudaController::class, 'exportPesertaExcel']);
        Route::post('wisuda/{wisuda}/peserta', [WisudaController::class, 'storePeserta']);
        Route::get('wisuda/{wisuda}/peserta/{peserta}', [WisudaController::class, 'showPeserta']);
        Route::put('wisuda/{wisuda}/peserta/{peserta}', [WisudaController::class, 'updatePeserta']);
        Route::delete('wisuda/{wisuda}/peserta/{peserta}', [WisudaController::class, 'destroyPeserta']);
        Route::apiResource('wisuda', WisudaController::class);
        Route::apiResource('rentang-nilai', RentangNilaiController::class);

        Route::apiResource('jenis-daftar', JenisDaftarController::class);

        Route::apiResource('jalur-masuk', JalurMasukController::class);

        Route::apiResource('jenis-matkul', JenisMatkulController::class);

        Route::get('matkul/template/download', [MatkulController::class, 'downloadTemplate']);
        Route::post('matkul/import', [MatkulController::class, 'import']);
        Route::get('matkul/{matkul}/prasyarat', [MatkulPrasyaratController::class, 'index']);
        Route::post('matkul/{matkul}/prasyarat', [MatkulPrasyaratController::class, 'store']);
        Route::put('matkul/{matkul}/prasyarat/{matkulPrasyarat}', [MatkulPrasyaratController::class, 'update']);
        Route::delete('matkul/{matkul}/prasyarat/{matkulPrasyarat}', [MatkulPrasyaratController::class, 'destroy']);
        Route::apiResource('matkul', MatkulController::class);

        Route::post('kurikulum/{kurikulum}/bobot-penilaian-massal', [KurikulumController::class, 'applyBobotPenilaianMassal']);
        Route::apiResource('kurikulum', KurikulumController::class);
        Route::get('kurikulum/template/download', [KurikulumController::class, 'downloadTemplate']);
        Route::post('kurikulum/import', [KurikulumController::class, 'import']);
        Route::get('kurikulum-matkul/template/download', [KurikulumMatkulController::class, 'downloadTemplate']);
        Route::post('kurikulum-matkul/import', [KurikulumMatkulController::class, 'import']);
        Route::get('kurikulum-matkul/{id}', [KurikulumMatkulController::class, 'show']);
        Route::post('kurikulum-matkul/{id}/sync-dari-matkul', [KurikulumMatkulController::class, 'syncFromMatkul']);
        Route::put('kurikulum-matkul/{id}/bobot-penilaian', [KurikulumMatkulController::class, 'updateBobotPenilaian']);

        Route::apiResource('semester', SemesterController::class);

        Route::apiResource('settings', SettingController::class);
        Route::post('settings/bulk-update', [SettingController::class, 'bulkUpdate']);
        Route::get('settings/prefix/{prefix}', [SettingController::class, 'getByPrefix']);
        Route::post('settings/upload', [SettingController::class, 'uploadFile']);

        Route::get('mahasiswa/{id}/status-akademik/by-semester', [MahasiswaController::class, 'getStatusAkademikBySemester']);
        Route::apiResource('mahasiswa', MahasiswaController::class);
        Route::get('mahasiswa/template/download', [MahasiswaController::class, 'downloadTemplate']);
        Route::post('mahasiswa/import', [MahasiswaController::class, 'import']);

        Route::get('kelompok-kelas/template/download', [KelompokKelasController::class, 'downloadTemplate']);
        Route::post('kelompok-kelas/import', [KelompokKelasController::class, 'import']);
        Route::apiResource('kelompok-kelas', KelompokKelasController::class);

        Route::get('kelas/template/download', [KelasController::class, 'downloadTemplate']);
        Route::post('kelas/import', [KelasController::class, 'import']);
        Route::get('kelas/options/kurikulum-matkul', [KelasController::class, 'getKurikulumMatkulOptions']);
        Route::get('kelas/{kelas}/edit', [KelasController::class, 'edit']);
        Route::get('kelas/{id}/detail-jadwal', [KelasController::class, 'getDetailWithJadwal']);
        Route::apiResource('kelas', KelasController::class);

        Route::apiResource('grup-mahasiswa', GrupMahasiswaController::class);

        Route::get('ktm/options/mahasiswa', [KtmController::class, 'getMahasiswaOptions']);
        Route::get('ktm/settings/template', [KtmController::class, 'getSettingTemplate']);
        Route::post('ktm/settings/template', [KtmController::class, 'storeSettingTemplate']);
        Route::post('ktm/{ktm}/regenerate', [KtmController::class, 'regenerate']);
        Route::apiResource('ktm', KtmController::class);

        Route::get('jadwal/{jadwal}/detail', [JadwalController::class, 'detail']);
        Route::get('jadwal/{jadwal}/edit', [JadwalController::class, 'edit']);
        Route::get('jadwal/export', [JadwalController::class, 'export']);
        Route::get('jadwal/options/kelas', [JadwalController::class, 'getKelasOptions']);
        Route::get('jadwal/options/dosen-kelas', [JadwalController::class, 'getDosenByKelasForJadwal']);
        Route::get('jadwal/options/kelompok-kelas', [JadwalController::class, 'getKelompokKelasOptions']);
        Route::get('jadwal/template/download', [JadwalController::class, 'downloadTemplate']);
        Route::post('jadwal/import', [JadwalController::class, 'import']);
        Route::apiResource('jadwal', JadwalController::class);
        Route::get('ujian/{ujian}/export-peserta-pdf', [UjianController::class, 'exportPesertaPdf'])
            ->whereNumber('ujian');
        Route::get('ujian/{ujian}/export-peserta-excel', [UjianController::class, 'exportPesertaExcel'])
            ->whereNumber('ujian');
        Route::apiResource('ujian', UjianController::class);

        Route::get('perkuliahan/template/download', [PerkuliahanController::class, 'downloadImportTemplate']);
        Route::post('perkuliahan/import', [PerkuliahanController::class, 'importSpreadsheet']);

        Route::get('jenis-kuliah', [JenisKuliahController::class, 'index']);

        Route::apiResource('ruangan', RuanganController::class);

        Route::apiResource('jenis-penilaian', JenisPenilaianController::class);

        // Modul keuangan: tagihan, pembayaran, struktur biaya, komponen, keringanan, dll.
        Route::middleware('role.admin.keuangan')->group(function (): void {
            Route::apiResource('komponen-biaya', KomponenBiayaController::class);
            Route::apiResource('jenis-keringanan-biaya', JenisKeringananBiayaController::class);
            Route::apiResource('keringanan-biaya', KeringananBiayaController::class);
            Route::apiResource('aturan-akses-keuangan', AturanAksesKeuanganController::class);
            Route::get('kategori-biaya/{kategori_biaya}/mahasiswa', [KategoriBiayaController::class, 'mahasiswa']);
            Route::apiResource('kategori-biaya', KategoriBiayaController::class);
            Route::get('kategori-biaya-mahasiswa/by-mahasiswa/{id}', [KategoriBiayaMahasiswaController::class, 'getByMahasiswa']);
            Route::apiResource('kategori-biaya-mahasiswa', KategoriBiayaMahasiswaController::class);

            Route::get('struktur-biaya/import/template', [StrukturBiayaController::class, 'downloadImportTemplate']);
            Route::post('struktur-biaya/import', [StrukturBiayaController::class, 'importSpreadsheet']);
            Route::apiResource('struktur-biaya', StrukturBiayaController::class);

            Route::get('tagihan/export', [TagihanController::class, 'exportExcel']);
            Route::apiResource('tagihan', TagihanController::class);
            Route::get('tagihan/generate/preview', [TagihanController::class, 'generatePreview']);
            Route::post('tagihan/generate', [TagihanController::class, 'generateFromStrukturBiaya']);
            Route::get('tagihan/template/download', [TagihanController::class, 'downloadTemplate']);
            Route::post('tagihan/import', [TagihanController::class, 'import']);

            Route::get('keuangan/dashboard-stats', [PembayaranController::class, 'dashboardStats']);

            Route::post('pembayaran/{pembayaran}/approve', [PembayaranController::class, 'approve']);
            Route::get('pembayaran/export', [PembayaranController::class, 'exportExcel']);
            Route::apiResource('pembayaran', PembayaranController::class);
            Route::get('pembayaran/tagihan/unpaid', [PembayaranController::class, 'getUnpaidTagihanByNim']);
            Route::get('pembayaran/tagihan/{id}/total', [PembayaranController::class, 'getTotalPembayaranByTagihan']);
            Route::get('pembayaran/template/download', [PembayaranController::class, 'downloadTemplate']);
            Route::post('pembayaran/import', [PembayaranController::class, 'import']);
        });

        Route::apiResource('status-akademik', StatusAkademikController::class);

        Route::apiResource('krs', KrsController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('krs/{id}/edit', [KrsController::class, 'edit']);
        Route::get('krs/template/download', [KrsController::class, 'downloadTemplate']);
        Route::post('krs/import', [KrsController::class, 'import']);
        Route::get('krs/options/mahasiswa', [KrsController::class, 'getMahasiswaOptions']);
        Route::get('krs/mahasiswa/{id}', [KrsController::class, 'getMahasiswaDetail']);
        Route::get('krs/mahasiswa/{id}/by-semester', [KrsController::class, 'getKrsBySemesterForMahasiswa']);
        Route::get('krs/options/kelas', [KrsController::class, 'getKelasOptions']);

        Route::get('konversi-nilai/ringkasan-mahasiswa', [KonversiNilaiController::class, 'ringkasanMahasiswa']);
        Route::get('konversi-nilai/mahasiswa/{mahasiswa}', [KonversiNilaiController::class, 'rincianMahasiswa']);
        Route::get('konversi-nilai/options/jenis-konversi', [KonversiNilaiController::class, 'optionsJenisKonversi']);
        Route::get('konversi-nilai/options/kurikulum-matkul/{kurikulum}', [KonversiNilaiController::class, 'optionsKurikulumMatkul']);
        Route::post('konversi-nilai/bulk', [KonversiNilaiController::class, 'storeBulk']);

        Route::get('nilai', [NilaiController::class, 'index']);
        Route::get('nilai/mahasiswa/{id}', [NilaiController::class, 'show']);
        Route::get('nilai/mahasiswa/{id}/export', [NilaiController::class, 'exportNilaiMahasiswa']);
        Route::get('nilai/mahasiswa/{id}/export-pdf', [NilaiController::class, 'exportNilaiMahasiswaPdf']);
        Route::get('nilai/mahasiswa/{id}/by-semester', [NilaiController::class, 'getNilaiBySemesterForMahasiswa']);
        Route::get('nilai/krs/{id}', [NilaiController::class, 'getByKrs']);
        Route::post('nilai', [NilaiController::class, 'store']);
        Route::delete('nilai/{id}', [NilaiController::class, 'destroy']);
        Route::put('nilai/{id}', [NilaiController::class, 'update']);
        Route::get('nilai/template/download', [NilaiController::class, 'downloadTemplate']);
        Route::post('nilai/import', [NilaiController::class, 'import']);

        Route::get('kehadiran/rekap/kelas/{id}/admin', [KehadiranController::class, 'getRekapByKelasAdmin']);
        Route::get('kehadiran/rekap/kelas/{id}/admin/export', [KehadiranController::class, 'exportRekapByKelasAdmin']);

        Route::get('nilai/kelas/{id}/admin', [NilaiController::class, 'getMahasiswaByKelasAdmin']);
        Route::post('nilai/kelas/{id}/kalkulasi-kehadiran', [NilaiController::class, 'kalkulasiNilaiKehadiran']);

        Route::get('permissions/all', [PermissionController::class, 'getAll']);
        Route::apiResource('permissions', PermissionController::class);

        Route::apiResource('users', UserController::class);
        Route::get('users/options/mahasiswa', [UserController::class, 'getAvailableMahasiswa']);
        Route::get('users/options/dosen', [UserController::class, 'getAvailableDosen']);
        Route::get('users/mahasiswa/{id}', [UserController::class, 'getMahasiswaDetail']);
        Route::get('users/dosen/{id}', [UserController::class, 'getDosenDetail']);
        Route::get('users/{user}/roles-scopes', [UserController::class, 'getRolesAndScopes']);
        Route::get('users/permissions', [UserController::class, 'getPermissions']);
        Route::get('users/{user}/permissions', [UserController::class, 'getUserPermissions']);

        // Endpoint pemberian role/scope/permission: berdampak privilese, khusus Superadmin.
        Route::middleware('role.superadmin')->group(function (): void {
            Route::apiResource('roles', RoleController::class);
            Route::post('users/{user}/roles-scopes', [UserController::class, 'storeRolesAndScopes']);
            Route::post('users/{user}/permissions', [UserController::class, 'storeUserPermissions']);
        });

        Route::get('jenis-keluar', [JenisKeluarController::class, 'index']);

        Route::apiResource('survey', SurveyController::class);
        Route::get('survey/{survey}/statistik', [SurveyController::class, 'getStatistik']);
        Route::get('survey/{survey}/statistik/export', [SurveyController::class, 'exportStatistik']);
        Route::get('survey-question', [SurveyQuestionController::class, 'index']);
        Route::post('survey-question', [SurveyQuestionController::class, 'store']);
        Route::get('survey-question/{surveyQuestion}', [SurveyQuestionController::class, 'show']);
        Route::put('survey-question/{surveyQuestion}', [SurveyQuestionController::class, 'update']);
        Route::delete('survey-question/{surveyQuestion}', [SurveyQuestionController::class, 'destroy']);

        Route::get('yudisium', [YudisiumController::class, 'index']);
        Route::post('yudisium', [YudisiumController::class, 'store']);
        Route::get('yudisium/export-pdf', [YudisiumController::class, 'exportPdf']);
        Route::get('yudisium/export-excel', [YudisiumController::class, 'exportExcel']);
        Route::get('yudisium/template/download', [YudisiumController::class, 'downloadTemplate']);
        Route::post('yudisium/import', [YudisiumController::class, 'import']);
        Route::get('yudisium/{yudisium}', [YudisiumController::class, 'show']);

        Route::get('tugas-akhir', [TugasAkhirController::class, 'index']);
        Route::get('tugas-akhir/template/download', [TugasAkhirController::class, 'downloadTemplate']);
        Route::post('tugas-akhir/import', [TugasAkhirController::class, 'import']);
        Route::patch('tugas-akhir/{tugasAkhir}/status', [TugasAkhirController::class, 'updateStatus']);
        Route::post('tugas-akhir/{tugasAkhir}/pembimbing', [TugasAkhirController::class, 'storePembimbing']);
        Route::put('tugas-akhir/{tugasAkhir}/pembimbing/{pembimbing}', [TugasAkhirController::class, 'updatePembimbing']);
        Route::delete('tugas-akhir/{tugasAkhir}/pembimbing/{pembimbing}', [TugasAkhirController::class, 'destroyPembimbing']);
        Route::post('tugas-akhir/{tugasAkhir}/ujian-sidang', [TugasAkhirController::class, 'storeUjianSidang']);
        Route::patch('tugas-akhir/{tugasAkhir}/ujian-sidang/{ujianSidang}', [TugasAkhirController::class, 'updateUjianSidang']);
        Route::post('tugas-akhir/{tugasAkhir}/ujian-sidang/{ujianSidang}/penguji', [TugasAkhirController::class, 'storePengujiSidang']);
        Route::put(
            'tugas-akhir/{tugasAkhir}/ujian-sidang/{ujianSidang}/penguji/{pengujiSidang}',
            [TugasAkhirController::class, 'updatePengujiSidang']
        );
        Route::delete(
            'tugas-akhir/{tugasAkhir}/ujian-sidang/{ujianSidang}/penguji/{pengujiSidang}',
            [TugasAkhirController::class, 'destroyPengujiSidang']
        );
        Route::get(
            'tugas-akhir/{tugasAkhir}/ujian-sidang/{ujianSidang}/preview-finalisasi-nilai',
            [TugasAkhirController::class, 'previewFinalisasiNilaiUjianSidang']
        );
        Route::post(
            'tugas-akhir/{tugasAkhir}/ujian-sidang/{ujianSidang}/finalisasi-nilai',
            [TugasAkhirController::class, 'finalisasiNilaiUjianSidang']
        );
        Route::get('tugas-akhir/{tugasAkhir}', [TugasAkhirController::class, 'show']);

        Route::post('negara', [NegaraController::class, 'store']);
        Route::get('negara/template/download', [NegaraController::class, 'downloadTemplate']);
        Route::post('negara/import', [NegaraController::class, 'import']);
        Route::get('negara/{negara}', [NegaraController::class, 'show']);
        Route::put('negara/{negara}', [NegaraController::class, 'update']);
        Route::delete('negara/{negara}', [NegaraController::class, 'destroy']);
        Route::post('provinsi', [ProvinsiController::class, 'store']);
        Route::get('provinsi/template/download', [ProvinsiController::class, 'downloadTemplate']);
        Route::post('provinsi/import', [ProvinsiController::class, 'import']);
        Route::get('provinsi/{provinsi}', [ProvinsiController::class, 'show']);
        Route::put('provinsi/{provinsi}', [ProvinsiController::class, 'update']);
        Route::delete('provinsi/{provinsi}', [ProvinsiController::class, 'destroy']);
        Route::post('kota', [KotaController::class, 'store']);
        Route::get('kota/template/download', [KotaController::class, 'downloadTemplate']);
        Route::post('kota/import', [KotaController::class, 'import']);
        Route::get('kota/{kota}', [KotaController::class, 'show']);
        Route::put('kota/{kota}', [KotaController::class, 'update']);
        Route::delete('kota/{kota}', [KotaController::class, 'destroy']);
        Route::post('kecamatan', [KecamatanController::class, 'store']);
        Route::get('kecamatan/template/download', [KecamatanController::class, 'downloadTemplate']);
        Route::post('kecamatan/import', [KecamatanController::class, 'import']);
        Route::get('kecamatan/{kecamatan}', [KecamatanController::class, 'show']);
        Route::put('kecamatan/{kecamatan}', [KecamatanController::class, 'update']);
        Route::delete('kecamatan/{kecamatan}', [KecamatanController::class, 'destroy']);

        Route::apiResource('pengumuman', PengumumanController::class);
    });

    /** API untuk aplikasi PMB */
    Route::prefix('pmb')->group(function (): void {
        Route::post('register', [PmbAuthController::class, 'register']);
        Route::post('login', [PmbAuthController::class, 'login']);
    });

});

// Routing untuk aplikasi PMB
require_once __DIR__.'/pmb.php';
