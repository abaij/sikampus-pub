<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use App\Models\Prodi;
use App\Models\JalurMasuk;
use App\Models\JenisDaftar;
use App\Models\Semester;
use App\Models\Negara;
use App\Models\Provinsi;
use App\Models\Kota;
use App\Models\Pendidikan;
use App\Models\Pekerjaan;
use App\Models\Penghasilan;
use App\Models\KelompokKelas;
use App\Models\StatusAkademik;
use App\Models\StatusMahasiswa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MahasiswaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);        
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi') ? (int) $request->get('id_prodi') : null;
        $statusAkademikId = $request->get('id_status_akademik') ? (int) $request->get('id_status_akademik') : null;
        $kelompokKelasId = $request->get('id_kelompok_kelas') ? (int) $request->get('id_kelompok_kelas') : null;
        $semesterMasukId = $request->get('id_semester_masuk') ? (int) $request->get('id_semester_masuk') : null;

        $query = Mahasiswa::with(['user', 'prodi', 'status_akademik', 'kelompok_kelas', 'semester_masuk']);

        // Hak akses scope: hanya tampilkan mahasiswa dari prodi yang sesuai scope user (fakultas dan/atau prodi)
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id_prodi', $allowedProdiIds);
                if ($prodiId && !in_array($prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('nim', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($prodiId) {
            $query->where('id_prodi', $prodiId);
        }

        if ($statusAkademikId) {
            $query->where('id_status_akademik', $statusAkademikId);
        }

        if ($semesterMasukId) {
            $query->where('id_semester_masuk', $semesterMasukId);
        }

        if ($kelompokKelasId) {
            $query->where('id_kelompok_kelas', $kelompokKelasId);
        }

        $query->orderBy('nama');

        $data = $query->paginate($perPage);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_user' => ['nullable', 'integer', 'exists:users,id', 'unique:mahasiswa,id_user'],
            'nama' => ['required', 'string', 'max:255'],
            'nim' => ['nullable', 'string', 'max:50', 'unique:mahasiswa,nim'],
            'email' => ['nullable', 'email', 'max:255', 'unique:mahasiswa,email'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'alamat' => ['nullable', 'string'],
            'kode_pos' => ['nullable', 'string', 'max:10'],
            'id_semester_masuk' => ['nullable', 'integer'],
            'id_prodi' => ['nullable', 'integer', 'exists:prodi,id'],
            'id_kelompok_kelas' => ['nullable', 'integer', 'exists:kelompok_kelas,id'],
            'id_kecamatan' => ['nullable', 'string'],
            'id_kota' => ['nullable', 'string'],
            'id_provinsi' => ['nullable', 'string'],
            'id_negara' => ['nullable', 'string'],
            'foto' => ['nullable', 'string', 'max:255'],
            'id_status_akademik' => ['nullable', 'integer', 'exists:status_akademik,id'],
            'jenis_kelamin' => ['nullable', 'string', Rule::in(['L', 'P'])],
            'id_tempat_lahir' => ['nullable', 'string'],
            'tanggal_lahir' => ['nullable', 'date'],
            'no_ktp' => ['nullable', 'string', 'max:20'],
            'sekolah_asal' => ['nullable', 'string', 'max:255'],
            'nis' => ['nullable', 'string', 'max:50'],
            'nisn' => ['nullable', 'string', 'max:50'],
            'npwp' => ['nullable', 'string', 'max:20'],
            'mulai_semester' => ['nullable', 'string', 'max:50'],
            'rt' => ['nullable', 'string', 'max:10'],
            'rw' => ['nullable', 'string', 'max:10'],
            'dusun' => ['nullable', 'string', 'max:100'],
            'kelurahan' => ['nullable', 'string', 'max:100'],
            'handphone' => ['nullable', 'string', 'max:20'],
            'penerima_kps' => ['nullable', 'string', 'max:10'],
            'no_kps' => ['nullable', 'string', 'max:50'],
            'nik_ayah' => ['nullable', 'string', 'max:20'],
            'tgl_lahir_ayah' => ['nullable', 'date'],
            'id_pddk_ayah' => ['nullable', 'integer'],
            'id_pekerjaan_ayah' => ['nullable', 'integer'],
            'id_penghasilan_ayah' => ['nullable', 'integer'],
            'nik_ibu' => ['nullable', 'string', 'max:20'],
            'tgl_lahir_ibu' => ['nullable', 'date'],
            'id_pddk_ibu' => ['nullable', 'integer'],
            'id_pekerjaan_ibu' => ['nullable', 'integer'],
            'id_penghasilan_ibu' => ['nullable', 'integer'],
            'wali' => ['nullable', 'string', 'max:255'],
            'nik_wali' => ['nullable', 'string', 'max:20'],
            'tgl_lahir_wali' => ['nullable', 'date'],
            'id_pddk_wali' => ['nullable', 'integer'],
            'id_pekerjaan_wali' => ['nullable', 'integer'],
            'id_penghasilan_wali' => ['nullable', 'integer'],
            'jml_biaya_masuk' => ['nullable', 'numeric', 'min:0'],
            'sks_diakui' => ['nullable', 'integer', 'min:0'],
            'id_jalur_masuk' => ['nullable', 'integer'],
            'id_jenis_daftar' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction() && isset($validated['id_prodi'])) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $validated['id_prodi'], $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke program studi ini.');
            }
        }

        $mahasiswa = Mahasiswa::create($validated);

        return response()->json($mahasiswa->load(['user', 'prodi']), 201);
    }

    public function show(Request $request, Mahasiswa $mahasiswa): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data mahasiswa ini.');
            }
        }

        return response()->json($mahasiswa->load([
            'user',
            'prodi',
            'status_akademik',
            'kelompok_kelas',
            'jalur_masuk',
            'jenis_daftar',
            'negara',
            'provinsi',
            'kota',
            'semester_masuk',
            'kategori_biaya_mahasiswa' => function ($q) {
                $q->where('status', 'active')->with('kategori_biaya');
            },
            'pendidikan_ayah',
            'pekerjaan_ayah',
            'penghasilan_ayah',
            'pendidikan_ibu',
            'pekerjaan_ibu',
            'penghasilan_ibu',
            'pendidikan_wali',
            'pekerjaan_wali',
            'penghasilan_wali',
        ]));
    }

    /**
     * Detail mahasiswa berdasarkan NIM (untuk API partner, mis. Siska).
     */
    public function showByNim(string $nim): JsonResponse
    {
        $mahasiswa = Mahasiswa::with([
            'user', 'prodi', 'status_akademik', 'kelompok_kelas', 'jalur_masuk', 'jenis_daftar',
            'negara', 'provinsi', 'kota', 'semester_masuk',
            'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah',
            'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu',
            'pendidikan_wali', 'pekerjaan_wali', 'penghasilan_wali',
        ])->where('nim', $nim)->first();

        if (! $mahasiswa) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        return response()->json($mahasiswa);
    }

    /**
     * Detail mahasiswa untuk admin prodi (scope fakultas/prodi) - termasuk dosen wali aktif.
     */
    public function showProdi(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $allowedProdiIds = $user && $user->hasScopeRestriction() ? $user->getAllowedProdiIds() : null;
        if ($user && $user->hasScopeRestriction() && (empty($allowedProdiIds))) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $mahasiswa = Mahasiswa::with([
            'user', 'prodi', 'status_akademik', 'kelompok_kelas', 'jalur_masuk', 'jenis_daftar',
            'negara', 'provinsi', 'kota', 'semester_masuk',
            'pendidikan_ayah', 'pekerjaan_ayah', 'penghasilan_ayah',
            'pendidikan_ibu', 'pekerjaan_ibu', 'penghasilan_ibu',
            'pendidikan_wali', 'pekerjaan_wali', 'penghasilan_wali',
        ])->find($id);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }
        if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke data mahasiswa ini.'], 403);
        }

        $dosenWali = DB::table('dosen_wali')
            ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
            ->where('dosen_wali.id_mahasiswa', $mahasiswa->id)
            ->where('dosen_wali.status', 'active')
            ->whereNull('dosen_wali.deleted_at')
            ->select('dosen.id as id_dosen', 'dosen.nama as nama', 'dosen.nidn as nidn')
            ->first();

        $dosenWaliData = $dosenWali ? [
            'id' => $dosenWali->id_dosen,
            'nama' => $dosenWali->nama,
            'nidn' => $dosenWali->nidn ?? null,
        ] : null;

        return response()->json([
            ...$mahasiswa->toArray(),
            'dosen_wali' => $dosenWaliData,
        ]);
    }

    public function getStatusAkademikBySemester(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $allowedProdiIds = $user && $user->hasScopeRestriction() ? $user->getAllowedProdiIds() : null;
        if ($user && $user->hasScopeRestriction() && (empty($allowedProdiIds))) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $mahasiswa = Mahasiswa::with(['prodi', 'semester_masuk'])->find($id);
        if (!$mahasiswa) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }
        if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke data mahasiswa ini.'], 403);
        }

        $riwayat = StatusMahasiswa::query()
            ->with(['semester:id,kode,nama', 'status_akademik:id,nama'])
            ->where('id_mahasiswa', $mahasiswa->id)
            ->orderBy(
                Semester::select('kode')
                    ->whereColumn('semester.id', 'status_mahasiswa.id_semester')
            )
            ->get()
            ->map(function (StatusMahasiswa $item): array {
                return [
                    'id' => $item->id,
                    'semester' => $item->semester ? [
                        'id' => $item->semester->id,
                        'kode' => $item->semester->kode,
                        'nama' => $item->semester->nama,
                    ] : null,
                    'status_akademik' => $item->status_akademik ? [
                        'id' => $item->status_akademik->id,
                        'nama' => $item->status_akademik->nama,
                    ] : null,
                    'keterangan' => $item->keterangan,
                ];
            })
            ->values();

        return response()->json([
            'mahasiswa' => [
                'id' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                    'kode' => $mahasiswa->prodi->kode,
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'kode' => $mahasiswa->semester_masuk->kode,
                    'nama' => $mahasiswa->semester_masuk->nama,
                ] : null,
            ],
            'data' => $riwayat,
        ]);
    }

    public function update(Request $request, Mahasiswa $mahasiswa): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data mahasiswa ini.');
            }
        }

        $validated = $request->validate([
            'id_user' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('mahasiswa', 'id_user')->ignore($mahasiswa->id),
            ],
            'nama' => ['sometimes', 'required', 'string', 'max:255'],
            'nim' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('mahasiswa', 'nim')->ignore($mahasiswa->id),
            ],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('mahasiswa', 'email')->ignore($mahasiswa->id),
            ],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'alamat' => ['nullable', 'string'],
            'kode_pos' => ['nullable', 'string', 'max:10'],
            'id_semester_masuk' => ['nullable', 'integer'],
            'id_prodi' => ['nullable', 'integer', 'exists:prodi,id'],
            'id_kelompok_kelas' => ['nullable', 'integer', 'exists:kelompok_kelas,id'],
            'id_kecamatan' => ['nullable', 'string'],
            'id_kota' => ['nullable', 'string'],
            'id_provinsi' => ['nullable', 'string'],
            'id_negara' => ['nullable', 'string'],
            'foto' => ['nullable', 'string', 'max:255'],
            'id_status_akademik' => ['nullable', 'integer', 'exists:status_akademik,id'],
            'jenis_kelamin' => ['nullable', 'string', Rule::in(['L', 'P'])],
            'id_tempat_lahir' => ['nullable', 'string'],
            'tanggal_lahir' => ['nullable', 'date'],
            'no_ktp' => ['nullable', 'string', 'max:20'],
            'sekolah_asal' => ['nullable', 'string', 'max:255'],
            'nis' => ['nullable', 'string', 'max:50'],
            'nisn' => ['nullable', 'string', 'max:50'],
            'npwp' => ['nullable', 'string', 'max:20'],
            'mulai_semester' => ['nullable', 'string', 'max:50'],
            'rt' => ['nullable', 'string', 'max:10'],
            'rw' => ['nullable', 'string', 'max:10'],
            'dusun' => ['nullable', 'string', 'max:100'],
            'kelurahan' => ['nullable', 'string', 'max:100'],
            'handphone' => ['nullable', 'string', 'max:20'],
            'penerima_kps' => ['nullable', 'string', 'max:10'],
            'no_kps' => ['nullable', 'string', 'max:50'],
            'nik_ayah' => ['nullable', 'string', 'max:20'],
            'tgl_lahir_ayah' => ['nullable', 'date'],
            'id_pddk_ayah' => ['nullable', 'integer'],
            'id_pekerjaan_ayah' => ['nullable', 'integer'],
            'id_penghasilan_ayah' => ['nullable', 'integer'],
            'nik_ibu' => ['nullable', 'string', 'max:20'],
            'tgl_lahir_ibu' => ['nullable', 'date'],
            'id_pddk_ibu' => ['nullable', 'integer'],
            'id_pekerjaan_ibu' => ['nullable', 'integer'],
            'id_penghasilan_ibu' => ['nullable', 'integer'],
            'wali' => ['nullable', 'string', 'max:255'],
            'nik_wali' => ['nullable', 'string', 'max:20'],
            'tgl_lahir_wali' => ['nullable', 'date'],
            'id_pddk_wali' => ['nullable', 'integer'],
            'id_pekerjaan_wali' => ['nullable', 'integer'],
            'id_penghasilan_wali' => ['nullable', 'integer'],
            'jml_biaya_masuk' => ['nullable', 'numeric', 'min:0'],
            'sks_diakui' => ['nullable', 'integer', 'min:0'],
            'id_jalur_masuk' => ['nullable', 'integer'],
            'id_jenis_daftar' => ['nullable', 'integer'],
        ]);

        if ($user && $user->hasScopeRestriction() && array_key_exists('id_prodi', $validated)) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $validated['id_prodi'], $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke program studi ini.');
            }
        }

        $mahasiswa->update($validated);

        return response()->json($mahasiswa->load(['user', 'prodi']));
    }

    public function destroy(Request $request, Mahasiswa $mahasiswa): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data mahasiswa ini.');
            }
        }

        $mahasiswa->delete();

        return response()->json(['message' => 'Mahasiswa dihapus']);
    }

    /**
     * Get profile mahasiswa yang sedang login
     */
    public function getMyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $mahasiswa = Mahasiswa::with([
            'user',
            'prodi',
            'status_akademik',
            'kelompok_kelas',
            'jalur_masuk',
            'jenis_daftar',
            'negara',
            'provinsi',
            'kota',
            'semester_masuk',
            'pendidikan_ayah',
            'pekerjaan_ayah',
            'penghasilan_ayah',
            'pendidikan_ibu',
            'pekerjaan_ibu',
            'penghasilan_ibu',
            'pendidikan_wali',
            'pekerjaan_wali',
            'penghasilan_wali'
        ])
            ->where('id_user', $user->id)
            ->first();

        if (!$mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan'
            ], 404);
        }

        $baseUrl = config('app.url');
        $fotoUrl = $mahasiswa->foto ? $baseUrl . '/storage/' . $mahasiswa->foto : null;
        
        $mahasiswaData = $mahasiswa->toArray();
        $mahasiswaData['foto_url'] = $fotoUrl;

        return response()->json($mahasiswaData);
    }

    /**
     * Update profile mahasiswa yang sedang login
     */
    public function updateMyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('mahasiswa', 'email')->ignore($mahasiswa->id),
            ],
            'no_wa' => ['nullable', 'string', 'max:20'],
            'handphone' => ['nullable', 'string', 'max:20'],
            'alamat' => ['nullable', 'string'],
            'kode_pos' => ['nullable', 'string', 'max:10'],
            'id_kecamatan' => ['nullable', 'string'],
            'id_kota' => ['nullable', 'string'],
            'id_provinsi' => ['nullable', 'string'],
            'id_negara' => ['nullable', 'string'],
            'jenis_kelamin' => ['nullable', 'string', Rule::in(['L', 'P'])],
            'id_tempat_lahir' => ['nullable', 'string'],
            'tanggal_lahir' => ['nullable', 'date'],
            'no_ktp' => ['nullable', 'string', 'max:20'],
            'rt' => ['nullable', 'string', 'max:10'],
            'rw' => ['nullable', 'string', 'max:10'],
            'dusun' => ['nullable', 'string', 'max:100'],
            'kelurahan' => ['nullable', 'string', 'max:100'],
        ]);

        $mahasiswa->update($validated);
        $mahasiswa->load([
            'user',
            'prodi',
            'status_akademik',
            'kelompok_kelas',
            'negara',
            'provinsi',
            'kota',
        ]);

        return response()->json($mahasiswa);
    }

    /**
     * Update password mahasiswa yang sedang login
     */
    public function updateMyPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'password_lama' => ['required', 'string'],
            'password_baru' => ['required', 'string', 'min:8'],
            'password_baru_confirmation' => ['required', 'string', 'same:password_baru'],
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($validated['password_lama'], $user->password)) {
            return response()->json([
                'message' => 'Password lama tidak sesuai'
            ], 422);
        }

        $user->password = \Illuminate\Support\Facades\Hash::make($validated['password_baru']);
        $user->save();

        return response()->json([
            'message' => 'Password berhasil diubah'
        ]);
    }

    /**
     * Upload foto mahasiswa yang sedang login
     */
    public function uploadMyFoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (!$mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan'
            ], 404);
        }

        $validated = $request->validate([
            'foto' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:5120'], // 5 MB = 5120 KB
        ]);

        // Hapus foto lama jika ada
        if ($mahasiswa->foto) {
            Storage::disk('public')->delete($mahasiswa->foto);
        }

        // Simpan foto baru
        $foto = $request->file('foto');
        $filename = 'mahasiswa_' . $mahasiswa->id . '_' . time() . '.' . $foto->getClientOriginalExtension();
        $path = $foto->storeAs('mahasiswa/foto', $filename, 'public');

        $mahasiswa->foto = $path;
        $mahasiswa->save();

        $baseUrl = config('app.url');
        $fotoUrl = $baseUrl . '/storage/' . $path;

        return response()->json([
            'message' => 'Foto berhasil diupload',
            'foto' => $path,
            'foto_url' => $fotoUrl,
        ]);
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set header
        $headers = [
            'Nama*',
            'NIM',
            'Email',
            'No. HP',
            'Handphone',
            'Jenis Kelamin (L/P)',
            'ID Tempat Lahir (string kode kota/wilayah; sesuai kolom di form mahasiswa)',
            'Tanggal Lahir (YYYY-MM-DD atau tanggal Excel)',
            'No. KTP',
            'Status Akademik (Nama)',
            'Alamat',
            'RT',
            'RW',
            'Dusun',
            'Kelurahan',
            'Kode Pos',
            'ID Kecamatan (string kode wilayah; bukan nama teks)',
            'Negara (Nama Negara)',
            'Provinsi (Nama Provinsi)',
            'Kota (Nama Kota)',
            'Kode Prodi*',
            'Kelas Mahasiswa (Nama)',
            'Kode Semester Masuk',
            'Jalur Masuk (Nama Jalur)',
            'Jenis Daftar (Nama Jenis)',
            'Mulai Semester',
            'SKS Diakui',
            'Sekolah Asal',
            'NIS',
            'NISN',
            'NPWP',
            'Nama Ayah',
            'NIK Ayah',
            'Tanggal Lahir Ayah (YYYY-MM-DD)',
            'Pendidikan Ayah (Nama)',
            'Pekerjaan Ayah (Nama)',
            'Penghasilan Ayah (Nama)',
            'Nama Ibu',
            'NIK Ibu',
            'Tanggal Lahir Ibu (YYYY-MM-DD)',
            'Pendidikan Ibu (Nama)',
            'Pekerjaan Ibu (Nama)',
            'Penghasilan Ibu (Nama)',
            'Nama Wali',
            'NIK Wali',
            'Tanggal Lahir Wali (YYYY-MM-DD)',
            'Pendidikan Wali (Nama)',
            'Pekerjaan Wali (Nama)',
            'Penghasilan Wali (Nama)',
            'Jumlah Biaya Masuk',
            'Penerima KPS',
            'No. KPS',
            'Foto (path relatif di storage disk public; file harus sudah diunggah ke server, contoh: mahasiswa/foto/nama_file.jpg)',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Set column widths
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF', 'AG', 'AH', 'AI', 'AJ', 'AK', 'AL', 'AM', 'AN', 'AO', 'AP', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AV', 'AW', 'AX', 'AY', 'AZ', 'BA'];
        $widths = [25, 15, 25, 15, 15, 20, 20, 20, 20, 20, 30, 10, 10, 15, 20, 12, 20, 20, 20, 20, 15, 20, 25, 25, 25, 20, 12, 25, 15, 15, 20, 20, 20, 15, 20, 15, 20, 20, 20, 15, 20, 15, 20, 20, 20, 15, 20, 15, 15, 15, 20, 20, 35];
        
        foreach ($columns as $index => $col) {
            if (isset($widths[$index])) {
                $sheet->getColumnDimension($col)->setWidth($widths[$index]);
            }
        }

        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $headerCount = count($headers);
        $lastColumn = $columns[$headerCount - 1] ?? 'AZ';
        $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

        // Add example row
        $exampleRow = [
            'John Doe',
            '2024001',
            'john.doe@example.com',
            '081234567890',
            '081234567890',
            'L',
            '31.71',
            '2000-01-15',
            '1234567890123456',
            'Aktif',
            'Jl. Contoh No. 123',
            '001',
            '002',
            'Dusun A',
            'Kelurahan B',
            '12345',
            '3171010001',
            'Indonesia',
            'DKI Jakarta',
            'Jakarta Pusat',
            'TI',
            'GRP-A',
            '20241',
            'SNMPTN',
            'Baru',
            '2024/2025 Ganjil',
            '0',
            'SMA Negeri 1',
            '12345',
            '1234567890',
            '12.345.678.9-000.000',
            'Ayah Contoh',
            '1234567890123456',
            '1970-01-01',
            'S1',
            'PNS',
            'Rp 5.000.000 - Rp 10.000.000',
            'Ibu Contoh',
            '1234567890123456',
            '1970-01-01',
            'S1',
            'PNS',
            'Rp 5.000.000 - Rp 10.000.000',
            'Jane Doe',
            '1234567890123456',
            '1970-01-01',
            'S1',
            'PNS',
            'Rp 5.000.000 - Rp 10.000.000',
            '5000000',
            'Ya',
            '1234567890',
            '', // Foto: opsional, isi hanya jika file sudah diunggah ke storage server
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_mahasiswa_' . date('YmdHis') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Tanggal dari Excel sering berupa serial angka atau DateTime — normalisasi ke Y-m-d untuk kolom date DB.
     */
    private static function normalizeImportDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n > 200 && $n < 120_000) {
                try {
                    return ExcelDate::excelToDateTimeObject($n)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }
        if (is_string($value)) {
            $t = trim($value);
            if ($t === '') {
                return null;
            }
            try {
                return Carbon::parse($t)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Desimal / uang dari Excel atau teks (mis. pemisah ribuan).
     */
    private static function normalizeImportDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/^Rp\s*/iu', '', $s);
        $s = str_replace([' ', "\u{00A0}"], '', $s);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',') && ! str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        }
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'File Excel kosong atau tidak valid.',
            ], 400);
        }

        // Remove header row
        array_shift($rows);

        $errors = [];
        $successCount = 0;
        $skipCount = 0;
        $processedRows = [];
        /** NIM => id mahasiswa (untuk duplikat NIM dalam file: update record yang sudah dibuat) */
        $processedNimIds = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2; // +2 karena header di row 1 dan array 0-indexed

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                $data = [
                    'nama' => $row[0] ?? null,
                    'nim' => $row[1] ?? null,
                    'email' => $row[2] ?? null,
                    'no_hp' => $row[3] ?? null,
                    'handphone' => $row[4] ?? null,
                    'jenis_kelamin' => $row[5] ?? null,
                    'id_tempat_lahir' => $row[6] ?? null,
                    'tanggal_lahir' => $row[7] ?? null,
                    'no_ktp' => $row[8] ?? null,
                    'status_akademik_nama' => $row[9] ?? null,
                    'alamat' => $row[10] ?? null,
                    'rt' => $row[11] ?? null,
                    'rw' => $row[12] ?? null,
                    'dusun' => $row[13] ?? null,
                    'kelurahan' => $row[14] ?? null,
                    'kode_pos' => $row[15] ?? null,
                    'id_kecamatan' => $row[16] ?? null,
                    'negara_nama' => $row[17] ?? null,
                    'provinsi_nama' => $row[18] ?? null,
                    'kota_nama' => $row[19] ?? null,
                    'prodi_kode' => $row[20] ?? null,
                    'kelompok_kelas_nama' => $row[21] ?? null,
                    'semester_masuk_kode' => $row[22] ?? null,
                    'jalur_masuk_nama' => $row[23] ?? null,
                    'jenis_daftar_nama' => $row[24] ?? null,
                    'mulai_semester' => $row[25] ?? null,
                    'sks_diakui' => $row[26] ?? null,
                    'sekolah_asal' => $row[27] ?? null,
                    'nis' => $row[28] ?? null,
                    'nisn' => $row[29] ?? null,
                    'npwp' => $row[30] ?? null,
                    'ayah' => $row[31] ?? null,
                    'nik_ayah' => $row[32] ?? null,
                    'tgl_lahir_ayah' => $row[33] ?? null,
                    'pddk_ayah_nama' => $row[34] ?? null,
                    'pekerjaan_ayah_nama' => $row[35] ?? null,
                    'penghasilan_ayah_nama' => $row[36] ?? null,
                    'ibu' => $row[37] ?? null,
                    'nik_ibu' => $row[38] ?? null,
                    'tgl_lahir_ibu' => $row[39] ?? null,
                    'pddk_ibu_nama' => $row[40] ?? null,
                    'pekerjaan_ibu_nama' => $row[41] ?? null,
                    'penghasilan_ibu_nama' => $row[42] ?? null,
                    'wali' => $row[43] ?? null,
                    'nik_wali' => $row[44] ?? null,
                    'tgl_lahir_wali' => $row[45] ?? null,
                    'pddk_wali_nama' => $row[46] ?? null,
                    'pekerjaan_wali_nama' => $row[47] ?? null,
                    'penghasilan_wali_nama' => $row[48] ?? null,
                    'jml_biaya_masuk' => $row[49] ?? null,
                    'penerima_kps' => $row[50] ?? null,
                    'no_kps' => $row[51] ?? null,
                    'foto_path' => $row[52] ?? null,
                ];

                // Satu-satunya field wajib (NOT NULL): nama. Skip hanya jika nama kosong.
                if (empty(trim($data['nama'] ?? ''))) {
                    $errors[] = "Baris {$rowNumber}: Nama wajib diisi.";
                    $skipCount++;
                    continue;
                }

                // NIM: jika duplikat (di DB atau dalam file), lakukan UPDATE record yang ada, bukan null/skip
                $nimValue = !empty($data['nim']) ? trim($data['nim']) : null;
                $mahasiswaToUpdate = null;
                if ($nimValue !== null) {
                    $existingByNim = Mahasiswa::where('nim', $nimValue)->first();
                    if ($existingByNim) {
                        $mahasiswaToUpdate = $existingByNim;
                        $errors[] = "Baris {$rowNumber}: NIM '{$nimValue}' sudah ada di sistem, data diperbarui.";
                    } elseif (isset($processedNimIds[$nimValue])) {
                        $mahasiswaToUpdate = Mahasiswa::find($processedNimIds[$nimValue]);
                        if ($mahasiswaToUpdate) {
                            $errors[] = "Baris {$rowNumber}: NIM '{$nimValue}' duplikat dalam file, data diperbarui.";
                        }
                    }
                }

                // Email: jika duplikat (di DB atau dalam file), set null dan catat peringatan — kecuali untuk record yang sedang di-update (by NIM)
                $emailValue = !empty($data['email']) ? trim($data['email']) : null;
                if ($emailValue !== null) {
                    $emailExistsQuery = Mahasiswa::where('email', $emailValue);
                    if ($mahasiswaToUpdate !== null) {
                        $emailExistsQuery->where('id', '!=', $mahasiswaToUpdate->id);
                    }
                    if ($emailExistsQuery->exists()) {
                        $errors[] = "Baris {$rowNumber}: Email '{$emailValue}' sudah ada di sistem, disimpan dengan email kosong.";
                        $emailValue = null;
                    } else {
                        foreach ($processedRows as $processed) {
                            $sameEmail = isset($processed['email']) && $processed['email'] !== null && $emailValue === $processed['email'];
                            $isOtherRecord = !$mahasiswaToUpdate || (isset($processed['id']) && $processed['id'] != $mahasiswaToUpdate->id);
                            if ($sameEmail && $isOtherRecord) {
                                $errors[] = "Baris {$rowNumber}: Email '{$emailValue}' duplikat dalam file, disimpan dengan email kosong.";
                                $emailValue = null;
                                break;
                            }
                        }
                    }
                }

                // Find Prodi by kode — jika tidak ditemukan: null, catat peringatan
                $id_prodi = null;
                if (!empty($data['prodi_kode'])) {
                    $prodi_kode = trim($data['prodi_kode']);
                    $prodi = Prodi::where('kode', $prodi_kode)->first();
                    if (!$prodi) {
                        $errors[] = "Baris {$rowNumber}: Kode Prodi '{$prodi_kode}' tidak ditemukan, disimpan dengan Prodi kosong.";
                    } else {
                        $id_prodi = $prodi->id;
                    }
                }

                // Find kelas mahasiswa by nama — jika tidak ditemukan: null, catat peringatan
                $id_kelompok_kelas = null;
                if (!empty($data['kelompok_kelas_nama'])) {
                    $kelompok_kelas_nama = trim($data['kelompok_kelas_nama']);
                    $kelompok_kelas = KelompokKelas::where('nama', $kelompok_kelas_nama)->first();
                    if (!$kelompok_kelas) {
                        $errors[] = "Baris {$rowNumber}: Kelas mahasiswa '{$kelompok_kelas_nama}' tidak ditemukan, disimpan tanpa kelas mahasiswa.";
                    } else {
                        $id_kelompok_kelas = $kelompok_kelas->id;
                    }
                }

                // Find Semester Masuk by kode — jika tidak ditemukan: null, catat peringatan
                $id_semester_masuk = null;
                if (!empty($data['semester_masuk_kode'])) {
                    $semester_kode = trim($data['semester_masuk_kode']);
                    $semester = Semester::where('kode', $semester_kode)->first();
                    if (!$semester) {
                        $errors[] = "Baris {$rowNumber}: Kode Semester Masuk '{$semester_kode}' tidak ditemukan, disimpan dengan Semester Masuk kosong.";
                    } else {
                        $id_semester_masuk = $semester->id;
                    }
                }

                // Find Jalur Masuk by nama — jika tidak ditemukan: null, catat peringatan
                $id_jalur_masuk = null;
                if (!empty($data['jalur_masuk_nama'])) {
                    $jalurMasuk = JalurMasuk::where('nama', 'like', '%' . trim($data['jalur_masuk_nama']) . '%')->first();
                    if (!$jalurMasuk) {
                        $errors[] = "Baris {$rowNumber}: Jalur Masuk '{$data['jalur_masuk_nama']}' tidak ditemukan, disimpan dengan Jalur Masuk kosong.";
                    } else {
                        $id_jalur_masuk = $jalurMasuk->id;
                    }
                }

                // Find Jenis Daftar by nama — jika tidak ditemukan: null, catat peringatan
                $id_jenis_daftar = null;
                if (!empty($data['jenis_daftar_nama'])) {
                    $jenisDaftar = JenisDaftar::where('nama', 'like', '%' . trim($data['jenis_daftar_nama']) . '%')->first();
                    if (!$jenisDaftar) {
                        $errors[] = "Baris {$rowNumber}: Jenis Daftar '{$data['jenis_daftar_nama']}' tidak ditemukan, disimpan dengan Jenis Daftar kosong.";
                    } else {
                        $id_jenis_daftar = $jenisDaftar->id;
                    }
                }

                // Find Negara by nama — jika tidak ditemukan: null, catat peringatan
                $id_negara = null;
                if (!empty($data['negara_nama'])) {
                    $negara = Negara::where('nama', 'like', '%' . trim($data['negara_nama']) . '%')->first();
                    if (!$negara) {
                        $errors[] = "Baris {$rowNumber}: Negara '{$data['negara_nama']}' tidak ditemukan, disimpan dengan Negara kosong.";
                    } else {
                        $id_negara = $negara->id;
                    }
                }

                // Find Provinsi by nama — jika tidak ditemukan: null, catat peringatan
                $id_provinsi = null;
                if (!empty($data['provinsi_nama'])) {
                    $provinsiQuery = Provinsi::where('nama', 'like', '%' . trim($data['provinsi_nama']) . '%');
                    if ($id_negara) {
                        $provinsiQuery->where('id_negara', $id_negara);
                    }
                    $provinsi = $provinsiQuery->first();
                    if (!$provinsi) {
                        $errors[] = "Baris {$rowNumber}: Provinsi '{$data['provinsi_nama']}' tidak ditemukan, disimpan dengan Provinsi kosong.";
                    } else {
                        $id_provinsi = $provinsi->id;
                    }
                }

                // Find Kota by nama — jika tidak ditemukan: null, catat peringatan
                $id_kota = null;
                if (!empty($data['kota_nama'])) {
                    $kotaQuery = Kota::where('nama', 'like', '%' . trim($data['kota_nama']) . '%');
                    if ($id_provinsi) {
                        $kotaQuery->where('id_provinsi', $id_provinsi);
                    }
                    $kota = $kotaQuery->first();
                    if (!$kota) {
                        $errors[] = "Baris {$rowNumber}: Kota '{$data['kota_nama']}' tidak ditemukan, disimpan dengan Kota kosong.";
                    } else {
                        $id_kota = $kota->id;
                    }
                }

                // Jenis kelamin: jika tidak valid (bukan L/P), set null dan catat peringatan
                $jenis_kelamin = null;
                if (!empty($data['jenis_kelamin'])) {
                    $jk = strtoupper(trim($data['jenis_kelamin']));
                    if (!in_array($jk, ['L', 'P'])) {
                        $errors[] = "Baris {$rowNumber}: Jenis Kelamin '{$data['jenis_kelamin']}' tidak valid (harus L/P), disimpan kosong.";
                    } else {
                        $jenis_kelamin = $jk;
                    }
                }

                // Find Status Akademik by nama — jika tidak ditemukan: null, catat peringatan
                $id_status_akademik = null;
                if (!empty($data['status_akademik_nama'])) {
                    $status_nama = trim($data['status_akademik_nama']);
                    $statusAkademik = StatusAkademik::whereRaw('LOWER(nama) = ?', [strtolower($status_nama)])->first();
                    if (!$statusAkademik) {
                        $statusAkademik = StatusAkademik::whereRaw('LOWER(nama) LIKE ?', ['%' . strtolower($status_nama) . '%'])->first();
                    }
                    if (!$statusAkademik) {
                        $errors[] = "Baris {$rowNumber}: Status Akademik '{$status_nama}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_status_akademik = $statusAkademik->id;
                    }
                }

                // Find Pendidikan Ayah by nama — jika tidak ditemukan: null, catat peringatan
                $id_pddk_ayah = null;
                if (!empty($data['pddk_ayah_nama'])) {
                    $pendidikan = Pendidikan::where('nama', 'like', '%' . trim($data['pddk_ayah_nama']) . '%')->first();
                    if (!$pendidikan) {
                        $errors[] = "Baris {$rowNumber}: Pendidikan Ayah '{$data['pddk_ayah_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pddk_ayah = $pendidikan->id;
                    }
                }

                // Find Pekerjaan Ayah by nama — jika tidak ditemukan: null, catat peringatan
                $id_pekerjaan_ayah = null;
                if (!empty($data['pekerjaan_ayah_nama'])) {
                    $pekerjaan = Pekerjaan::where('nama', 'like', '%' . trim($data['pekerjaan_ayah_nama']) . '%')->first();
                    if (!$pekerjaan) {
                        $errors[] = "Baris {$rowNumber}: Pekerjaan Ayah '{$data['pekerjaan_ayah_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pekerjaan_ayah = $pekerjaan->id;
                    }
                }

                // Find Penghasilan Ayah by nama — jika tidak ditemukan: null, catat peringatan
                $id_penghasilan_ayah = null;
                if (!empty($data['penghasilan_ayah_nama'])) {
                    $penghasilan = Penghasilan::where('nama', 'like', '%' . trim($data['penghasilan_ayah_nama']) . '%')->first();
                    if (!$penghasilan) {
                        $errors[] = "Baris {$rowNumber}: Penghasilan Ayah '{$data['penghasilan_ayah_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_penghasilan_ayah = $penghasilan->id;
                    }
                }

                // Find Pendidikan Ibu by nama — jika tidak ditemukan: null, catat peringatan
                $id_pddk_ibu = null;
                if (!empty($data['pddk_ibu_nama'])) {
                    $pendidikan = Pendidikan::where('nama', 'like', '%' . trim($data['pddk_ibu_nama']) . '%')->first();
                    if (!$pendidikan) {
                        $errors[] = "Baris {$rowNumber}: Pendidikan Ibu '{$data['pddk_ibu_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pddk_ibu = $pendidikan->id;
                    }
                }

                // Find Pekerjaan Ibu by nama — jika tidak ditemukan: null, catat peringatan
                $id_pekerjaan_ibu = null;
                if (!empty($data['pekerjaan_ibu_nama'])) {
                    $pekerjaan = Pekerjaan::where('nama', 'like', '%' . trim($data['pekerjaan_ibu_nama']) . '%')->first();
                    if (!$pekerjaan) {
                        $errors[] = "Baris {$rowNumber}: Pekerjaan Ibu '{$data['pekerjaan_ibu_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pekerjaan_ibu = $pekerjaan->id;
                    }
                }

                // Find Penghasilan Ibu by nama — jika tidak ditemukan: null, catat peringatan
                $id_penghasilan_ibu = null;
                if (!empty($data['penghasilan_ibu_nama'])) {
                    $penghasilan = Penghasilan::where('nama', 'like', '%' . trim($data['penghasilan_ibu_nama']) . '%')->first();
                    if (!$penghasilan) {
                        $errors[] = "Baris {$rowNumber}: Penghasilan Ibu '{$data['penghasilan_ibu_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_penghasilan_ibu = $penghasilan->id;
                    }
                }

                // Find Pendidikan Wali by nama — jika tidak ditemukan: null, catat peringatan
                $id_pddk_wali = null;
                if (!empty($data['pddk_wali_nama'])) {
                    $pendidikan = Pendidikan::where('nama', 'like', '%' . trim($data['pddk_wali_nama']) . '%')->first();
                    if (!$pendidikan) {
                        $errors[] = "Baris {$rowNumber}: Pendidikan Wali '{$data['pddk_wali_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pddk_wali = $pendidikan->id;
                    }
                }

                // Find Pekerjaan Wali by nama — jika tidak ditemukan: null, catat peringatan
                $id_pekerjaan_wali = null;
                if (!empty($data['pekerjaan_wali_nama'])) {
                    $pekerjaan = Pekerjaan::where('nama', 'like', '%' . trim($data['pekerjaan_wali_nama']) . '%')->first();
                    if (!$pekerjaan) {
                        $errors[] = "Baris {$rowNumber}: Pekerjaan Wali '{$data['pekerjaan_wali_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pekerjaan_wali = $pekerjaan->id;
                    }
                }

                // Find Penghasilan Wali by nama — jika tidak ditemukan: null, catat peringatan
                $id_penghasilan_wali = null;
                if (!empty($data['penghasilan_wali_nama'])) {
                    $penghasilan = Penghasilan::where('nama', 'like', '%' . trim($data['penghasilan_wali_nama']) . '%')->first();
                    if (!$penghasilan) {
                        $errors[] = "Baris {$rowNumber}: Penghasilan Wali '{$data['penghasilan_wali_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_penghasilan_wali = $penghasilan->id;
                    }
                }

                // Foto: berupa path relatif yang HARUS sudah ada di storage disk public (bukan upload/URL) — jika tidak ditemukan: null, catat peringatan
                $fotoPath = null;
                if (!empty($data['foto_path'])) {
                    $foto_path_trimmed = ltrim(trim((string) $data['foto_path']), '/');
                    if (!Storage::disk('public')->exists($foto_path_trimmed)) {
                        $errors[] = "Baris {$rowNumber}: Foto '{$foto_path_trimmed}' tidak ditemukan di storage, disimpan dengan Foto kosong.";
                    } else {
                        $fotoPath = $foto_path_trimmed;
                    }
                }

                // Prepare data for insert (nim/email pakai nilai yang mungkin sudah diset null karena duplikat)
                $mahasiswaData = [
                    'nama' => trim($data['nama']),
                    'nim' => $nimValue,
                    'email' => $emailValue,
                    'no_wa' => !empty($data['no_hp']) ? trim((string) $data['no_hp']) : null,
                    'handphone' => !empty($data['handphone']) ? trim((string) $data['handphone']) : null,
                    'jenis_kelamin' => $jenis_kelamin,
                    'id_tempat_lahir' => !empty($data['id_tempat_lahir']) ? trim((string) $data['id_tempat_lahir']) : null,
                    'tanggal_lahir' => self::normalizeImportDate($data['tanggal_lahir'] ?? null),
                    'no_ktp' => !empty($data['no_ktp']) ? trim($data['no_ktp']) : null,
                    'id_status_akademik' => $id_status_akademik,
                    'alamat' => !empty($data['alamat']) ? trim($data['alamat']) : null,
                    'rt' => !empty($data['rt']) ? trim($data['rt']) : null,
                    'rw' => !empty($data['rw']) ? trim($data['rw']) : null,
                    'dusun' => !empty($data['dusun']) ? trim($data['dusun']) : null,
                    'kelurahan' => !empty($data['kelurahan']) ? trim($data['kelurahan']) : null,
                    'kode_pos' => !empty($data['kode_pos']) ? trim($data['kode_pos']) : null,
                    'id_kecamatan' => !empty($data['id_kecamatan']) ? trim($data['id_kecamatan']) : null,
                    'id_negara' => $id_negara,
                    'id_provinsi' => $id_provinsi,
                    'id_kota' => $id_kota,
                    'id_prodi' => $id_prodi,
                    'id_kelompok_kelas' => $id_kelompok_kelas,
                    'id_semester_masuk' => $id_semester_masuk,
                    'id_jalur_masuk' => $id_jalur_masuk,
                    'id_jenis_daftar' => $id_jenis_daftar,
                    'mulai_semester' => !empty($data['mulai_semester']) ? trim($data['mulai_semester']) : null,
                    'sks_diakui' => !empty($data['sks_diakui']) ? (int)$data['sks_diakui'] : null,
                    'sekolah_asal' => !empty($data['sekolah_asal']) ? trim($data['sekolah_asal']) : null,
                    'nis' => !empty($data['nis']) ? trim($data['nis']) : null,
                    'nisn' => !empty($data['nisn']) ? trim($data['nisn']) : null,
                    'npwp' => !empty($data['npwp']) ? trim($data['npwp']) : null,
                    'ayah' => !empty($data['ayah']) ? trim($data['ayah']) : null,
                    'nik_ayah' => !empty($data['nik_ayah']) ? trim($data['nik_ayah']) : null,
                    'tgl_lahir_ayah' => self::normalizeImportDate($data['tgl_lahir_ayah'] ?? null),
                    'id_pddk_ayah' => $id_pddk_ayah,
                    'id_pekerjaan_ayah' => $id_pekerjaan_ayah,
                    'id_penghasilan_ayah' => $id_penghasilan_ayah,
                    'ibu' => !empty($data['ibu']) ? trim($data['ibu']) : null,
                    'nik_ibu' => !empty($data['nik_ibu']) ? trim($data['nik_ibu']) : null,
                    'tgl_lahir_ibu' => !empty($data['tgl_lahir_ibu']) ? $data['tgl_lahir_ibu'] : null,
                    'id_pddk_ibu' => $id_pddk_ibu,
                    'id_pekerjaan_ibu' => $id_pekerjaan_ibu,
                    'id_penghasilan_ibu' => $id_penghasilan_ibu,
                    'wali' => !empty($data['wali']) ? trim($data['wali']) : null,
                    'nik_wali' => !empty($data['nik_wali']) ? trim($data['nik_wali']) : null,
                    'tgl_lahir_wali' => self::normalizeImportDate($data['tgl_lahir_wali'] ?? null),
                    'id_pddk_wali' => $id_pddk_wali,
                    'id_pekerjaan_wali' => $id_pekerjaan_wali,
                    'id_penghasilan_wali' => $id_penghasilan_wali,
                    'jml_biaya_masuk' => self::normalizeImportDecimal($data['jml_biaya_masuk'] ?? null),
                    'penerima_kps' => !empty($data['penerima_kps']) ? trim($data['penerima_kps']) : null,
                    'no_kps' => !empty($data['no_kps']) ? trim($data['no_kps']) : null,
                    'foto' => $fotoPath,
                ];

                if ($mahasiswaToUpdate !== null) {
                    $mahasiswaToUpdate->update($mahasiswaData);
                    $mahasiswa = $mahasiswaToUpdate;
                } else {
                    $mahasiswa = Mahasiswa::create($mahasiswaData);
                }

                $successCount++;
                if ($nimValue !== null) {
                    $processedNimIds[$nimValue] = $mahasiswa->id;
                }
                $processedRows[] = [
                    'nim' => $mahasiswaData['nim'],
                    'email' => $mahasiswaData['email'],
                    'id' => $mahasiswa->id,
                ];
            }

            DB::commit();

            $message = "Import selesai. Berhasil: {$successCount}, Dilewati: {$skipCount}";
            if (count($errors) > 0) {
                $message .= ". Terdapat " . count($errors) . " error.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'success_count' => $successCount,
                    'skip_count' => $skipCount,
                    'error_count' => count($errors),
                    'errors' => $errors,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import mahasiswa gagal', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengimport data! Harap periksa kembali data yang diimport.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
