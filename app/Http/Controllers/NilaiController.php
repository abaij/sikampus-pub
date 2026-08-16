<?php

namespace App\Http\Controllers;

use App\Models\Mahasiswa;
use App\Models\Krs;
use App\Models\Nilai;
use App\Models\Matkul;
use App\Models\Kelas;
use App\Models\KurikulumMatkul;
use App\Models\Semester;
use App\Models\Perkuliahan;
use App\Models\Kehadiran;
use App\Models\JenisPenilaian;
use App\Models\BobotPenilaian;
use App\Models\Dosen;
use App\Models\DosenWali;
use App\Models\RentangNilai;
use App\Models\NilaiRevisi;
use App\Models\Notifikasi;
use App\Models\Setting;
use App\Services\SemesterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Dompdf\Dompdf;
use Dompdf\Options;

class NilaiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi');
        $semesterMasukId = $request->get('id_semester_masuk');

        // Query untuk mendapatkan data mahasiswa dengan jumlah mata kuliah yang sudah dikontrak
        $query = Mahasiswa::select([
                'mahasiswa.id',
                'mahasiswa.nim',
                'mahasiswa.nama',
                'prodi.id as id_prodi',
                'prodi.nama as prodi_nama',
                DB::raw('COUNT(DISTINCT krs.id) as jumlah_mata_kuliah')
            ])
            ->leftJoin('prodi', 'mahasiswa.id_prodi', '=', 'prodi.id')
            ->leftJoin('krs', function ($join) {
                $join->on('krs.id_mahasiswa', '=', 'mahasiswa.id')
                     ->whereNull('krs.deleted_at');
            })
            ->whereNull('mahasiswa.deleted_at')
            ->groupBy('mahasiswa.id', 'mahasiswa.nim', 'mahasiswa.nama', 'prodi.id', 'prodi.nama');

        // Filter berdasarkan pencarian nama atau nim
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('mahasiswa.nama', 'like', "%{$search}%")
                  ->orWhere('mahasiswa.nim', 'like', "%{$search}%");
            });
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('mahasiswa.id_prodi', $allowedProdiIds);
                if ($prodiId && !in_array((int) $prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        // Filter berdasarkan prodi
        if ($prodiId) {
            $query->where('mahasiswa.id_prodi', $prodiId);
        }

        // Filter berdasarkan semester masuk
        if ($semesterMasukId) {
            $query->where('mahasiswa.id_semester_masuk', $semesterMasukId);
        }

        // Hitung total sebelum pagination
        $totalQuery = clone $query;
        $total = DB::table(DB::raw("({$totalQuery->toSql()}) as sub"))
            ->mergeBindings($totalQuery->getQuery())
            ->count();

        // Pagination
        $page = (int) $request->get('page', 1);
        $offset = ($page - 1) * $perPage;
        
        $results = $query->orderBy('mahasiswa.nim')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        // Format data
        $data = $results->map(function ($item) {
            return [
                'id' => $item->id,
                'nim' => $item->nim,
                'nama' => $item->nama,
                'prodi' => [
                    'id' => $item->id_prodi,
                    'nama' => $item->prodi_nama ?? '-',
                ],
                'jumlah_mata_kuliah' => (int) $item->jumlah_mata_kuliah,
            ];
        });

        $lastPage = (int) ceil($total / $perPage);

        return response()->json([
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
        ]);
    }

    /**
     * Get list of mata kuliah yang diampu dosen untuk input nilai
     */
    public function getMyMataKuliah(Request $request): JsonResponse
    {
        $user = $request->user();
        $dosen = \App\Models\Dosen::where('id_user', $user->id)->first();
        
        if (!$dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan'
            ], 404);
        }

        // Ambil semester aktif
        $activeSemester = Semester::where('is_active', true)->first();
        
        if (!$activeSemester) {
            return response()->json([
                'semester' => null,
                'data' => []
            ]);
        }

        // Ambil semua kelas yang diampu dosen pada semester aktif
        // Cara 1: Kelas dimana dosen adalah PIC dan memiliki jadwal di semester aktif
        $kelasAsPIC = Kelas::where('id_dosen_pic', $dosen->id)
            ->where('id_semester', $activeSemester->id)
            ->pluck('id')
            ->toArray();

        // Cara 2: Kelas dimana dosen memiliki jadwal aktif di semester aktif
        $kelasWithJadwal = \App\Models\JadwalDosen::where('id_dosen', $dosen->id)
            ->where('status', 'active')
            ->whereHas('jadwal.kelas', function ($q) use ($activeSemester) {
                $q->where('id_semester', $activeSemester->id);
            })
            ->with('jadwal:id_kelas')
            ->get()
            ->pluck('jadwal.id_kelas')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // Gabungkan dan hapus duplikat
        $kelasIds = array_unique(array_merge($kelasAsPIC, $kelasWithJadwal));

        if (empty($kelasIds)) {
            return response()->json([
                'semester' => [
                    'id' => $activeSemester->id,
                    'kode' => $activeSemester->kode,
                    'nama' => $activeSemester->nama,
                ],
                'data' => []
            ]);
        }

        // Ambil data kelas dengan relasi
        $kelasList = Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi',
            'prodi.jenjang',
            'semester',
        ])
        ->whereIn('id', $kelasIds)
        ->where('id_semester', $activeSemester->id)
        ->get();

        // Hitung jumlah mahasiswa per kelas dari KRS
        $mahasiswaCounts = Krs::whereIn('id_kelas', $kelasIds)
            ->whereNull('deleted_at')
            ->selectRaw('id_kelas, COUNT(DISTINCT id_mahasiswa) as jumlah_mahasiswa')
            ->groupBy('id_kelas')
            ->pluck('jumlah_mahasiswa', 'id_kelas')
            ->toArray();

        // Format data
        $data = $kelasList->map(function ($kelas) use ($mahasiswaCounts) {
            $kurikulumMatkul = $kelas->kurikulumMatkul;
            $matkul = $kurikulumMatkul?->matkul;
            
            // Kode mata kuliah: prioritas dari kurikulum_matkul, jika kosong ambil dari matkul
            $kodeMatkul = (!empty($kurikulumMatkul?->kode_matkul) && trim($kurikulumMatkul->kode_matkul) !== '') 
                ? $kurikulumMatkul->kode_matkul 
                : ($matkul?->kode ?? '-');
            
            // Nama mata kuliah: prioritas dari kurikulum_matkul, jika kosong ambil dari matkul
            $namaMatkul = (!empty($kurikulumMatkul?->nama_matkul) && trim($kurikulumMatkul->nama_matkul) !== '') 
                ? $kurikulumMatkul->nama_matkul 
                : ($matkul?->nama ?? '-');
            
            // SKS: prioritas dari kurikulum_matkul, jika kosong atau 0 ambil dari matkul
            $sks = (!empty($kurikulumMatkul?->sks) && $kurikulumMatkul->sks > 0)
                ? $kurikulumMatkul->sks
                : ($matkul?->sks ?? 0);
            
            return [
                'id_kelas' => $kelas->id,
                'kode_matkul' => $kodeMatkul,
                'nama_matkul' => $namaMatkul,
                'sks' => $sks,
                'nama_kelas' => $kelas->nama ?? '-',
                'prodi' => $kelas->prodi ? [
                    'id' => $kelas->prodi->id,
                    'nama' => $kelas->prodi->nama,
                    'jenjang' => $kelas->prodi->jenjang ? [
                        'id' => $kelas->prodi->jenjang->id,
                        'nama' => $kelas->prodi->jenjang->nama,
                    ] : null,
                ] : null,
                'semester' => $kelas->semester ? [
                    'id' => $kelas->semester->id,
                    'kode' => $kelas->semester->kode,
                    'nama' => $kelas->semester->nama,
                ] : null,
                'jumlah_mahasiswa' => $mahasiswaCounts[$kelas->id] ?? 0,
            ];
        })
        ->sortBy('nama_matkul')
        ->values();

        return response()->json([
            'semester' => [
                'id' => $activeSemester->id,
                'kode' => $activeSemester->kode,
                'nama' => $activeSemester->nama,
            ],
            'data' => $data,
        ]);
    }

    /**
     * Get list of mahasiswa yang mengontrak kelas untuk input nilai
     */
    public function getMahasiswaByKelas(Request $request, int $idKelas): JsonResponse
    {
        $user = $request->user();
        $dosen = \App\Models\Dosen::where('id_user', $user->id)->first();
        
        if (!$dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan'
            ], 404);
        }

        // Verifikasi bahwa dosen memiliki akses ke kelas ini
        $kelas = Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi',
            'prodi.jenjang',
            'semester',
        ])->find($idKelas);

        if (!$kelas) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan'
            ], 404);
        }

        // Cek apakah dosen adalah dosen PIC atau memiliki jadwal di kelas ini
        $hasAccess = false;
        if ($kelas->id_dosen_pic === $dosen->id) {
            $hasAccess = true;
        } else {
            $hasJadwal = \App\Models\JadwalDosen::whereHas('jadwal', function ($q) use ($idKelas) {
                $q->where('id_kelas', $idKelas);
            })
            ->where('id_dosen', $dosen->id)
            ->where('status', 'active')
            ->exists();

            if ($hasJadwal) {
                $hasAccess = true;
            }
        }

        if (!$hasAccess) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke kelas ini'
            ], 403);
        }

        // Ambil KRS untuk kelas ini
        $krsList = Krs::with([
            'mahasiswa.prodi',
            'mahasiswa.semester_masuk',
        ])
        ->join('mahasiswa', 'krs.id_mahasiswa', '=', 'mahasiswa.id')
        ->where('krs.id_kelas', $idKelas)
        ->whereNull('krs.deleted_at')
        ->whereNull('mahasiswa.deleted_at')
        ->select('krs.*')
        ->orderBy('mahasiswa.nim')
        ->get();

        // Ambil nilai komponen untuk semua KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiKomponenMap = [];
        if (!empty($krsIds)) {
            $nilaiKomponenList = DB::table('nilai_komponen')
                ->whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->get()
                ->groupBy('id_krs')
                ->map(function ($items) {
                    return $items->keyBy('id_jenis_penilaian');
                })
                ->toArray();
            $nilaiKomponenMap = $nilaiKomponenList;
        }

        // Ambil nilai (angka_mutu dan huruf_mutu) untuk semua KRS
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList;
        }

        // Kode mata kuliah: prioritas dari kurikulum_matkul, jika kosong ambil dari matkul
        $kodeMatkul = (!empty($kelas->kurikulumMatkul?->kode_matkul) && trim($kelas->kurikulumMatkul->kode_matkul) !== '') 
            ? $kelas->kurikulumMatkul->kode_matkul 
            : ($kelas->kurikulumMatkul?->matkul?->kode ?? '-');
        
        // Nama mata kuliah: prioritas dari kurikulum_matkul, jika kosong ambil dari matkul
        $namaMatkul = (!empty($kelas->kurikulumMatkul?->nama_matkul) && trim($kelas->kurikulumMatkul->nama_matkul) !== '') 
            ? $kelas->kurikulumMatkul->nama_matkul 
            : ($kelas->kurikulumMatkul?->matkul?->nama ?? '-');
        
        // SKS: prioritas dari kurikulum_matkul, jika kosong atau 0 ambil dari matkul
        $sks = (!empty($kelas->kurikulumMatkul?->sks) && $kelas->kurikulumMatkul->sks > 0)
            ? $kelas->kurikulumMatkul->sks
            : ($kelas->kurikulumMatkul?->matkul?->sks ?? 0);

        // Bobot per jenis penilaian: prioritas dari bobot_penilaian (mata kuliah), fallback ke jenis_penilaian (default)
        $bobotPenilaianMap = [];
        if ($kelas->id_kurikulum_matkul) {
            $bobotPenilaianMap = BobotPenilaian::where('id_kurikulum_matkul', $kelas->id_kurikulum_matkul)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_jenis_penilaian');
        }
        $jenisPenilaianBase = JenisPenilaian::whereNull('deleted_at')
            ->where('status', 'manual')
            ->orderBy('nama')
            ->get();
        $jenisPenilaianWithBobot = $jenisPenilaianBase->map(function ($jp) use ($bobotPenilaianMap) {
            $bobotPenilaian = $bobotPenilaianMap->get($jp->id);
            $bobot = $bobotPenilaian !== null
                ? (float) $bobotPenilaian->bobot
                : (float) $jp->bobot;
            return [
                'id' => $jp->id,
                'kode' => $jp->kode,
                'nama' => $jp->nama,
                'bobot' => $bobot,
                'status' => $jp->status,
            ];
        })->values()->all();

        // Jenis penilaian otomatis (Kehadiran): tampilkan di tabel, nilai = persentase hadir dari tabel kehadiran
        $jenisPenilaianKehadiran = JenisPenilaian::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('kode', 'PRESENSI')
                    ->orWhere('nama', 'like', '%presensi%')
                    ->orWhere('nama', 'like', '%kehadiran%');
            })
            ->first();
        if ($jenisPenilaianKehadiran) {
            $bobotKehadiran = $bobotPenilaianMap->get($jenisPenilaianKehadiran->id);
            $bobotKehadiranVal = $bobotKehadiran !== null
                ? (float) $bobotKehadiran->bobot
                : (float) $jenisPenilaianKehadiran->bobot;
            $jenisPenilaianWithBobot[] = [
                'id' => $jenisPenilaianKehadiran->id,
                'kode' => $jenisPenilaianKehadiran->kode,
                'nama' => $jenisPenilaianKehadiran->nama,
                'bobot' => $bobotKehadiranVal,
                'status' => $jenisPenilaianKehadiran->status,
            ];
        }

        // Hitung persentase kehadiran per mahasiswa dari tabel kehadiran (status hadir)
        $persentaseKehadiranMap = [];
        $jadwalList = \App\Models\Jadwal::where('id_kelas', $idKelas)->whereNull('deleted_at')->pluck('id')->toArray();
        if (!empty($jadwalList) && $jenisPenilaianKehadiran) {
            $perkuliahanList = Perkuliahan::whereIn('id_jadwal', $jadwalList)->whereNull('deleted_at')->get();
            $perkuliahanIds = $perkuliahanList->pluck('id')->toArray();
            $jumlahPerkuliahan = count($perkuliahanIds);
            if ($jumlahPerkuliahan > 0) {
                $kehadiranPerMahasiswa = Kehadiran::whereIn('id_perkuliahan', $perkuliahanIds)
                    ->whereNull('deleted_at')
                    ->where('status', 'hadir')
                    ->get()
                    ->groupBy('id_mhs')
                    ->map(fn ($items) => $items->count())
                    ->toArray();
                foreach ($krsList as $krs) {
                    $jumlahHadir = $kehadiranPerMahasiswa[$krs->id_mahasiswa] ?? 0;
                    $persentaseKehadiranMap[$krs->id_mahasiswa] = round(($jumlahHadir / $jumlahPerkuliahan) * 100, 2);
                }
            }
        }

        // Format data
        $idJenisKehadiran = $jenisPenilaianKehadiran?->id;
        $data = $krsList->map(function ($krs) use ($nilaiKomponenMap, $nilaiMap, $persentaseKehadiranMap, $idJenisKehadiran) {
            $mahasiswa = $krs->mahasiswa;
            $nilaiKomponen = $nilaiKomponenMap[$krs->id] ?? [];
            if (is_object($nilaiKomponen)) {
                $nilaiKomponen = (array) $nilaiKomponen;
            }
            if ($idJenisKehadiran !== null && isset($persentaseKehadiranMap[$krs->id_mahasiswa])) {
                $nilaiKomponen[$idJenisKehadiran] = (object) [
                    'id_jenis_penilaian' => $idJenisKehadiran,
                    'nilai' => $persentaseKehadiranMap[$krs->id_mahasiswa],
                ];
            }
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;

            return [
                'id_krs' => $krs->id,
                'id_mahasiswa' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'nama' => $mahasiswa->semester_masuk->nama,
                ] : null,
                'nilai_komponen' => $nilaiKomponen,
                'nilai' => $nilai ? [
                    'id' => $nilai->id,
                    'angka_mutu' => $nilai->angka_mutu,
                    'huruf_mutu' => $nilai->huruf_mutu,
                    'is_final' => $nilai->is_final,
                    'revisi' => (int) ($nilai->revisi ?? 0),
                ] : null,
            ];
        });
        $rentangNilaiList = [];
        $jenjang = $kelas->prodi?->jenjang;
        if ($jenjang) {
            $rentangNilaiList = RentangNilai::where('id_jenjang', $jenjang->id)
                ->whereNull('deleted_at')
                ->orderBy('nilai_tinggi', 'desc')
                ->get()
                ->map(function ($rn) {
                    return [
                        'id' => $rn->id,
                        'nilai_huruf' => $rn->nilai_huruf,
                        'nilai_angka' => (float) $rn->nilai_angka,
                        'nilai_rendah' => (float) $rn->nilai_rendah,
                        'nilai_tinggi' => (float) $rn->nilai_tinggi,
                    ];
                })
                ->values()
                ->all();
        }

        // Id jenis penilaian yang dipakai di kelas ini (untuk kolom Jumlah Total Nilai): bobot matkul + otomatis (kehadiran)
        $idsManualKelas = $bobotPenilaianMap->isNotEmpty()
            ? $bobotPenilaianMap->keys()
            : $jenisPenilaianBase->pluck('id');
        $idsOtomatis = JenisPenilaian::whereNull('deleted_at')->where('status', 'otomatis')->pluck('id');
        $idJenisPenilaianKelas = $idsManualKelas->merge($idsOtomatis)->unique()->values()->all();

        return response()->json([
            'kelas' => [
                'id' => $kelas->id,
                'nama' => $kelas->nama ?? '-',
                'kode_matkul' => $kodeMatkul,
                'nama_matkul' => $namaMatkul,
                'sks' => $sks,
                'prodi' => $kelas->prodi ? [
                    'id' => $kelas->prodi->id,
                    'nama' => $kelas->prodi->nama,
                ] : null,
            ],
            'jenis_penilaian' => $jenisPenilaianWithBobot,
            'rentang_nilai' => $rentangNilaiList,
            'id_jenis_penilaian_kelas' => $idJenisPenilaianKelas,
            'data' => $data,
        ]);
    }

    /**
     * Get list of jenis penilaian
     */
    public function getJenisPenilaian(Request $request): JsonResponse
    {
        $jenisPenilaian = \App\Models\JenisPenilaian::whereNull('deleted_at')
            ->where('status', 'manual')
            ->orderBy('nama')
            ->get();

        return response()->json($jenisPenilaian);
    }

    /**
     * Kalkulasi nilai akhir untuk kelas
     */
    public function kalkulasiNilaiAkhir(Request $request, int $idKelas): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();
        
        if (!$dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan'
            ], 404);
        }

        // Verifikasi bahwa dosen memiliki akses ke kelas ini
        $kelas = Kelas::with([
            'kurikulumMatkul.matkul',
            'prodi.jenjang',
        ])->find($idKelas);

        if (!$kelas) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan'
            ], 404);
        }

        // Cek apakah dosen adalah dosen PIC atau memiliki jadwal di kelas ini
        $hasAccess = false;
        if ($kelas->id_dosen_pic === $dosen->id) {
            $hasAccess = true;
        } else {
            $hasJadwal = \App\Models\JadwalDosen::whereHas('jadwal', function ($q) use ($idKelas) {
                $q->where('id_kelas', $idKelas);
            })
            ->where('id_dosen', $dosen->id)
            ->where('status', 'active')
            ->exists();

            if ($hasJadwal) {
                $hasAccess = true;
            }
        }

        if (!$hasAccess) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke kelas ini'
            ], 403);
        }

        // Ambil jenjang dari kelas
        $jenjang = $kelas->prodi?->jenjang;
        if (!$jenjang) {
            return response()->json([
                'message' => 'Jenjang tidak ditemukan untuk kelas ini'
            ], 400);
        }

        // Ambil rentang nilai untuk jenjang ini
        $rentangNilaiList = RentangNilai::where('id_jenjang', $jenjang->id)
            ->whereNull('deleted_at')
            ->orderBy('nilai_tinggi', 'desc')
            ->get();

        if ($rentangNilaiList->isEmpty()) {
            return response()->json([
                'message' => 'Rentang nilai tidak ditemukan untuk jenjang ' . $jenjang->nama
            ], 400);
        }

        // Ambil semua jenis penilaian dengan bobot
        $jenisPenilaianList = JenisPenilaian::whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        // Bobot per jenis penilaian: prioritas dari bobot_penilaian (mata kuliah), fallback ke jenis_penilaian (default)
        $bobotPenilaianMap = collect();
        if ($kelas->id_kurikulum_matkul) {
            $bobotPenilaianMap = BobotPenilaian::where('id_kurikulum_matkul', $kelas->id_kurikulum_matkul)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_jenis_penilaian');
        }

        // Ambil semua KRS untuk kelas ini
        $krsList = Krs::where('id_kelas', $idKelas)
            ->whereNull('deleted_at')
            ->get();

        if ($krsList->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada mahasiswa yang mengambil kelas ini'
            ], 400);
        }

        // Ambil SKS dari kelas
        $sks = $kelas->kurikulumMatkul?->sks ?? $kelas->kurikulumMatkul?->matkul?->sks ?? 0;

        // Ambil semua nilai komponen untuk KRS di kelas ini
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiKomponenList = DB::table('nilai_komponen')
            ->whereIn('id_krs', $krsIds)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('id_krs');

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($krsList as $krs) {
                $nilaiKomponenKrs = $nilaiKomponenList->get($krs->id, collect());

                if ($nilaiKomponenKrs->isEmpty()) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Tidak ada nilai komponen";
                    continue;
                }

                // Hitung nilai akhir berdasarkan bobot
                $totalNilai = 0;
                $totalBobot = 0;
                $allJenisPenilaianFilled = true;

                foreach ($nilaiKomponenKrs as $nk) {
                    $jenisPenilaian = $jenisPenilaianList->get($nk->id_jenis_penilaian);
                    if (!$jenisPenilaian) {
                        continue;
                    }

                    $nilai = (float) $nk->nilai;
                    $bobotPenilaian = $bobotPenilaianMap->get($nk->id_jenis_penilaian);
                    $bobot = $bobotPenilaian !== null
                        ? (float) $bobotPenilaian->bobot
                        : (float) $jenisPenilaian->bobot;

                    $totalNilai += $nilai * $bobot;
                    $totalBobot += $bobot;
                }

                // Pastikan semua jenis penilaian sudah diisi
                foreach ($jenisPenilaianList as $jp) {
                    $hasNilai = $nilaiKomponenKrs->contains('id_jenis_penilaian', $jp->id);
                    if (!$hasNilai) {
                        $allJenisPenilaianFilled = false;
                        break;
                    }
                }

                if (!$allJenisPenilaianFilled) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Belum semua jenis penilaian diisi";
                    continue;
                }

                if ($totalBobot === 0) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Total bobot tidak boleh nol";
                    continue;
                }

                // Hitung nilai akhir
                $nilaiAkhir = $totalNilai / $totalBobot;

                // Cari rentang nilai yang sesuai
                $rentangNilai = null;
                foreach ($rentangNilaiList as $rn) {
                    if ($nilaiAkhir >= (float) $rn->nilai_rendah && $nilaiAkhir <= (float) $rn->nilai_tinggi) {
                        $rentangNilai = $rn;
                        break;
                    }
                }

                if (!$rentangNilai) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Nilai akhir {$nilaiAkhir} tidak sesuai dengan rentang nilai yang tersedia";
                    continue;
                }

                // Simpan atau update nilai
                $nilai = Nilai::where('id_krs', $krs->id)->first();
                
                if ($nilai) {
                    $nilai->update([
                        'sks' => $sks,
                        'angka_mutu' => $rentangNilai->nilai_angka,
                        'huruf_mutu' => $rentangNilai->nilai_huruf,
                        'is_final' => null,
                    ]);
                } else {
                    Nilai::create([
                        'id_krs' => $krs->id,
                        'sks' => $sks,
                        'angka_mutu' => $rentangNilai->nilai_angka,
                        'huruf_mutu' => $rentangNilai->nilai_huruf,
                        'is_final' => null,
                    ]);
                }

                $successCount++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Kalkulasi nilai akhir berhasil',
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Terjadi kesalahan saat melakukan kalkulasi nilai akhir',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Kalkulasi nilai akhir dengan rentang nilai custom dan simpan ke tabel nilai (is_final = null)
     * Body: { rentang_nilai: [ { nilai_huruf, nilai_angka, nilai_rendah, nilai_tinggi }, ... ] }
     */
    public function kalkulasiPreview(Request $request, int $idKelas): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $kelas = Kelas::with(['kurikulumMatkul.matkul', 'prodi.jenjang'])->find($idKelas);
        if (!$kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $hasAccess = $kelas->id_dosen_pic === $dosen->id
            || \App\Models\JadwalDosen::whereHas('jadwal', fn ($q) => $q->where('id_kelas', $idKelas))
                ->where('id_dosen', $dosen->id)
                ->where('status', 'active')
                ->exists();
        if (!$hasAccess) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke kelas ini'], 403);
        }

        $validated = $request->validate([
            'rentang_nilai' => ['required', 'array', 'min:1'],
            'rentang_nilai.*.nilai_huruf' => ['required', 'string', 'max:10'],
            'rentang_nilai.*.nilai_angka' => ['required', 'numeric', 'min:0'],
            'rentang_nilai.*.nilai_rendah' => ['required', 'numeric', 'min:0'],
            'rentang_nilai.*.nilai_tinggi' => ['required', 'numeric', 'min:0'],
        ]);

        $rentangNilaiList = collect($validated['rentang_nilai'])->sortByDesc('nilai_tinggi')->values();

        // Semua jenis penilaian (manual + otomatis/kehadiran) agar nilai kehadiran ikut terhitung
        $jenisPenilaianList = JenisPenilaian::whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $bobotPenilaianMap = collect();
        if ($kelas->id_kurikulum_matkul) {
            $bobotPenilaianMap = BobotPenilaian::where('id_kurikulum_matkul', $kelas->id_kurikulum_matkul)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_jenis_penilaian');
        }

        $krsList = Krs::where('id_kelas', $idKelas)->whereNull('deleted_at')->get();
        if ($krsList->isEmpty()) {
            return response()->json(['message' => 'Tidak ada mahasiswa di kelas ini'], 400);
        }

        $sks = $kelas->kurikulumMatkul?->sks ?? $kelas->kurikulumMatkul?->matkul?->sks ?? 0;

        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiKomponenList = DB::table('nilai_komponen')
            ->whereIn('id_krs', $krsIds)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('id_krs');

        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $result = [];

        DB::beginTransaction();
        try {
            foreach ($krsList as $krs) {
                $nilaiKomponenKrs = $nilaiKomponenList->get($krs->id, collect());
                if ($nilaiKomponenKrs->isEmpty()) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Tidak ada nilai komponen";
                    continue;
                }

                $totalNilai = 0;
                $totalBobot = 0;
                foreach ($nilaiKomponenKrs as $nk) {
                    $jp = $jenisPenilaianList->get($nk->id_jenis_penilaian);
                    if (!$jp) {
                        continue;
                    }
                    $bobot = $bobotPenilaianMap->get($nk->id_jenis_penilaian)
                        ? (float) $bobotPenilaianMap->get($nk->id_jenis_penilaian)->bobot
                        : (float) $jp->bobot;
                    $totalNilai += (float) $nk->nilai * $bobot;
                    $totalBobot += $bobot;
                }

                $allFilled = $jenisPenilaianList->every(fn ($jp) => $nilaiKomponenKrs->contains('id_jenis_penilaian', $jp->id));
                if (!$allFilled) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Belum semua jenis penilaian diisi";
                    continue;
                }
                if ($totalBobot <= 0) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Total bobot tidak boleh nol";
                    continue;
                }

                $nilaiAkhir = $totalNilai / $totalBobot;
                $rentangNilai = $rentangNilaiList->first(function ($rn) use ($nilaiAkhir) {
                    $low = (float) $rn['nilai_rendah'];
                    $high = (float) $rn['nilai_tinggi'];
                    return $nilaiAkhir >= $low && $nilaiAkhir <= $high;
                });

                if (!$rentangNilai) {
                    $errorCount++;
                    $errors[] = "KRS ID {$krs->id}: Nilai akhir " . round($nilaiAkhir, 2) . " tidak sesuai rentang";
                    continue;
                }

                $nilai = Nilai::where('id_krs', $krs->id)->first();
                if ($nilai) {
                    $nilai->update([
                        'sks' => $sks,
                        'angka_mutu' => (float) $rentangNilai['nilai_angka'],
                        'huruf_mutu' => $rentangNilai['nilai_huruf'],
                        'is_final' => null,
                    ]);
                } else {
                    Nilai::create([
                        'id_krs' => $krs->id,
                        'sks' => $sks,
                        'angka_mutu' => (float) $rentangNilai['nilai_angka'],
                        'huruf_mutu' => $rentangNilai['nilai_huruf'],
                        'is_final' => null,
                    ]);
                }

                $result[] = [
                    'id_krs' => $krs->id,
                    'nilai_akhir' => round($nilaiAkhir, 2),
                    'angka_mutu' => (float) $rentangNilai['nilai_angka'],
                    'huruf_mutu' => $rentangNilai['nilai_huruf'],
                ];
                $successCount++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Kalkulasi nilai akhir dengan rentang custom berhasil disimpan',
                'data' => $result,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Terjadi kesalahan saat kalkulasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Finalisasi nilai: set is_final = true untuk semua nilai di kelas ini (nilai tampil di akun mahasiswa).
     */
    public function finalizeNilai(Request $request, int $idKelas): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $kelas = Kelas::find($idKelas);
        if (!$kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $hasAccess = $kelas->id_dosen_pic === $dosen->id
            || \App\Models\JadwalDosen::whereHas('jadwal', fn ($q) => $q->where('id_kelas', $idKelas))
                ->where('id_dosen', $dosen->id)
                ->where('status', 'active')
                ->exists();
        if (!$hasAccess) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke kelas ini'], 403);
        }

        $krsIds = Krs::where('id_kelas', $idKelas)->whereNull('deleted_at')->pluck('id')->toArray();
        if (empty($krsIds)) {
            return response()->json(['message' => 'Tidak ada mahasiswa di kelas ini'], 400);
        }

        $updated = Nilai::whereIn('id_krs', $krsIds)->whereNull('deleted_at')->update(['is_final' => true]);

        $namaMatkul = $kelas->kurikulumMatkul?->matkul?->nama ?? 'kelas ini';
        $idMahasiswaTerdampak = Krs::whereIn('id', $krsIds)->pluck('id_mahasiswa')->unique();
        $idUserPerMahasiswa = Mahasiswa::whereIn('id', $idMahasiswaTerdampak)
            ->whereNotNull('id_user')
            ->pluck('id_user');
        foreach ($idUserPerMahasiswa as $idUser) {
            Notifikasi::kirim(
                idUser: $idUser,
                tipe: 'nilai_final',
                judul: 'Nilai sudah keluar',
                pesan: "Nilai {$namaMatkul} sudah difinalisasi dan bisa dilihat.",
                url: '/mahasiswa/nilai',
            );
        }

        return response()->json([
            'message' => 'Nilai berhasil difinalisasi. Nilai akan tampil di akun mahasiswa.',
            'updated_count' => $updated,
        ], 200);
    }

    /**
     * Simpan revisi nilai: insert ke nilai_revisi dan update nilai + kolom revisi.
     * Dosen only; verifikasi akses via id_kelas dari KRS.
     */
    public function storeRevisiNilai(Request $request): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();
        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'id_krs' => ['required', 'integer', 'exists:krs,id'],
            'huruf_mutu' => ['required', 'string', 'max:10'],
            'angka_mutu' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ]);

        $krs = Krs::find($validated['id_krs']);
        if (!$krs || $krs->deleted_at) {
            return response()->json(['message' => 'KRS tidak ditemukan'], 404);
        }

        $idKelas = $krs->id_kelas;
        $kelas = Kelas::with(['kurikulumMatkul.matkul'])->find($idKelas);
        if (!$kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $hasAccess = $kelas->id_dosen_pic === $dosen->id
            || \App\Models\JadwalDosen::whereHas('jadwal', fn ($q) => $q->where('id_kelas', $idKelas))
                ->where('id_dosen', $dosen->id)
                ->where('status', 'active')
                ->exists();
        if (!$hasAccess) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke kelas ini'], 403);
        }

        $sks = (!empty($kelas->kurikulumMatkul?->sks) && $kelas->kurikulumMatkul->sks > 0)
            ? (int) $kelas->kurikulumMatkul->sks
            : (int) ($kelas->kurikulumMatkul?->matkul?->sks ?? 0);

        $createdBy = $user->name ?? (string) $user->id;

        try {
            DB::beginTransaction();

            NilaiRevisi::create([
                'id_krs' => $validated['id_krs'],
                'angka_mutu' => $validated['angka_mutu'] ?? null,
                'huruf_mutu' => $validated['huruf_mutu'],
                'keterangan' => $validated['keterangan'] ?? null,
                'created_by' => $createdBy,
            ]);

            $revisiCount = NilaiRevisi::where('id_krs', $validated['id_krs'])->whereNull('deleted_at')->count();

            $nilai = Nilai::where('id_krs', $validated['id_krs'])->whereNull('deleted_at')->first();
            $angkaMutu = $validated['angka_mutu'] ?? $nilai?->angka_mutu;

            if (!$nilai) {
                Nilai::create([
                    'id_krs' => $validated['id_krs'],
                    'sks' => $sks ?: null,
                    'angka_mutu' => $angkaMutu,
                    'huruf_mutu' => $validated['huruf_mutu'],
                    'is_final' => null,
                    'revisi' => $revisiCount,
                ]);
            } else {
                $nilai->update([
                    'angka_mutu' => $angkaMutu,
                    'huruf_mutu' => $validated['huruf_mutu'],
                    'revisi' => $revisiCount,
                ]);
            }

            DB::commit();
            return response()->json([
                'message' => 'Revisi nilai berhasil disimpan.',
                'revisi_ke' => $revisiCount,
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal menyimpan revisi nilai.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update nilai saja (tanpa revisi): hanya update angka_mutu dan huruf_mutu di tabel nilai.
     * Dosen only; verifikasi akses via id_kelas dari KRS.
     */
    public function updateNilaiByKrs(Request $request): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();
        if (!$dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'id_krs' => ['required', 'integer', 'exists:krs,id'],
            'huruf_mutu' => ['required', 'string', 'max:10'],
            'angka_mutu' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
        ]);

        $krs = Krs::find($validated['id_krs']);
        if (!$krs || $krs->deleted_at) {
            return response()->json(['message' => 'KRS tidak ditemukan'], 404);
        }

        $idKelas = $krs->id_kelas;
        $kelas = Kelas::with(['kurikulumMatkul.matkul'])->find($idKelas);
        if (!$kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $hasAccess = $kelas->id_dosen_pic === $dosen->id
            || \App\Models\JadwalDosen::whereHas('jadwal', fn ($q) => $q->where('id_kelas', $idKelas))
                ->where('id_dosen', $dosen->id)
                ->where('status', 'active')
                ->exists();
        if (!$hasAccess) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke kelas ini'], 403);
        }

        $sks = (!empty($kelas->kurikulumMatkul?->sks) && $kelas->kurikulumMatkul->sks > 0)
            ? (int) $kelas->kurikulumMatkul->sks
            : (int) ($kelas->kurikulumMatkul?->matkul?->sks ?? 0);

        $nilai = Nilai::where('id_krs', $validated['id_krs'])->whereNull('deleted_at')->first();
        $angkaMutu = $validated['angka_mutu'] ?? $nilai?->angka_mutu;

        if (!$nilai) {
            Nilai::create([
                'id_krs' => $validated['id_krs'],
                'sks' => $sks ?: null,
                'huruf_mutu' => $validated['huruf_mutu'],
                'angka_mutu' => $angkaMutu,
                'is_final' => false,
            ]);
        } else {
            $nilai->update([
                'huruf_mutu' => $validated['huruf_mutu'],
                'angka_mutu' => $angkaMutu,
            ]);
        }

        return response()->json([
            'message' => 'Nilai berhasil diperbarui.',
        ], 200);
    }

    /**
     * Get mahasiswa dengan nilai komponen untuk admin (tanpa verifikasi dosen)
     */
    public function getMahasiswaByKelasAdmin(Request $request, int $idKelas): JsonResponse
    {
        // Ambil data kelas
        $kelas = Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi',
            'prodi.jenjang',
            'semester',
        ])->find($idKelas);

        if (!$kelas) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan'
            ], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data nilai kelas ini.');
            }
        }

        // Ambil KRS untuk kelas ini
        $krsList = Krs::with([
            'mahasiswa.prodi',
            'mahasiswa.semester_masuk',
        ])
        ->join('mahasiswa', 'krs.id_mahasiswa', '=', 'mahasiswa.id')
        ->where('krs.id_kelas', $idKelas)
        ->whereNull('krs.deleted_at')
        ->whereNull('mahasiswa.deleted_at')
        ->select('krs.*')
        ->orderBy('mahasiswa.nim')
        ->get();

        // Ambil nilai komponen untuk semua KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiKomponenMap = [];
        if (!empty($krsIds)) {
            $nilaiKomponenList = DB::table('nilai_komponen')
                ->join('jenis_penilaian', 'nilai_komponen.id_jenis_penilaian', '=', 'jenis_penilaian.id')
                ->whereIn('nilai_komponen.id_krs', $krsIds)
                ->whereNull('nilai_komponen.deleted_at')
                ->whereNull('jenis_penilaian.deleted_at')
                ->select('nilai_komponen.*', 'jenis_penilaian.nama as jenis_penilaian_nama', 'jenis_penilaian.kode as jenis_penilaian_kode', 'jenis_penilaian.bobot')
                ->get()
                ->groupBy('id_krs')
                ->map(function ($items) {
                    return $items->keyBy('id_jenis_penilaian');
                })
                ->toArray();
            $nilaiKomponenMap = $nilaiKomponenList;
        }

        // Ambil nilai (angka_mutu dan huruf_mutu) untuk semua KRS
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList;
        }

        // Ambil jenis penilaian untuk referensi
        $jenisPenilaianList = \App\Models\JenisPenilaian::whereNull('deleted_at')
            ->orderBy('nama')
            ->get();

        // Format data
        $data = $krsList->map(function ($krs) use ($nilaiKomponenMap, $nilaiMap) {
            $mahasiswa = $krs->mahasiswa;
            $nilaiKomponen = $nilaiKomponenMap[$krs->id] ?? [];
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;

            return [
                'id_krs' => $krs->id,
                'id_mahasiswa' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'nama' => $mahasiswa->semester_masuk->nama,
                ] : null,
                'nilai_komponen' => $nilaiKomponen,
                'nilai' => $nilai ? [
                    'id' => $nilai->id,
                    'angka_mutu' => $nilai->angka_mutu,
                    'huruf_mutu' => $nilai->huruf_mutu,
                    'is_final' => $nilai->is_final,
                ] : null,
            ];
        });

        // Ambil data mata kuliah dari kurikulum_matkul dengan fallback ke matkul
        $kurikulumMatkul = $kelas->kurikulumMatkul;
        $matkul = $kurikulumMatkul?->matkul;
        
        // Kode mata kuliah: prioritas dari kurikulum_matkul, jika kosong ambil dari matkul
        $kodeMatkul = (!empty($kurikulumMatkul?->kode_matkul) && trim($kurikulumMatkul->kode_matkul) !== '') 
            ? $kurikulumMatkul->kode_matkul 
            : ($matkul?->kode ?? '-');
        
        // Nama mata kuliah: prioritas dari kurikulum_matkul, jika kosong ambil dari matkul
        $namaMatkul = (!empty($kurikulumMatkul?->nama_matkul) && trim($kurikulumMatkul->nama_matkul) !== '') 
            ? $kurikulumMatkul->nama_matkul 
            : ($matkul?->nama ?? '-');
        
        // SKS: prioritas dari kurikulum_matkul, jika kosong ambil dari matkul
        $sks = $kurikulumMatkul?->sks ?? $matkul?->sks ?? 0;

        return response()->json([
            'kelas' => [
                'id' => $kelas->id,
                'nama' => $kelas->nama ?? '-',
                'kode_matkul' => $kodeMatkul,
                'nama_matkul' => $namaMatkul,
                'sks' => $sks,
                'prodi' => $kelas->prodi ? [
                    'id' => $kelas->prodi->id,
                    'nama' => $kelas->prodi->nama,
                    'jenjang' => $kelas->prodi->jenjang ? [
                        'id' => $kelas->prodi->jenjang->id,
                        'nama' => $kelas->prodi->jenjang->nama,
                        'kode' => $kelas->prodi->jenjang->kode ?? null,
                    ] : null,
                ] : null,
                'semester' => $kelas->semester ? [
                    'id' => $kelas->semester->id,
                    'kode' => $kelas->semester->kode,
                    'nama' => $kelas->semester->nama,
                ] : null,
            ],
            'jenis_penilaian' => $jenisPenilaianList->map(function ($jp) {
                return [
                    'id' => $jp->id,
                    'kode' => $jp->kode,
                    'nama' => $jp->nama,
                    'bobot' => $jp->bobot,
                ];
            }),
            'data' => $data,
        ]);
    }

    /**
     * Kalkulasi nilai kehadiran untuk kelas
     */
    public function kalkulasiNilaiKehadiran(Request $request, int $idKelas): JsonResponse
    {
        $user = $request->user();
        
        // Ambil data dosen dari user yang login (jika role dosen)
        $dosen = null;
        $idDosen = null;
        
        if ($user->role === 'dosen') {
            $dosen = Dosen::where('id_user', $user->id)->first();
            if ($dosen) {
                $idDosen = $dosen->id;
            }
        }
        
        // Ambil data kelas
        $kelas = Kelas::find($idKelas);
        if (!$kelas) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan'
            ], 404);
        }

        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }
        
        // Jika id_dosen belum ada (admin), ambil dari dosen PIC kelas
        if (!$idDosen && $kelas->id_dosen_pic) {
            $idDosen = $kelas->id_dosen_pic;
        }
        
        // Jika masih belum ada id_dosen, return error
        if (!$idDosen) {
            return response()->json([
                'message' => 'Tidak dapat menentukan dosen untuk menyimpan nilai. Pastikan kelas memiliki dosen PIC atau Anda login sebagai dosen.'
            ], 400);
        }

        // Cari jenis penilaian untuk kehadiran (biasanya dengan kode KEHADIRAN atau HADIR)
        $jenisPenilaianKehadiran = JenisPenilaian::whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('kode', 'PRESENSI')
                  ->orWhere('nama', 'like', '%presensi%')
                  ->orWhere('nama', 'like', '%kehadiran%');
            })
            ->first();

        if (!$jenisPenilaianKehadiran) {
            return response()->json([
                'message' => 'Jenis penilaian untuk kehadiran tidak ditemukan. Pastikan ada jenis penilaian dengan kode KEHADIRAN atau HADIR.'
            ], 404);
        }

        // Ambil semua jadwal untuk kelas ini
        $jadwalList = \App\Models\Jadwal::where('id_kelas', $idKelas)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($jadwalList)) {
            return response()->json([
                'message' => 'Belum ada jadwal untuk kelas ini.'
            ], 400);
        }

        // Ambil semua perkuliahan untuk jadwal-jadwal kelas ini
        $perkuliahanList = Perkuliahan::whereIn('id_jadwal', $jadwalList)
            ->whereNull('deleted_at')
            ->get();

        if ($perkuliahanList->isEmpty()) {
            return response()->json([
                'message' => 'Belum ada perkuliahan yang dilaksanakan untuk kelas ini.'
            ], 400);
        }

        $perkuliahanIds = $perkuliahanList->pluck('id')->toArray();
        $jumlahPerkuliahan = count($perkuliahanIds);

        // Ambil semua KRS untuk kelas ini
        $krsList = Krs::where('id_kelas', $idKelas)
            ->whereNull('deleted_at')
            ->get();

        if ($krsList->isEmpty()) {
            return response()->json([
                'message' => 'Belum ada mahasiswa yang mengambil kelas ini.'
            ], 400);
        }

        $krsIds = $krsList->pluck('id')->toArray();

        // Ambil semua kehadiran untuk perkuliahan di kelas ini
        $kehadiranList = Kehadiran::whereIn('id_perkuliahan', $perkuliahanIds)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('id_mhs')
            ->map(function ($items) {
                // Hitung jumlah hadir (status = 'hadir')
                return $items->where('status', 'hadir')->count();
            })
            ->toArray();

        // Kalkulasi dan simpan nilai kehadiran untuk setiap mahasiswa
        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($krsList as $krs) {
                $idMahasiswa = $krs->id_mahasiswa;
                $jumlahHadir = $kehadiranList[$idMahasiswa] ?? 0;
                
                // Hitung persentase kehadiran
                $persentaseKehadiran = $jumlahPerkuliahan > 0 
                    ? round(($jumlahHadir / $jumlahPerkuliahan) * 100, 2) 
                    : 0;

                // Cek apakah nilai komponen sudah ada
                $existingNilaiKomponen = DB::table('nilai_komponen')
                    ->where('id_krs', $krs->id)
                    ->where('id_jenis_penilaian', $jenisPenilaianKehadiran->id)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingNilaiKomponen) {
                    // Update nilai komponen
                    DB::table('nilai_komponen')
                        ->where('id', $existingNilaiKomponen->id)
                        ->update([
                            'nilai' => $persentaseKehadiran,
                            'updated_at' => now(),
                        ]);
                } else {
                    // Create nilai komponen baru
                    DB::table('nilai_komponen')->insert([
                        'id_krs' => $krs->id,
                        'id_jenis_penilaian' => $jenisPenilaianKehadiran->id,
                        'nilai' => $persentaseKehadiran,
                        'id_dosen' => $idDosen,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $successCount++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Kalkulasi nilai kehadiran berhasil.',
                'data' => [
                    'jumlah_mahasiswa' => $krsList->count(),
                    'jumlah_perkuliahan' => $jumlahPerkuliahan,
                    'berhasil' => $successCount,
                    'gagal' => $errorCount,
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Gagal melakukan kalkulasi nilai kehadiran: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store or update nilai komponen
     */
    public function storeNilaiKomponen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_krs' => ['required', 'integer', 'exists:krs,id'],
            'id_jenis_penilaian' => ['required', 'integer', 'exists:jenis_penilaian,id'],
            'nilai' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $user = $request->user();
        $dosen = \App\Models\Dosen::where('id_user', $user->id)->first();
        
        if (!$dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan'
            ], 404);
        }

        // Verifikasi bahwa dosen memiliki akses ke KRS ini
        $krs = Krs::with('kelas')->find($validated['id_krs']);
        if (!$krs) {
            return response()->json([
                'message' => 'KRS tidak ditemukan'
            ], 404);
        }

        $kelas = $krs->kelas;
        $hasAccess = false;
        if ($kelas->id_dosen_pic === $dosen->id) {
            $hasAccess = true;
        } else {
            $hasJadwal = \App\Models\JadwalDosen::whereHas('jadwal', function ($q) use ($kelas) {
                $q->where('id_kelas', $kelas->id);
            })
            ->where('id_dosen', $dosen->id)
            ->where('status', 'active')
            ->exists();

            if ($hasJadwal) {
                $hasAccess = true;
            }
        }

        if (!$hasAccess) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke kelas ini'
            ], 403);
        }

        // Cek apakah nilai komponen sudah ada
        $existingNilaiKomponen = DB::table('nilai_komponen')
            ->where('id_krs', $validated['id_krs'])
            ->where('id_jenis_penilaian', $validated['id_jenis_penilaian'])
            ->whereNull('deleted_at')
            ->first();

        if ($existingNilaiKomponen) {
            // Update nilai komponen
            DB::table('nilai_komponen')
                ->where('id', $existingNilaiKomponen->id)
                ->update([
                    'nilai' => $validated['nilai'],
                    'id_dosen' => $dosen->id,
                    'updated_at' => now(),
                ]);

            $nilaiKomponen = DB::table('nilai_komponen')
                ->where('id', $existingNilaiKomponen->id)
                ->first();
        } else {
            // Create nilai komponen baru
            $id = DB::table('nilai_komponen')->insertGetId([
                'id_krs' => $validated['id_krs'],
                'id_jenis_penilaian' => $validated['id_jenis_penilaian'],
                'nilai' => $validated['nilai'],
                'id_dosen' => $dosen->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $nilaiKomponen = DB::table('nilai_komponen')
                ->where('id', $id)
                ->first();
        }

        return response()->json([
            'id' => $nilaiKomponen->id,
            'id_krs' => $nilaiKomponen->id_krs,
            'id_jenis_penilaian' => $nilaiKomponen->id_jenis_penilaian,
            'nilai' => (float) $nilaiKomponen->nilai,
            'id_dosen' => $nilaiKomponen->id_dosen,
        ]);
    }

    public function show($idMahasiswa, Request $request): JsonResponse
    {
        $search = $request->get('search');
        $semesterId = $request->get('id_semester');

        // Ambil detail mahasiswa
        $mahasiswa = Mahasiswa::with([
            'prodi',
            'semester_masuk'
        ])->find($idMahasiswa);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data nilai mahasiswa ini.');
            }
        }

        // Query untuk mendapatkan KRS dengan nilai
        $query = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
        ])
        ->where('id_mahasiswa', $idMahasiswa)
        ->whereNull('krs.deleted_at');

        // Filter berdasarkan semester
        if ($semesterId) {
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        // Filter berdasarkan pencarian nama atau kode mata kuliah
        if ($search) {
            $query->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('kode', 'like', "%{$search}%");
            });
        }

        $krsList = $query->orderBy('created_at', 'desc')->get();

        // Ambil nilai untuk setiap KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        // Format data
        $nilaiList = $krsList->map(function ($krs) use ($nilaiMap) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;

            return [
                'id_krs' => $krs->id,
                'id_kelas' => $krs->id_kelas,
                'status' => $krs->status,
                'approved_at' => $krs->approved_at,
                'matkul' => $matkul ? [
                    'id' => $matkul->id,
                    'kode' => $matkul->kode,
                    'nama' => $matkul->nama,
                    'sks' => $matkul->sks,
                ] : null,
                'semester' => $semester ? [
                    'id' => $semester->id,
                    'kode' => $semester->kode,
                    'nama' => $semester->nama,
                ] : null,
                'nilai' => $nilai ? [
                    'id' => $nilai['id'],
                    'sks' => $nilai['sks'],
                    'angka_mutu' => $nilai['angka_mutu'],
                    'huruf_mutu' => $nilai['huruf_mutu'],
                    'is_final' => $nilai['is_final'],
                ] : null,
            ];
        });

        return response()->json([
            'mahasiswa' => [
                'id' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                    'kode' => $mahasiswa->prodi->kode ?? null,
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'nama' => $mahasiswa->semester_masuk->nama,
                    'kode' => $mahasiswa->semester_masuk->kode,
                ] : null,
            ],
            'nilai_list' => $nilaiList,
        ]);
    }

    /**
     * Get nilai untuk mahasiswa tertentu yang dikelompokkan berdasarkan semester
     */
    public function getNilaiBySemesterForMahasiswa(Request $request, $idMahasiswa): JsonResponse
    {
        // Ambil detail mahasiswa
        $mahasiswa = Mahasiswa::with([
            'prodi',
            'semester_masuk'
        ])->find($idMahasiswa);

        if (!$mahasiswa) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data nilai mahasiswa ini.');
            }
        }

        // Ambil semua KRS yang sudah disetujui untuk mahasiswa ini
        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic'
        ])
        ->where('id_mahasiswa', $idMahasiswa)
        ->whereNotNull('approved_at') // Hanya KRS yang sudah disetujui
        ->whereNull('deleted_at')
        ->orderBy('created_at', 'desc')
        ->get();

        // Ambil nilai untuk setiap KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        // Kelompokkan nilai berdasarkan semester
        $nilaiBySemester = [];
        
        foreach ($krsList as $krs) {
            $semester = $krs->kelas->semester;
            
            if (!$semester) {
                continue;
            }
            
            $semesterId = $semester->id;
            
            if (!isset($nilaiBySemester[$semesterId])) {
                $nilaiBySemester[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'nilai_list' => [],
                    'total_sks' => 0,
                    'total_angka_mutu' => 0,
                    'total_sks_dengan_nilai' => 0,
                ];
            }
            
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $sks = $matkul->sks ?? 0;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;
            
            $nilaiBySemester[$semesterId]['total_sks'] += $sks;
            
            if ($nilai) {
                $angkaMutu = $nilai['angka_mutu'] ?? 0;
                $nilaiBySemester[$semesterId]['total_angka_mutu'] += ($angkaMutu * $sks);
                $nilaiBySemester[$semesterId]['total_sks_dengan_nilai'] += $sks;
            }
            
            $nilaiBySemester[$semesterId]['nilai_list'][] = [
                'id' => $krs->id,
                'id_krs' => $krs->id,
                'id_kelas' => $krs->id_kelas,
                'matkul' => $matkul ? [
                    'id' => $matkul->id,
                    'kode' => $matkul->kode,
                    'nama' => $matkul->nama,
                    'sks' => $sks,
                ] : null,
                'kelas' => [
                    'id' => $krs->kelas->id ?? null,
                    'nama' => $krs->kelas->nama ?? null,
                ],
                'dosen' => [
                    'id' => $krs->kelas->dosenPic->id ?? null,
                    'nama' => $krs->kelas->dosenPic->nama ?? null,
                ],
                'nilai' => $nilai ? [
                    'id' => $nilai['id'],
                    'sks' => $nilai['sks'] ?? $sks,
                    'angka_mutu' => $nilai['angka_mutu'] ?? null,
                    'huruf_mutu' => $nilai['huruf_mutu'] ?? null,
                    'is_final' => $nilai['is_final'] ?? false,
                ] : null,
            ];
        }

        // Hitung IP (Indeks Prestasi) untuk setiap semester
        foreach ($nilaiBySemester as &$semesterData) {
            if ($semesterData['total_sks_dengan_nilai'] > 0) {
                $semesterData['ip'] = round($semesterData['total_angka_mutu'] / $semesterData['total_sks_dengan_nilai'], 2);
            } else {
                $semesterData['ip'] = 0;
            }
        }
        unset($semesterData);

        // Sort berdasarkan semester (terbaru dulu)
        usort($nilaiBySemester, function ($a, $b) {
            return $b['semester']['id'] <=> $a['semester']['id'];
        });

        return response()->json([
            'mahasiswa' => [
                'id' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                    'kode' => $mahasiswa->prodi->kode ?? null,
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'kode' => $mahasiswa->semester_masuk->kode,
                    'nama' => $mahasiswa->semester_masuk->nama,
                ] : null,
            ],
            'data' => array_values($nilaiBySemester)
        ]);
    }

    /**
     * Get nilai mahasiswa dikelompokkan per semester (scope prodi) - untuk halaman detail mahasiswa prodi.
     */
    public function getNilaiBySemesterForMahasiswaProdi(Request $request, $idMahasiswa): JsonResponse
    {
        $user = $request->user();
        $prodiScopeIds = $user && $user->hasProdiScope() ? $user->getProdiScopeIds() : [];
        if (empty($prodiScopeIds)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $mahasiswa = Mahasiswa::with(['prodi', 'semester_masuk'])->find($idMahasiswa);
        if (!$mahasiswa || !in_array($mahasiswa->id_prodi, $prodiScopeIds)) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic'
        ])
        ->where('id_mahasiswa', $idMahasiswa)
        ->whereNotNull('approved_at')
        ->whereNull('deleted_at')
        ->orderBy('created_at', 'desc')
        ->get();

        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)->whereNull('deleted_at')->get()->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        $nilaiBySemester = [];
        foreach ($krsList as $krs) {
            $semester = $krs->kelas->semester;
            if (!$semester) {
                continue;
            }
            $semesterId = $semester->id;
            if (!isset($nilaiBySemester[$semesterId])) {
                $nilaiBySemester[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'nilai_list' => [],
                    'total_sks' => 0,
                    'total_angka_mutu' => 0,
                    'total_sks_dengan_nilai' => 0,
                ];
            }
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $sks = $matkul->sks ?? 0;
            $nilai = $nilaiMap[$krs->id] ?? null;
            $nilaiBySemester[$semesterId]['total_sks'] += $sks;
            if ($nilai) {
                $angkaMutu = $nilai['angka_mutu'] ?? 0;
                $nilaiBySemester[$semesterId]['total_angka_mutu'] += ($angkaMutu * $sks);
                $nilaiBySemester[$semesterId]['total_sks_dengan_nilai'] += $sks;
            }
            $nilaiBySemester[$semesterId]['nilai_list'][] = [
                'id' => $krs->id,
                'id_krs' => $krs->id,
                'id_kelas' => $krs->id_kelas,
                'matkul' => $matkul ? [
                    'id' => $matkul->id,
                    'kode' => $matkul->kode,
                    'nama' => $matkul->nama,
                    'sks' => $sks,
                ] : null,
                'kelas' => [
                    'id' => $krs->kelas->id ?? null,
                    'nama' => $krs->kelas->nama ?? null,
                ],
                'dosen' => [
                    'id' => $krs->kelas->dosenPic->id ?? null,
                    'nama' => $krs->kelas->dosenPic->nama ?? null,
                ],
                'nilai' => $nilai ? [
                    'id' => $nilai['id'],
                    'sks' => $nilai['sks'] ?? $sks,
                    'angka_mutu' => $nilai['angka_mutu'] ?? null,
                    'huruf_mutu' => $nilai['huruf_mutu'] ?? null,
                    'is_final' => $nilai['is_final'] ?? false,
                ] : null,
            ];
        }

        foreach ($nilaiBySemester as &$semesterData) {
            $semesterData['ip'] = $semesterData['total_sks_dengan_nilai'] > 0
                ? round($semesterData['total_angka_mutu'] / $semesterData['total_sks_dengan_nilai'], 2)
                : 0;
        }
        unset($semesterData);

        usort($nilaiBySemester, function ($a, $b) {
            return $b['semester']['id'] <=> $a['semester']['id'];
        });

        return response()->json([
            'mahasiswa' => [
                'id' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                    'kode' => $mahasiswa->prodi->kode ?? null,
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'kode' => $mahasiswa->semester_masuk->kode,
                    'nama' => $mahasiswa->semester_masuk->nama,
                ] : null,
            ],
            'data' => array_values($nilaiBySemester),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_krs' => ['required', 'integer', 'exists:krs,id'],
            'sks' => ['nullable', 'integer', 'min:0', 'max:255'],
            'angka_mutu' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'huruf_mutu' => ['nullable', 'string', 'max:10'],
            'is_final' => ['nullable', 'boolean'],
            'keterangan_revisi' => ['nullable', 'string', 'max:2000'],
        ]);

        // Ambil KRS untuk mendapatkan SKS dari mata kuliah jika SKS tidak diisi
        $krs = Krs::with(['kelas.kurikulumMatkul.matkul', 'mahasiswa'])->find($validated['id_krs']);
        if (!$krs) {
            return response()->json(['message' => 'KRS tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $mahasiswa = $krs->mahasiswa ?? Mahasiswa::find($krs->id_mahasiswa);
            if ($mahasiswa) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke data nilai ini.');
                }
            }
        }

        // Jika SKS tidak diisi, ambil dari mata kuliah
        if (!isset($validated['sks']) && $krs->kelas && $krs->kelas->kurikulumMatkul && $krs->kelas->kurikulumMatkul->matkul) {
            $validated['sks'] = $krs->kelas->kurikulumMatkul->matkul->sks;
        }

        // Pastikan is_final selalu boolean (default false jika tidak diisi)
        // Cek dari request->all() yang sudah memproses JSON body
        $allData = $request->all();
        if (array_key_exists('is_final', $allData)) {
            $isFinal = $allData['is_final'];
            if (is_bool($isFinal)) {
                $validated['is_final'] = $isFinal;
            } else {
                $validated['is_final'] = filter_var($isFinal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($validated['is_final'] === null) {
                    $validated['is_final'] = false;
                }
            }
        } else {
            $validated['is_final'] = false;
        }

        try {
            $response = DB::transaction(function () use ($request, $validated) {
                // Cek apakah nilai sudah ada untuk KRS ini
                $existingNilai = Nilai::where('id_krs', $validated['id_krs'])
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingNilai) {
                    $existingIdKrs = $existingNilai->id_krs;

                    if (isset($validated['sks'])) {
                        $existingNilai->sks = $validated['sks'];
                    }
                    if (isset($validated['angka_mutu'])) {
                        $existingNilai->angka_mutu = $validated['angka_mutu'];
                    }
                    if (isset($validated['huruf_mutu'])) {
                        $existingNilai->huruf_mutu = $validated['huruf_mutu'];
                    }
                    if (isset($validated['is_final'])) {
                        $existingNilai->is_final = $validated['is_final'];
                    }

                    $existingNilai->id_krs = $existingIdKrs;
                    $existingNilai->save();
                    $existingNilai->refresh();

                    $this->upsertNilaiRevisiForAdmin($request, $existingNilai);

                    return response()->json($existingNilai);
                }

                // Unique id_krs tetap berlaku untuk baris soft-deleted; hindari duplicate dengan restore + update.
                $trashedNilai = Nilai::onlyTrashed()
                    ->where('id_krs', $validated['id_krs'])
                    ->first();
                if ($trashedNilai) {
                    $trashedNilai->restore();
                    $trashedNilai->deleted_by = null;

                    if (isset($validated['sks'])) {
                        $trashedNilai->sks = $validated['sks'];
                    }
                    if (isset($validated['angka_mutu'])) {
                        $trashedNilai->angka_mutu = $validated['angka_mutu'];
                    }
                    if (isset($validated['huruf_mutu'])) {
                        $trashedNilai->huruf_mutu = $validated['huruf_mutu'];
                    }
                    if (isset($validated['is_final'])) {
                        $trashedNilai->is_final = $validated['is_final'];
                    }

                    $trashedNilai->id_krs = $validated['id_krs'];
                    $trashedNilai->save();
                    $trashedNilai->refresh();

                    $this->upsertNilaiRevisiForAdmin($request, $trashedNilai);

                    return response()->json($trashedNilai);
                }

                $nilai = Nilai::create($validated);
                $nilai->refresh();
                $this->upsertNilaiRevisiForAdmin($request, $nilai);

                return response()->json($nilai, 201);
            });

            return $response;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal menyimpan nilai.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getByKrs(Request $request, $idKrs): JsonResponse
    {
        $nilai = Nilai::where('id_krs', $idKrs)
            ->whereNull('deleted_at')
            ->first();

        if (!$nilai) {
            return response()->json(['message' => 'Nilai tidak ditemukan'], 404);
        }

        $krs = Krs::with('mahasiswa')->find($nilai->id_krs);
        if ($krs) {
            $user = $request->user();
            if ($user && $user->hasScopeRestriction()) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && !in_array((int) $krs->mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke data nilai ini.');
                }
            }
        }

        return response()->json($nilai);
    }

    public function update(Request $request, $id): JsonResponse
    {
        // Termasuk soft-deleted: setelah hapus, klien mungkin masih mengirim PUT ke id yang sama; restore lalu update.
        $nilai = Nilai::withTrashed()->with('krs.mahasiswa')->find($id);
        if (!$nilai) {
            return response()->json(['message' => 'Nilai tidak ditemukan'], 404);
        }

        if ($nilai->trashed()) {
            $nilai->restore();
            $nilai->deleted_by = null;
            $nilai->save();
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $krs = $nilai->krs ?? Krs::with('mahasiswa')->find($nilai->id_krs);
            if ($krs && $krs->mahasiswa) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && !in_array((int) $krs->mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke data nilai ini.');
                }
            }
        }

        $validated = $request->validate([
            'sks' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:255'],
            'angka_mutu' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99'],
            'huruf_mutu' => ['sometimes', 'nullable', 'string', 'max:10'],
            'is_final' => ['sometimes', 'boolean'],
            'keterangan_revisi' => ['nullable', 'string', 'max:2000'],
        ]);

        // Pastikan is_final selalu diupdate jika ada di request (termasuk false)
        // Cek dari request->all() yang sudah memproses JSON body
        $allData = $request->all();
        if (array_key_exists('is_final', $allData)) {
            $isFinal = $allData['is_final'];
            // Konversi ke boolean dengan benar
            if (is_bool($isFinal)) {
                $validated['is_final'] = $isFinal;
            } else {
                $validated['is_final'] = filter_var($isFinal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($validated['is_final'] === null) {
                    $validated['is_final'] = false;
                }
            }
        }

        // Pastikan id_krs tetap ada (tidak diubah)
        // Simpan id_krs yang sudah ada untuk memastikan tidak hilang
        $existingIdKrs = $nilai->id_krs;

        // Build update data dengan memastikan id_krs selalu ada
        $updateData = [
            'id_krs' => $existingIdKrs, // Pastikan id_krs selalu ada
        ];

        if (isset($validated['sks'])) {
            $updateData['sks'] = $validated['sks'];
        }
        if (isset($validated['angka_mutu'])) {
            $updateData['angka_mutu'] = $validated['angka_mutu'];
        }
        if (isset($validated['huruf_mutu'])) {
            $updateData['huruf_mutu'] = $validated['huruf_mutu'];
        }
        if (array_key_exists('is_final', $validated)) {
            $updateData['is_final'] = $validated['is_final'];
        }

        try {
            DB::transaction(function () use ($request, $nilai, $updateData): void {
                $nilai->update($updateData);
                $nilai->refresh();
                $this->upsertNilaiRevisiForAdmin($request, $nilai);
            });

            $nilai->refresh();

            return response()->json($nilai);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal memperbarui nilai.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Hapus (soft delete) nilai beserta komponen & revisi terkait id_krs yang sama.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $nilai = Nilai::with('krs.mahasiswa')->find($id);

        if (! $nilai) {
            return response()->json(['message' => 'Nilai tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $krs = $nilai->krs ?? Krs::with('mahasiswa')->find($nilai->id_krs);
            if ($krs && $krs->mahasiswa) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $krs->mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke data nilai ini.');
                }
            }
        }

        $idKrs = (int) $nilai->id_krs;
        $deletedBy = $user ? ($user->name ?? (string) ($user->email ?? $user->id)) : 'system';

        try {
            DB::transaction(function () use ($nilai, $idKrs, $deletedBy): void {
                DB::table('nilai_komponen')
                    ->where('id_krs', $idKrs)
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => now(),
                        'deleted_by' => $deletedBy,
                        'updated_at' => now(),
                    ]);

                NilaiRevisi::where('id_krs', $idKrs)->whereNull('deleted_at')->delete();

                $nilai->deleted_by = $deletedBy;
                $nilai->save();
                $nilai->delete();
            });

            return response()->json(['message' => 'Nilai berhasil dihapus']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Gagal menghapus nilai.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Simpan baris di nilai_revisi setelah admin submit form nilai (sinkron dengan tabel nilai).
     * Memperbarui kolom revisi pada nilai (jumlah baris aktif di nilai_revisi untuk id_krs).
     */
    private function upsertNilaiRevisiForAdmin(Request $request, Nilai $nilai): void
    {
        $hurufRaw = $nilai->huruf_mutu;
        if ($hurufRaw === null || $hurufRaw === '') {
            return;
        }

        $huruf = strtoupper(trim((string) $hurufRaw));
        $keterangan = $request->input('keterangan_revisi');
        $user = $request->user();
        $by = $user ? ($user->name ?? (string) $user->id) : 'system';

        $rev = NilaiRevisi::withTrashed()
            ->where('id_krs', $nilai->id_krs)
            ->where('huruf_mutu', $huruf)
            ->first();

        if ($rev) {
            if ($rev->trashed()) {
                $rev->restore();
            }
            $rev->angka_mutu = $nilai->angka_mutu;
            $rev->keterangan = $keterangan;
            $rev->updated_by = $by;
            $rev->save();
        } else {
            NilaiRevisi::create([
                'id_krs' => $nilai->id_krs,
                'huruf_mutu' => $huruf,
                'angka_mutu' => $nilai->angka_mutu,
                'keterangan' => $keterangan,
                'created_by' => $by,
            ]);
        }

        $count = NilaiRevisi::where('id_krs', $nilai->id_krs)->whereNull('deleted_at')->count();
        $nilai->revisi = $count;
        $nilai->saveQuietly();
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'NIM*',
            'Kode Mata Kuliah*',
            'Kode Semester*',
            'Angka Mutu (Opsional)',
            'Huruf Mutu (Opsional)',
            'Is Final (true/false)',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(25);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(20);

        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

        // Add example row
        $exampleRow = [
            '2024001',
            'MK001',
            '20241',
            '85.5',
            'A',
            'true',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_nilai_' . date('YmdHis') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="' . $filename . '"',
            'Cache-Control' => 'max-age=0',
        ]);
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

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2; // +2 karena header di row 1 dan array 0-indexed

                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                $nim = trim($row[0] ?? '');
                $kodeMatkul = trim($row[1] ?? '');
                $kodeSemester = trim($row[2] ?? '');
                $angkaMutu = trim($row[3] ?? '');
                $hurufMutu = trim($row[4] ?? '');
                $isFinal = trim(strtolower($row[5] ?? 'false'));

                // Validate required fields
                if (empty($nim)) {
                    $errors[] = "Baris {$rowNumber}: NIM wajib diisi.";
                    continue;
                }

                if (empty($kodeMatkul)) {
                    $errors[] = "Baris {$rowNumber}: Kode Mata Kuliah wajib diisi.";
                    continue;
                }

                if (empty($kodeSemester)) {
                    $errors[] = "Baris {$rowNumber}: Kode Semester wajib diisi.";
                    continue;
                }

                // Find mahasiswa by NIM
                $mahasiswa = Mahasiswa::where('nim', $nim)->first();
                if (!$mahasiswa) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa dengan NIM '{$nim}' tidak ditemukan.";
                    continue;
                }

                // Find matkul by kode
                $matkul = Matkul::where('kode', $kodeMatkul)->first();
                if (!$matkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah dengan kode '{$kodeMatkul}' tidak ditemukan.";
                    continue;
                }

                // Find semester by kode
                $semester = Semester::where('kode', $kodeSemester)->first();
                if (!$semester) {
                    $errors[] = "Baris {$rowNumber}: Semester dengan kode '{$kodeSemester}' tidak ditemukan.";
                    continue;
                }

                // Find kurikulum_matkul by matkul
                $kurikulumMatkulList = KurikulumMatkul::where('id_matkul', $matkul->id)->get();
                if ($kurikulumMatkulList->isEmpty()) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah '{$kodeMatkul}' tidak ditemukan dalam kurikulum.";
                    continue;
                }

                // Find kelas by kurikulum_matkul and semester
                // Prioritize kelas dari prodi mahasiswa
                $kelas = Kelas::with('prodi')
                    ->whereIn('id_kurikulum_matkul', $kurikulumMatkulList->pluck('id'))
                    ->where('id_semester', $semester->id)
                    ->where('id_prodi', $mahasiswa->id_prodi)
                    ->first();

                // If not found, try without prodi filter
                if (!$kelas) {
                    $kelas = Kelas::with('prodi')
                        ->whereIn('id_kurikulum_matkul', $kurikulumMatkulList->pluck('id'))
                        ->where('id_semester', $semester->id)
                        ->first();
                }

                if (!$kelas) {
                    $errors[] = "Baris {$rowNumber}: Kelas dengan semester '{$kodeSemester}' dan mata kuliah '{$kodeMatkul}' tidak ditemukan.";
                    continue;
                }

                // Find KRS by mahasiswa and kelas
                $krs = Krs::where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_kelas', $kelas->id)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$krs) {
                    // Kelas yang cocok bisa saja berasal dari prodi lain (fallback query di atas
                    // tidak memfilter prodi) — sebutkan prodi kelasnya supaya jelas kenapa KRS
                    // mahasiswa tidak nyambung ke kelas ini, bukan cuma "tidak ditemukan".
                    $prodiKelas = $kelas->prodi->nama ?? null;
                    $errors[] = "Baris {$rowNumber}: KRS dengan NIM '{$nim}', mata kuliah '{$kodeMatkul}', dan semester '{$kodeSemester}' tidak ditemukan."
                        .($prodiKelas ? " Kelas yang cocok ditemukan pada prodi '{$prodiKelas}'." : '');
                    continue;
                }

                $user = $request->user();
                if ($user && $user->hasScopeRestriction()) {
                    $allowedProdiIds = $user->getAllowedProdiIds();
                    if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                        $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke mahasiswa NIM '{$nim}' (prodi di luar scope).";
                        continue;
                    }
                }

                // Prepare nilai data
                $nilaiData = [
                    'id_krs' => $krs->id,
                    'sks' => $matkul->sks ?? null,
                ];

                // Process angka_mutu
                if (!empty($angkaMutu)) {
                    $angkaMutuValue = filter_var($angkaMutu, FILTER_VALIDATE_FLOAT);
                    if ($angkaMutuValue === false) {
                        $errors[] = "Baris {$rowNumber}: Angka Mutu '{$angkaMutu}' tidak valid.";
                        continue;
                    }
                    $nilaiData['angka_mutu'] = $angkaMutuValue;
                }

                // Process huruf_mutu
                if (!empty($hurufMutu)) {
                    $nilaiData['huruf_mutu'] = strtoupper($hurufMutu);
                }

                // Process is_final
                $isFinalValue = filter_var($isFinal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isFinalValue === null) {
                    $isFinalValue = false;
                }
                $nilaiData['is_final'] = $isFinalValue;

                // Check if nilai already exists
                $existingNilai = Nilai::where('id_krs', $krs->id)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existingNilai) {
                    // Update existing nilai
                    $existingNilai->update($nilaiData);
                    $skipCount++;
                    $processedRows[] = [
                        'row' => $rowNumber,
                        'id' => $existingNilai->id,
                        'nim' => $nim,
                        'kode_matkul' => $kodeMatkul,
                        'action' => 'updated',
                    ];
                } else {
                    // Create new nilai
                    $nilai = Nilai::create($nilaiData);
                    $successCount++;
                    $processedRows[] = [
                        'row' => $rowNumber,
                        'id' => $nilai->id,
                        'nim' => $nim,
                        'kode_matkul' => $kodeMatkul,
                        'action' => 'created',
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Import selesai. Berhasil: {$successCount}, Diperbarui: {$skipCount}, Error: " . count($errors),
                'success_count' => $successCount,
                'updated_count' => $skipCount,
                'error_count' => count($errors),
                'errors' => $errors,
                'processed_rows' => $processedRows,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengimpor data: ' . $e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    /**
     * Get transkrip nilai untuk mahasiswa yang sedang login
     */
    public function getTranskripMahasiswa(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Ambil data mahasiswa dari user
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();
        
        if (!$mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan'
            ], 404);
        }

        // Ambil semua KRS yang sudah disetujui untuk mahasiswa ini
        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.semester',
            'kelas.prodi'
        ])
        ->where('id_mahasiswa', $mahasiswa->id)
        ->whereNotNull('approved_at') // Hanya KRS yang sudah disetujui
        ->whereNull('deleted_at')
        ->orderBy('created_at', 'asc')
        ->get();

        // Ambil nilai untuk setiap KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->where('is_final', true)
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        // Format data dan hitung statistik
        $transkripData = [];
        $totalSks = 0;
        $totalAngkaMutu = 0;
        $totalSksDenganNilai = 0;

        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;
            
            if (!$matkul || !$semester) {
                continue;
            }

            $sks = $matkul->sks ?? 0;
            $totalSks += $sks;

            $angkaMutu = null;
            $hurufMutu = null;
            $isFinal = false;
            
            if ($nilai) {
                $angkaMutu = $nilai['angka_mutu'];
                $hurufMutu = $nilai['huruf_mutu'];
                $isFinal = $nilai['is_final'] ?? false;
                
                // Hitung untuk IP (hanya yang sudah final)
                if ($isFinal && $angkaMutu !== null && $sks > 0) {
                    $totalAngkaMutu += ($angkaMutu * $sks);
                    $totalSksDenganNilai += $sks;
                }
            }

            $semesterId = $semester->id;
            if (!isset($transkripData[$semesterId])) {
                $transkripData[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'mata_kuliah' => [],
                    'total_sks' => 0,
                    'total_angka_mutu' => 0,
                    'total_sks_dengan_nilai' => 0,
                ];
            }

            $transkripData[$semesterId]['mata_kuliah'][] = [
                'id_krs' => $krs->id,
                'matkul' => [
                    'id' => $matkul->id,
                    'kode' => $matkul->kode,
                    'nama' => $matkul->nama,
                    'sks' => $sks,
                ],
                'nilai' => [
                    'angka_mutu' => $angkaMutu,
                    'huruf_mutu' => $hurufMutu,
                    'is_final' => $isFinal,
                ],
            ];

            $transkripData[$semesterId]['total_sks'] += $sks;
            if ($isFinal && $angkaMutu !== null) {
                $transkripData[$semesterId]['total_angka_mutu'] += ($angkaMutu * $sks);
                $transkripData[$semesterId]['total_sks_dengan_nilai'] += $sks;
            }
        }

        // Hitung IP per semester
        foreach ($transkripData as $semesterId => &$data) {
            if ($data['total_sks_dengan_nilai'] > 0) {
                $data['ip_semester'] = round($data['total_angka_mutu'] / $data['total_sks_dengan_nilai'], 2);
            } else {
                $data['ip_semester'] = null;
            }
        }

        // Hitung IP Kumulatif
        $ipKumulatif = null;
        if ($totalSksDenganNilai > 0) {
            $ipKumulatif = round($totalAngkaMutu / $totalSksDenganNilai, 2);
        }

        // Sort berdasarkan semester (terbaru dulu)
        usort($transkripData, function ($a, $b) {
            return $b['semester']['id'] <=> $a['semester']['id'];
        });

        return response()->json([
            'mahasiswa' => [
                'id' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                ] : null,
            ],
            'transkrip' => array_values($transkripData),
            'statistik' => [
                'total_sks' => $totalSks,
                'total_sks_dengan_nilai' => $totalSksDenganNilai,
                'ip_kumulatif' => $ipKumulatif,
            ],
        ]);
    }

    /**
     * Get data IP per semester untuk grafik dashboard mahasiswa
     */
    public function getIpPerSemester(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Ambil data mahasiswa dari user
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();
        
        if (!$mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan'
            ], 404);
        }

        // Ambil semua KRS yang sudah disetujui untuk mahasiswa ini
        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.semester',
        ])
        ->where('id_mahasiswa', $mahasiswa->id)
        ->whereNotNull('approved_at')
        ->whereNull('deleted_at')
        ->orderBy('created_at', 'asc')
        ->get();

        // Ambil nilai untuk setiap KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        // Kelompokkan berdasarkan semester dan hitung IP
        $ipBySemester = [];
        
        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;
            
            if (!$matkul || !$semester) {
                continue;
            }

            $sks = $matkul->sks ?? 0;
            $semesterId = $semester->id;
            
            if (!isset($ipBySemester[$semesterId])) {
                $ipBySemester[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'total_angka_mutu' => 0,
                    'total_sks_dengan_nilai' => 0,
                ];
            }

            if ($nilai && ($nilai['is_final'] ?? false) && $nilai['angka_mutu'] !== null && $sks > 0) {
                $ipBySemester[$semesterId]['total_angka_mutu'] += ($nilai['angka_mutu'] * $sks);
                $ipBySemester[$semesterId]['total_sks_dengan_nilai'] += $sks;
            }
        }

        // Hitung IP per semester
        $result = [];
        foreach ($ipBySemester as $semesterId => $data) {
            $ip = null;
            if ($data['total_sks_dengan_nilai'] > 0) {
                $ip = round($data['total_angka_mutu'] / $data['total_sks_dengan_nilai'], 2);
            }
            
            $result[] = [
                'semester' => $data['semester'],
                'ip' => $ip,
            ];
        }

        // Sort berdasarkan semester (terlama dulu untuk grafik)
        usort($result, function ($a, $b) {
            return $a['semester']['id'] <=> $b['semester']['id'];
        });

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Payload transkrip (KRS disetujui + nilai final) untuk satu mahasiswa — dipakai mahasiswa & dosen wali.
     *
     * @return array{mahasiswa: array, mata_kuliah: array<int, array>, statistik: array}
     */
    protected function buildTranskripLengkapPayload(Mahasiswa $mahasiswa): array
    {
        $mahasiswa->loadMissing('prodi');

        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.semester',
            'kelas.prodi',
        ])
            ->where('id_mahasiswa', $mahasiswa->id)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->get();

        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (! empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->where('is_final', true)
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        $mataKuliahList = [];
        $totalSks = 0;
        $totalAngkaMutu = 0;
        $totalSksDenganNilai = 0;

        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;

            if (! $matkul || ! $semester) {
                continue;
            }

            if (! $nilai || ! $nilai['huruf_mutu']) {
                continue;
            }

            $sks = $matkul->sks ?? 0;
            $totalSks += $sks;

            $angkaMutu = $nilai['angka_mutu'];
            $hurufMutu = $nilai['huruf_mutu'];
            $isFinal = $nilai['is_final'] ?? false;

            if ($isFinal && $angkaMutu !== null && $sks > 0) {
                $totalAngkaMutu += ($angkaMutu * $sks);
                $totalSksDenganNilai += $sks;
            }

            $mataKuliahList[] = [
                'id_krs' => $krs->id,
                'matkul' => [
                    'id' => $matkul->id,
                    'kode' => $matkul->kode,
                    'nama' => $matkul->nama,
                    'sks' => $sks,
                ],
                'semester' => [
                    'id' => $semester->id,
                    'kode' => $semester->kode,
                    'nama' => $semester->nama,
                ],
                'nilai' => [
                    'angka_mutu' => $angkaMutu,
                    'huruf_mutu' => $hurufMutu,
                    'is_final' => $isFinal,
                ],
            ];
        }

        usort($mataKuliahList, function ($a, $b) {
            $cmp = ($a['semester']['id'] ?? 0) <=> ($b['semester']['id'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['matkul']['kode'] ?? '', $b['matkul']['kode'] ?? '');
        });

        $ipk = null;
        if ($totalSksDenganNilai > 0) {
            $ipk = round($totalAngkaMutu / $totalSksDenganNilai, 2);
        }

        return [
            'mahasiswa' => [
                'id' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                ] : null,
            ],
            'mata_kuliah' => $mataKuliahList,
            'statistik' => [
                'total_sks' => $totalSks,
                'total_sks_dengan_nilai' => $totalSksDenganNilai,
                'ipk' => $ipk,
            ],
        ];
    }

    /**
     * Get transkrip lengkap untuk mahasiswa yang sedang login
     * Menampilkan semua mata kuliah tanpa pengelompokan semester
     */
    public function getTranskripLengkap(Request $request): JsonResponse
    {
        $user = $request->user();

        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (! $mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan',
            ], 404);
        }

        return response()->json($this->buildTranskripLengkapPayload($mahasiswa));
    }

    /**
     * Transkrip sementara mahasiswa bimbingan untuk dosen wali (sama sumber data dengan /transkrip mahasiswa).
     */
    public function getTranskripLengkapForBimbinganWali(Request $request, int $idMahasiswa): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $dosenWali = DosenWali::where('id_dosen', $dosen->id)
            ->where('id_mahasiswa', $idMahasiswa)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (! $dosenWali) {
            return response()->json([
                'message' => 'Mahasiswa bukan bimbingan Anda atau tidak aktif.',
            ], 404);
        }

        $mahasiswa = Mahasiswa::with('prodi')->find($idMahasiswa);

        if (! $mahasiswa) {
            return response()->json([
                'message' => 'Mahasiswa tidak ditemukan.',
            ], 404);
        }

        return response()->json($this->buildTranskripLengkapPayload($mahasiswa));
    }

    /**
     * Export nilai mahasiswa ke Excel
     */
    public function exportNilaiMahasiswa($idMahasiswa, Request $request): StreamedResponse
    {
        $search = $request->get('search');
        $semesterId = $request->get('id_semester');

        // Ambil detail mahasiswa
        $mahasiswa = Mahasiswa::with([
            'prodi',
            'semester_masuk'
        ])->find($idMahasiswa);

        if (!$mahasiswa) {
            throw new \Exception('Mahasiswa tidak ditemukan');
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data nilai mahasiswa ini.');
            }
        }

        // Query untuk mendapatkan KRS dengan nilai (sama seperti method show)
        $query = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
        ])
        ->where('id_mahasiswa', $idMahasiswa)
        ->whereNull('krs.deleted_at');

        // Filter berdasarkan semester
        if ($semesterId) {
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        // Filter berdasarkan pencarian nama atau kode mata kuliah
        if ($search) {
            $query->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('kode', 'like', "%{$search}%");
            });
        }

        $krsList = $query->orderBy('created_at', 'desc')->get();

        // Ambil nilai untuk setiap KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->where('is_final', true)
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        // Buat spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nilai Mahasiswa');

        // Header informasi mahasiswa
        $row = 1;
        $sheet->setCellValue('A' . $row, 'LAPORAN NILAI MAHASISWA');
        $sheet->mergeCells('A' . $row . ':F' . $row);
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
        $row++;

        $sheet->setCellValue('A' . $row, 'NIM:');
        $sheet->setCellValue('B' . $row, $mahasiswa->nim);
        $row++;

        $sheet->setCellValue('A' . $row, 'Nama:');
        $sheet->setCellValue('B' . $row, $mahasiswa->nama);
        $row++;

        $sheet->setCellValue('A' . $row, 'Program Studi:');
        $sheet->setCellValue('B' . $row, $mahasiswa->prodi?->nama ?? '-');
        $row++;

        $sheet->setCellValue('A' . $row, 'Semester Masuk:');
        $sheet->setCellValue('B' . $row, $mahasiswa->semester_masuk?->nama ?? '-');
        $row++;

        $sheet->setCellValue('A' . $row, 'Tanggal Export:');
        $sheet->setCellValue('B' . $row, date('d/m/Y H:i:s'));
        $row += 2;

        // Header tabel
        $headers = [
            'No',
            'Kode Mata Kuliah',
            'Nama Mata Kuliah',
            'SKS',
            'Semester',
            'Huruf Mutu',
            'Angka Mutu',
            'Status',
        ];
        $sheet->fromArray([$headers], null, 'A' . $row);

        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $lastHeaderCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A' . $row . ':' . $lastHeaderCol . $row)->applyFromArray($headerStyle);

        // Data rows
        $row++;
        $no = 1;
        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;

            $sheet->setCellValue('A' . $row, $no);
            $sheet->setCellValue('B' . $row, $matkul?->kode ?? '-');
            $sheet->setCellValue('C' . $row, $matkul?->nama ?? '-');
            $sheet->setCellValue('D' . $row, $matkul?->sks ?? '-');
            $sheet->setCellValue('E' . $row, $semester?->nama ?? '-');
            $sheet->setCellValue('F' . $row, $nilai && isset($nilai['huruf_mutu']) ? $nilai['huruf_mutu'] : '-');
            $sheet->setCellValue('G' . $row, $nilai && isset($nilai['angka_mutu']) ? $nilai['angka_mutu'] : '-');
            $statusNilai = '-';
            if ($nilai) {
                $statusNilai = (isset($nilai['is_final']) && $nilai['is_final']) ? 'Final' : 'Belum Final';
            } else {
                $statusNilai = 'Belum Ada Nilai';
            }
            $sheet->setCellValue('H' . $row, $statusNilai);

            // Center align untuk kolom tertentu
            $sheet->getStyle('A' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('H' . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            $row++;
            $no++;
        }

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(40);
        $sheet->getColumnDimension('D')->setWidth(8);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(15);
        $sheet->getColumnDimension('H')->setWidth(18);

        // Auto filter
        $sheet->setAutoFilter('A' . ($row - $no) . ':' . $lastHeaderCol . ($row - 1));

        $nimPart = trim((string) $mahasiswa->nim);
        $nimPart = str_replace([' ', "\t", "\n", "\r"], '_', $nimPart);
        $nimPart = trim($nimPart, '_');
        $filename = 'nilai_'.$nimPart.'_'.date('YmdHis').'.xlsx';
        $filename = preg_replace('/_+/', '_', $filename);
        $filename = trim($filename, '_');

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Export nilai mahasiswa ke PDF
     */
    public function exportNilaiMahasiswaPdf($idMahasiswa, Request $request)
    {
        $search = $request->get('search');
        $semesterId = $request->get('id_semester');

        // Ambil detail mahasiswa
        $mahasiswa = Mahasiswa::with([
            'prodi',
            'semester_masuk'
        ])->find($idMahasiswa);

        if (!$mahasiswa) {
            throw new \Exception('Mahasiswa tidak ditemukan');
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && !in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data nilai mahasiswa ini.');
            }
        }

        // Query untuk mendapatkan KRS dengan nilai (sama seperti method show)
        $query = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
        ])
        ->where('id_mahasiswa', $idMahasiswa)
        ->whereNull('krs.deleted_at');

        // Filter berdasarkan semester
        if ($semesterId) {
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        // Filter berdasarkan pencarian nama atau kode mata kuliah
        if ($search) {
            $query->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                  ->orWhere('kode', 'like', "%{$search}%");
            });
        }

        $krsList = $query->orderBy('created_at', 'desc')->get();

        // Ambil informasi semester yang dipilih (jika ada filter)
        $semesterFilter = null;
        if ($semesterId) {
            $semesterFilter = Semester::find($semesterId);
        } else {
            // Jika tidak ada filter, gunakan semester aktif
            $semesterFilter = Semester::where('is_active', true)->first();
        }

        // Ambil nilai untuk setiap KRS
        $krsIds = $krsList->pluck('id')->toArray();
        $nilaiMap = [];
        if (!empty($krsIds)) {
            $nilaiList = Nilai::whereIn('id_krs', $krsIds)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id_krs');
            $nilaiMap = $nilaiList->toArray();
        }

        // Konfigurasi kop surat (bisa diambil dari config atau database)

        $settingNamaPerguruanTinggi = Setting::where('key', 'app_univ_name')->first();
        $settingAlamatPerguruanTinggi = Setting::where('key', 'app_univ_address')->first();
        $settingEmailPerguruanTinggi = Setting::where('key', 'app_univ_email')->first();
        $settingWebsitePerguruanTinggi = Setting::where('key', 'app_univ_website')->first();
        $settingLogoPerguruanTinggi = Setting::where('key', 'app_univ_logo')->first();
        $settingYayasanPerguruanTinggi = Setting::where('key', 'app_univ_yayasan')->first();

        $namaPerguruanTinggi = $settingNamaPerguruanTinggi ? $settingNamaPerguruanTinggi->value : '';
        $alamatPerguruanTinggi = $settingAlamatPerguruanTinggi ? $settingAlamatPerguruanTinggi->value : '';
        $emailPerguruanTinggi = $settingEmailPerguruanTinggi ? $settingEmailPerguruanTinggi->value : '';
        $websitePerguruanTinggi = $settingWebsitePerguruanTinggi ? $settingWebsitePerguruanTinggi->value : '';
        $yayasanPerguruanTinggi = $settingYayasanPerguruanTinggi ? $settingYayasanPerguruanTinggi->value : '';

        // Hitung statistik
        $totalSks = 0;
        $totalAngkaMutu = 0;
        $totalSksDenganNilai = 0;

        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;
            
            $sks = $matkul?->sks ?? 0;
            $totalSks += $sks;

            // Hanya hitung yang sudah final
            if ($nilai && isset($nilai['is_final']) && $nilai['is_final'] && isset($nilai['angka_mutu']) && $nilai['angka_mutu'] !== null) {
                $angkaMutu = (float) $nilai['angka_mutu'];
                $totalAngkaMutu += $angkaMutu * $sks;
                $totalSksDenganNilai += $sks;
            }
        }

        // Hitung IPK
        $ipk = $totalSksDenganNilai > 0 ? number_format($totalAngkaMutu / $totalSksDenganNilai, 2) : '-';
        $totalAngkaMutuFormatted = $totalAngkaMutu > 0 ? number_format($totalAngkaMutu, 2) : '0.00';

        // Hitung semester ditempuh menggunakan SemesterService
        $semesterDitempuh = SemesterService::hitungSemesterDitempuhDenganAktif(
            $mahasiswa->semester_masuk,
            $semesterId
        );

        // Buat HTML untuk PDF
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 10mm 15mm;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.4;
        }
        .kop-surat {
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
            text-align: center;
        }
        .kop-surat h1 {
            margin: 0;
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .kop-surat p {
            margin: 2px 0;
            font-size: 10pt;
        }
        .info-mahasiswa {
            margin-bottom: 20px;
        }
        .info-mahasiswa h2 {
            font-size: 14pt;
            margin-bottom: 10px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
        }
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 5px;
        }
        .info-label {
            display: table-cell;
            width: 150px;
            font-weight: bold;
        }
        .info-value {
            display: table-cell;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th {
            background-color: #4472C4;
            color: white;
            padding: 8px;
            text-align: center;
            border: 1px solid #000;
            font-size: 10pt;
        }
        td {
            padding: 6px;
            border: 1px solid #000;
            font-size: 10pt;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .text-center {
            text-align: center;
        }
        .footer {
            margin-top: 30px;
            text-align: right;
            font-size: 10pt;
        }
    </style>
</head>
<body>
    <table style="width: 100%; border: none !important;" border="0">
        <tr style="border: none !important;">
            <td style="vertical-align: middle; border: none !important;" width="100px">
                <img src="' . htmlspecialchars($settingLogoPerguruanTinggi?->value ?? '') . '" alt="' . htmlspecialchars($namaPerguruanTinggi) . '" style="width: 100px; height: 100px;">
            </td>
            <td style="text-align: center; vertical-align: middle; border: none !important;">
                <p style="font-size: 12pt; font-weight: bold;">' . htmlspecialchars($yayasanPerguruanTinggi) . '</p>
                <h1 style="font-size: 18pt; font-weight: bold; margin: 0;">' . htmlspecialchars($namaPerguruanTinggi) . '</h1>

                ' . htmlspecialchars($alamatPerguruanTinggi) . '<br>

                Email: ' . htmlspecialchars($emailPerguruanTinggi) . '<br>

                Website: ' . htmlspecialchars($websitePerguruanTinggi) . '<br>
            </td>
        </tr>
    </table>

    <div class="info-mahasiswa">
        <h2>LAPORAN NILAI MAHASISWA</h2>
        <div class="info-row">
            <div class="info-label">NIM:</div>
            <div class="info-value">' . htmlspecialchars($mahasiswa->nim) . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Nama:</div>
            <div class="info-value">' . htmlspecialchars($mahasiswa->nama) . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Program Studi:</div>
            <div class="info-value">' . htmlspecialchars($mahasiswa->prodi?->nama ?? '-') . ' '. htmlspecialchars($mahasiswa->prodi?->jenjang?->kode ?? '-') .'</div>
        </div>
        <div class="info-row">
            <div class="info-label">Semester:</div>
            <div class="info-value">' . htmlspecialchars($semesterFilter ? $semesterFilter->nama : 'Semua Semester') . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Semester ditempuh:</div>
            <div class="info-value">' . ($semesterDitempuh !== null ? $semesterDitempuh : '-') . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Total SKS:</div>
            <div class="info-value">' . $totalSks . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Total Angka Mutu:</div>
            <div class="info-value">' . $totalAngkaMutuFormatted . ' (' . $totalSksDenganNilai . ' SKS dengan nilai final)</div>
        </div>
        <div class="info-row">
            <div class="info-label">IPK:</div>
            <div class="info-value">' . $ipk . '</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 15%;">Kode Mata Kuliah</th>
                <th style="width: 30%;">Nama Mata Kuliah</th>
                <th style="width: 8%;">SKS</th>
                <th style="width: 15%;">Semester</th>
                <th style="width: 12%;">Huruf Mutu</th>
                <th style="width: 10%;">Angka Mutu</th>
                <th style="width: 15%;">Status</th>
            </tr>
        </thead>
        <tbody>';

        $no = 1;
        foreach ($krsList as $krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $nilai = isset($nilaiMap[$krs->id]) ? $nilaiMap[$krs->id] : null;

            $hurufMutu = $nilai && isset($nilai['huruf_mutu']) ? htmlspecialchars($nilai['huruf_mutu']) : '-';
            $angkaMutu = $nilai && isset($nilai['angka_mutu']) ? number_format($nilai['angka_mutu'], 2) : '-';
            $statusNilai = '-';
            if ($nilai) {
                $statusNilai = (isset($nilai['is_final']) && $nilai['is_final']) ? 'Final' : 'Belum Final';
            } else {
                $statusNilai = 'Belum Ada Nilai';
            }

            $html .= '<tr>
                <td class="text-center">' . $no . '</td>
                <td>' . htmlspecialchars($matkul?->kode ?? '-') . '</td>
                <td>' . htmlspecialchars($matkul?->nama ?? '-') . '</td>
                <td class="text-center">' . ($matkul?->sks ?? '-') . '</td>
                <td>' . htmlspecialchars($semester?->nama ?? '-') . '</td>
                <td class="text-center">' . $hurufMutu . '</td>
                <td class="text-center">' . $angkaMutu . '</td>
                <td class="text-center">' . htmlspecialchars($statusNilai) . '</td>
            </tr>';

            $no++;
        }

        if ($krsList->isEmpty()) {
            $html .= '<tr>
                <td colspan="8" class="text-center">Belum ada data nilai</td>
            </tr>';
        }

        $html .= '</tbody>
    </table>

    <div class="footer">
        <p>Dicetak pada: ' . date('d/m/Y H:i:s') . '</p>
    </div>
</body>
</html>';

        // Setup dompdf
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'nilai_' . str_replace(' ', '_', $mahasiswa->nim) . '_' . date('YmdHis') . '.pdf';

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
