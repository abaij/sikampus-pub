<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\KelompokKelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\MateriPerkuliahan;
use App\Models\Perkuliahan;
use App\Models\Ruangan;
use App\Models\Semester;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PerkuliahanController extends Controller
{
    /**
     * Get perkuliahan by kelas ID (untuk dosen yang sedang login)
     */
    public function getByKelas(Request $request, int $kelasId): JsonResponse
    {
        $user = $request->user();

        // Ambil data dosen dari user
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        // Verifikasi bahwa dosen memiliki akses ke kelas ini
        $kelas = Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi',
            'prodi.jenjang',
            'semester',
        ])->find($kelasId);

        if (! $kelas) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan',
            ], 404);
        }

        // Cek apakah dosen adalah dosen PIC atau memiliki jadwal di kelas ini
        $hasAccess = false;
        if ($kelas->id_dosen_pic === $dosen->id) {
            $hasAccess = true;
        } else {
            // Cek apakah dosen memiliki jadwal di kelas ini
            $hasJadwal = JadwalDosen::whereHas('jadwal', function ($q) use ($kelasId) {
                $q->where('id_kelas', $kelasId);
            })
                ->where('id_dosen', $dosen->id)
                ->where('status', 'active')
                ->exists();

            if ($hasJadwal) {
                $hasAccess = true;
            }
        }

        if (! $hasAccess) {
            $hasAccess = KelasDosen::where('id_dosen', $dosen->id)
                ->where('id_kelas', $kelasId)
                ->whereNull('deleted_at')
                ->exists();
        }

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke kelas ini',
            ], 403);
        }

        // Ambil semua jadwal untuk kelas ini
        $jadwalIds = Jadwal::where('id_kelas', $kelasId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($jadwalIds)) {
            return response()->json([
                'kelas' => $kelas,
                'perkuliahan' => [],
            ]);
        }

        // Ambil data perkuliahan dengan materi untuk jadwal-jadwal kelas ini
        $perkuliahan = Perkuliahan::with('materiPerkuliahan')
            ->whereIn('id_jadwal', $jadwalIds)
            ->orderByDesc('waktu_mulai')
            ->orderByDesc('id')
            ->get()
            ->map(function ($item) {
                $baseUrl = config('app.url');
                $item->materiPerkuliahan = $item->materiPerkuliahan->map(function ($materi) use ($baseUrl) {
                    $url = $baseUrl.'/storage/'.$materi->file;

                    return [
                        ...$materi->toArray(),
                        'url' => $url,
                    ];
                });

                return $item;
            });

        return response()->json([
            'kelas' => $kelas,
            'perkuliahan' => $perkuliahan,
        ]);
    }

    /**
     * Store new perkuliahan
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $validated = $request->validate([
            'id_kelas' => ['required', 'integer', 'exists:kelas,id'],
            'id_jadwal' => ['required', 'integer', 'exists:jadwal,id'],
            'materi' => ['nullable', 'string', 'max:65535'],
        ]);

        // Verifikasi akses dosen ke kelas
        $kelas = Kelas::find($validated['id_kelas']);
        if (! $kelas) {
            return response()->json([
                'message' => 'Kelas tidak ditemukan',
            ], 404);
        }

        $hasAccess = false;
        if ($kelas->id_dosen_pic === $dosen->id) {
            $hasAccess = true;
        } else {
            $hasJadwal = JadwalDosen::whereHas('jadwal', function ($q) use ($validated) {
                $q->where('id_kelas', $validated['id_kelas']);
            })
                ->where('id_dosen', $dosen->id)
                ->where('status', 'active')
                ->exists();

            if ($hasJadwal) {
                $hasAccess = true;
            }
        }

        if (! $hasAccess) {
            $hasAccess = KelasDosen::where('id_dosen', $dosen->id)
                ->where('id_kelas', $validated['id_kelas'])
                ->whereNull('deleted_at')
                ->exists();
        }

        if (! $hasAccess) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke kelas ini',
            ], 403);
        }

        $jadwalId = (int) $validated['id_jadwal'];
        $jadwal = Jadwal::where('id', $jadwalId)
            ->where('id_kelas', $validated['id_kelas'])
            ->whereNull('deleted_at')
            ->first();

        if (! $jadwal) {
            return response()->json([
                'message' => 'Jadwal tidak termasuk kelas ini',
            ], 422);
        }

        if (! $this->dosenBisaAksesSlotJadwal($dosen, $kelas, $jadwal)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke slot jadwal ini',
            ], 403);
        }

        $ongoing = Perkuliahan::where('id_jadwal', $jadwal->id)
            ->whereNotNull('waktu_mulai')
            ->whereNull('waktu_selesai')
            ->whereNull('deleted_at')
            ->exists();

        if ($ongoing) {
            return response()->json([
                'message' => 'Masih ada sesi yang berlangsung untuk slot jadwal ini. Akhiri sesi terlebih dahulu.',
            ], 422);
        }

        $materi = $validated['materi'] ?? null;
        if (is_string($materi) && trim($materi) === '') {
            $materi = null;
        }

        $perkuliahan = DB::transaction(function () use ($jadwal, $dosen, $user, $materi) {
            $this->ensureJadwalDosenAktif((int) $jadwal->id, (int) $dosen->id);

            $attrs = [
                'id_jadwal' => $jadwal->id,
                'tanggal' => Carbon::now()->toDateString(),
                'waktu_mulai' => now(),
                'waktu_selesai' => null,
                'materi' => $materi,
                'created_by' => $this->namaLengkapDosen($dosen, $user),
            ];

            return Perkuliahan::create($attrs);
        });

        return response()->json($perkuliahan->load('dosen:id,nama,gelar_depan,gelar_belakang'), 201);
    }

    /**
     * Update perkuliahan
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $perkuliahan = Perkuliahan::with('jadwal.kelas')->find($id);

        if (! $perkuliahan || ! $perkuliahan->jadwal || ! $perkuliahan->jadwal->kelas) {
            return response()->json([
                'message' => 'Perkuliahan tidak ditemukan',
            ], 404);
        }

        if (! $this->dosenBisaAksesPerkuliahan($dosen, $perkuliahan)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke jadwal ini',
            ], 403);
        }

        $validated = $request->validate([
            'materi' => ['nullable', 'string', 'max:65535'],
            'waktu_mulai' => ['sometimes', 'nullable', 'date'],
            'waktu_selesai' => ['sometimes', 'nullable', 'date'],
            'realisasi_materi' => ['nullable', 'string', 'max:65535'],
        ]);

        if (array_key_exists('waktu_selesai', $validated) && $validated['waktu_selesai'] !== null) {
            $mulai = isset($validated['waktu_mulai'])
                ? Carbon::parse($validated['waktu_mulai'])
                : $perkuliahan->waktu_mulai;
            if (! $mulai || Carbon::parse($validated['waktu_selesai'])->lte($mulai)) {
                return response()->json([
                    'message' => 'Waktu selesai harus setelah waktu mulai',
                ], 422);
            }
        }

        if (array_key_exists('materi', $validated) && is_string($validated['materi']) && trim($validated['materi']) === '') {
            $validated['materi'] = null;
        }

        $perkuliahan->update($validated);

        return response()->json($perkuliahan);
    }

    /**
     * Akhiri sesi perkuliahan (waktu_selesai = sekarang).
     */
    public function selesaiSesi(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $perkuliahan = Perkuliahan::with('jadwal.kelas')->find($id);

        if (! $perkuliahan || ! $perkuliahan->jadwal || ! $perkuliahan->jadwal->kelas) {
            return response()->json([
                'message' => 'Perkuliahan tidak ditemukan',
            ], 404);
        }

        if (! $this->dosenBisaAksesPerkuliahan($dosen, $perkuliahan)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke jadwal ini',
            ], 403);
        }

        if (! $perkuliahan->waktu_mulai) {
            return response()->json([
                'message' => 'Perkuliahan belum dimulai',
            ], 422);
        }

        if ($perkuliahan->waktu_selesai) {
            return response()->json([
                'message' => 'Sesi sudah diakhiri',
            ], 422);
        }

        $validated = $request->validate([
            'realisasi_materi' => ['nullable', 'string', 'max:65535'],
        ]);

        $realisasi = $validated['realisasi_materi'] ?? null;
        if (is_string($realisasi) && trim($realisasi) === '') {
            $realisasi = null;
        }

        $perkuliahan->waktu_selesai = now();
        $perkuliahan->realisasi_materi = $realisasi;
        $perkuliahan->save();

        return response()->json($perkuliahan);
    }

    /**
     * Delete perkuliahan
     */
    public function destroy(int $id): JsonResponse
    {
        $user = request()->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $perkuliahan = Perkuliahan::with('jadwal.kelas')->find($id);

        if (! $perkuliahan || ! $perkuliahan->jadwal || ! $perkuliahan->jadwal->kelas) {
            return response()->json([
                'message' => 'Perkuliahan tidak ditemukan',
            ], 404);
        }

        if (! $this->dosenBisaAksesPerkuliahan($dosen, $perkuliahan)) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke jadwal ini',
            ], 403);
        }

        $perkuliahan->delete();

        return response()->json([
            'message' => 'Perkuliahan berhasil dihapus',
        ]);
    }

    /**
     * Get count of pertemuan by kelas IDs (batch)
     */
    public function getPertemuanCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $kelasIds = $request->get('kelas_ids');

        if (! $kelasIds || ! is_array($kelasIds)) {
            return response()->json([
                'counts' => [],
            ]);
        }

        // Verifikasi bahwa dosen memiliki akses ke semua kelas ini
        $accessibleKelasIds = [];
        foreach ($kelasIds as $kelasId) {
            $kelas = Kelas::find($kelasId);
            if (! $kelas) {
                continue;
            }

            $hasAccess = false;
            if ($kelas->id_dosen_pic === $dosen->id) {
                $hasAccess = true;
            } else {
                $hasJadwal = JadwalDosen::whereHas('jadwal', function ($q) use ($kelasId) {
                    $q->where('id_kelas', $kelasId);
                })
                    ->where('id_dosen', $dosen->id)
                    ->where('status', 'active')
                    ->exists();

                if ($hasJadwal) {
                    $hasAccess = true;
                }
            }

            if ($hasAccess) {
                $accessibleKelasIds[] = $kelasId;
            }
        }

        // Ambil semua jadwal untuk kelas-kelas yang dapat diakses
        $jadwalIds = Jadwal::whereIn('id_kelas', $accessibleKelasIds)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        // Get counts berdasarkan jadwal
        $countsByJadwal = Perkuliahan::whereIn('id_jadwal', $jadwalIds)
            ->whereNull('deleted_at')
            ->with('jadwal:id,id_kelas')
            ->get()
            ->groupBy('jadwal.id_kelas')
            ->map(function ($items) {
                return $items->count();
            })
            ->toArray();

        // Format response
        $result = [];
        foreach ($kelasIds as $kelasId) {
            $result[$kelasId] = $countsByJadwal[$kelasId] ?? 0;
        }

        return response()->json([
            'counts' => $result,
        ]);
    }

    /**
     * Get list of perkuliahan yang sudah dibuat oleh dosen (untuk kehadiran)
     */
    public function getMyPerkuliahan(Request $request): JsonResponse
    {
        $user = $request->user();
        $dosen = Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $idSemesterMasuk = $request->get('id_semester_masuk');

        // Jika tidak ada parameter, gunakan semester aktif
        if (! $idSemesterMasuk) {
            $activeSemester = Semester::where('is_active', true)->first();
            if ($activeSemester) {
                $idSemesterMasuk = $activeSemester->id;
            }
        }

        // Ambil semua kelas yang diakses dosen dengan filter semester masuk
        // Cara 1: Kelas dimana dosen adalah PIC dan memiliki jadwal di semester yang dipilih
        $kelasAsPIC = Kelas::where('id_dosen_pic', $dosen->id)
            ->when($idSemesterMasuk, function ($q) use ($idSemesterMasuk) {
                $q->where('id_semester', $idSemesterMasuk);
            })
            ->pluck('id')
            ->toArray();

        // Cara 2: Kelas dimana dosen memiliki jadwal aktif di semester yang dipilih
        $jadwalDosenQuery = JadwalDosen::where('id_dosen', $dosen->id)
            ->where('status', 'active');

        if ($idSemesterMasuk) {
            $jadwalDosenQuery->whereHas('jadwal.kelas', function ($q) use ($idSemesterMasuk) {
                $q->where('id_semester', $idSemesterMasuk);
            });
        }

        $kelasWithJadwal = $jadwalDosenQuery
            ->with('jadwal:id_kelas')
            ->get()
            ->pluck('jadwal.id_kelas')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // Gabungkan dan hapus duplikat
        $kelasIds = array_unique(array_merge($kelasAsPIC, $kelasWithJadwal));

        // Jika tidak ada kelas yang sesuai, return empty
        if (empty($kelasIds)) {
            $activeSemester = Semester::where('is_active', true)->first();
            $selectedSemester = $idSemesterMasuk
                ? Semester::find($idSemesterMasuk)
                : $activeSemester;

            return response()->json([
                'semester' => $selectedSemester ? [
                    'id' => $selectedSemester->id,
                    'kode' => $selectedSemester->kode,
                    'nama' => $selectedSemester->nama,
                ] : null,
                'data' => [],
            ]);
        }

        // Ambil semua jadwal untuk kelas-kelas tersebut
        $jadwalQuery = Jadwal::whereIn('id_kelas', $kelasIds)
            ->whereNull('deleted_at');

        if ($idSemesterMasuk) {
            $jadwalQuery->whereHas('kelas', function ($q) use ($idSemesterMasuk) {
                $q->where('id_semester', $idSemesterMasuk);
            });
        }

        $jadwalIds = $jadwalQuery->pluck('id')->toArray();

        // Ambil perkuliahan dari jadwal-jadwal tersebut
        // Perkuliahan menggunakan id_jadwal (bukan id_kelas)
        $perkuliahanQuery = Perkuliahan::with([
            'jadwal.kelas.kurikulumMatkul.matkul',
            'jadwal.kelas.kurikulumMatkul.kurikulum',
            'jadwal.kelas.prodi',
            'jadwal.kelas.prodi.jenjang',
            'jadwal.kelas.semester',
        ])
            ->whereIn('id_jadwal', $jadwalIds)
            ->whereNull('deleted_at');

        $perkuliahan = $perkuliahanQuery
            ->orderByRaw('waktu_mulai IS NULL')
            ->orderBy('waktu_mulai', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Kelompokkan perkuliahan berdasarkan kelas (id_kelas)
        $groupedData = $perkuliahan->groupBy(function ($item) {
            return $item->jadwal->id_kelas;
        })->map(function ($perkuliahanGroup, $kelasId) {
            // Ambil data kelas dari perkuliahan pertama (semua perkuliahan dalam grup memiliki kelas yang sama)
            $firstPerkuliahan = $perkuliahanGroup->first();
            $kelas = $firstPerkuliahan->jadwal->kelas;
            $kurikulumMatkul = $kelas->kurikulumMatkul;

            // Format data kelas
            // Jika kode_matkul atau nama_matkul kosong/null, ambil dari tabel matkul
            $kodeMatkul = $kurikulumMatkul?->kode_matkul;
            if (empty($kodeMatkul) && $kurikulumMatkul?->matkul?->kode) {
                $kodeMatkul = $kurikulumMatkul->matkul->kode;
            }

            $namaMatkul = $kurikulumMatkul?->nama_matkul;
            if (empty($namaMatkul) && $kurikulumMatkul?->matkul?->nama) {
                $namaMatkul = $kurikulumMatkul->matkul->nama;
            }

            $sks = $kurikulumMatkul?->sks;
            if (empty($sks) && $kurikulumMatkul?->matkul?->sks) {
                $sks = $kurikulumMatkul->matkul->sks;
            }

            $kelasData = [
                'id' => $kelas->id,
                'id_kurikulum_matkul' => $kelas->id_kurikulum_matkul,
                'id_prodi' => $kelas->id_prodi,
                'id_semester' => $kelas->id_semester,
                'id_dosen_pic' => $kelas->id_dosen_pic,
                'kode_matkul' => $kodeMatkul,
                'nama_matkul' => $namaMatkul,
                'sks' => $sks,
                'prodi' => $kelas->prodi ? [
                    'id' => $kelas->prodi->id,
                    'nama' => $kelas->prodi->nama,
                    'kode' => $kelas->prodi->kode,
                    'jenjang' => $kelas->prodi->jenjang ? [
                        'id' => $kelas->prodi->jenjang->id,
                        'kode' => $kelas->prodi->jenjang->kode,
                        'nama' => $kelas->prodi->jenjang->nama,
                    ] : null,
                ] : null,
                'semester' => $kelas->semester ? [
                    'id' => $kelas->semester->id,
                    'kode' => $kelas->semester->kode,
                    'nama' => $kelas->semester->nama,
                ] : null,
            ];

            // Hitung jumlah mahasiswa untuk kelas ini
            $jumlahMahasiswa = Krs::where('id_kelas', $kelasId)
                ->whereNull('deleted_at')
                ->selectRaw('COUNT(DISTINCT id_mahasiswa) as count')
                ->value('count') ?? 0;

            // Ambil semua ID perkuliahan untuk kelas ini
            $perkuliahanIds = $perkuliahanGroup->pluck('id')->toArray();

            // Hitung jumlah hadir per perkuliahan
            $jumlahHadirPerPerkuliahan = [];
            if (! empty($perkuliahanIds)) {
                $kehadiranCounts = Kehadiran::whereIn('id_perkuliahan', $perkuliahanIds)
                    ->whereNull('deleted_at')
                    ->where('status', 'hadir')
                    ->selectRaw('id_perkuliahan, COUNT(DISTINCT id_mhs) as jumlah_hadir')
                    ->groupBy('id_perkuliahan')
                    ->pluck('jumlah_hadir', 'id_perkuliahan')
                    ->toArray();

                foreach ($perkuliahanIds as $perkuliahanId) {
                    $jumlahHadirPerPerkuliahan[$perkuliahanId] = $kehadiranCounts[$perkuliahanId] ?? 0;
                }
            }

            // Format data perkuliahan (tanpa nested jadwal.kelas untuk menghindari duplikasi)
            // Collection::sortBy([$closure, $closure]) memanggil tiap closure sebagai comparator
            // dua-argumen ($a, $b), bukan sebagai pengambil nilai — closure satu-argumen di sini
            // diam-diam menghasilkan urutan yang salah. Satu closure yang mengembalikan array
            // kunci majemuk aman untuk perbandingan array bawaan PHP.
            $sortedGroup = $perkuliahanGroup
                ->sortBy(fn ($item) => [$item->waktu_mulai?->getTimestamp() ?? \PHP_INT_MAX, $item->id])
                ->values();

            $perkuliahanData = $sortedGroup->map(function ($item, $idx) use ($jumlahMahasiswa, $jumlahHadirPerPerkuliahan) {
                return [
                    'id' => $item->id,
                    'id_jadwal' => $item->id_jadwal,
                    'pertemuan_ke' => $idx + 1,
                    'tanggal' => $item->waktu_mulai?->format('Y-m-d'),
                    'materi' => $item->materi,
                    'jumlah_mahasiswa' => $jumlahMahasiswa,
                    'jumlah_hadir' => $jumlahHadirPerPerkuliahan[$item->id] ?? 0,
                    'jadwal' => [
                        'id' => $item->jadwal->id,
                        'hari' => $item->jadwal->hari,
                        'jam_mulai' => $item->jadwal->jam_mulai,
                        'jam_selesai' => $item->jadwal->jam_selesai,
                        'id_ruangan' => $item->jadwal->id_ruangan,
                    ],
                ];
            })->values();

            return [
                'kelas' => $kelasData,
                'perkuliahan' => $perkuliahanData,
            ];
        })->values();

        // Ambil semester aktif untuk response
        $activeSemester = Semester::where('is_active', true)->first();
        $selectedSemester = $idSemesterMasuk
            ? Semester::find($idSemesterMasuk)
            : $activeSemester;

        return response()->json([
            'semester' => $selectedSemester ? [
                'id' => $selectedSemester->id,
                'kode' => $selectedSemester->kode,
                'nama' => $selectedSemester->nama,
            ] : null,
            'data' => $groupedData,
        ]);
    }

    /**
     * Template Excel impor perkuliahan (admin / migrasi data).
     *
     * Kolom: id_jadwal (opsional), kunci alami seperti impor jadwal, waktu & materi perkuliahan,
     * plus opsi materi file (tabel materi_perkuliahan): nama label + path relatif ke storage public.
     */
    public function downloadImportTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'id_jadwal (opsional)',
            'Kode Semester (kelas)',
            'Kode Mata Kuliah',
            'Nama Kelas Mahasiswa (kosong = tanpa kelas mahasiswa)',
            'Pertemuan ke- (1-99)',
            'Nama Ruangan (opsional, jika ada beberapa slot)',
            'Waktu Mulai (YYYY-MM-DD HH:MM) — kosong jika hanya impor materi file',
            'Waktu Selesai (opsional)',
            'Materi ringkas (kolom di tabel perkuliahan, opsional)',
            'Realisasi Materi (opsional)',
            'Nama berkas materi (materi_perkuliahan, opsional)',
            'Path file materi (relatif storage public, contoh: materi_perkuliahan/slide.pdf)',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'];
        $widths = [22, 22, 18, 36, 18, 32, 38, 28, 32, 28, 28, 42];
        foreach ($columns as $index => $col) {
            if (isset($widths[$index])) {
                $sheet->getColumnDimension($col)->setWidth($widths[$index]);
            }
        }

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);

        $exampleRow = [
            '',
            '20241',
            'MK001',
            'Kelompok A',
            '1',
            'A101',
            '2026-01-15 08:00',
            '2026-01-15 10:00',
            'Pengenalan kuliah',
            'Materi sesuai RPS',
            'Slide pertemuan 1',
            'materi_perkuliahan/contoh-upload.pdf',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_perkuliahan_'.date('YmdHis').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Impor perkuliahan: id_jadwal langsung, atau kunci alami (semester + MK + kelompok + pertemuan)
     * untuk mencari baris jadwal, lalu menyimpan waktu & teks materi di tabel perkuliahan.
     * Opsional: kolom path file → insert ke tabel materi_perkuliahan (berkas harus sudah ada di storage public).
     */
    public function importSpreadsheet(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $user = $request->user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;
        $actor = $user ? (string) ($user->email ?? $user->id) : 'import';

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

        array_shift($rows);

        $errors = [];
        $perkuliahanSuccessCount = 0;
        $materiPerkuliahanSuccessCount = 0;
        $skipCount = 0;
        // Dilacak eksplisit (bukan diturunkan dari count($errors) - $skipCount) supaya tidak diam-diam
        // ikut salah kalau ada $errors[] baru ditambahkan di masa depan tanpa menandai jenisnya —
        // setiap error yang BUKAN "sudah ada/diabaikan" atau "di luar akses prodi" (dua-duanya sudah
        // dihitung $skipCount) berarti baris itu gagal divalidasi dan sama sekali tidak tersimpan.
        $failedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row, fn ($c) => $c !== null && $c !== ''))) {
                    continue;
                }

                $idJadwalRaw = trim((string) ($row[0] ?? ''));
                $kodeSemester = trim((string) ($row[1] ?? ''));
                $kodeMatkul = trim((string) ($row[2] ?? ''));
                $namaKelompokKelas = trim((string) ($row[3] ?? ''));
                $urutanRaw = trim((string) ($row[4] ?? ''));
                $namaRuangan = trim((string) ($row[5] ?? ''));
                $waktuMulaiRaw = $row[6] ?? null;
                $waktuSelesaiRaw = $row[7] ?? null;
                $materi = trim((string) ($row[8] ?? ''));
                $realisasiMateri = trim((string) ($row[9] ?? ''));
                $namaMateriFile = trim((string) ($row[10] ?? ''));
                $pathMateriFileRaw = trim((string) ($row[11] ?? ''));

                // Ditempel ke setiap pesan log baris (lihat importLogContext()) supaya mudah dilacak;
                // $logProdiNama baru terisi setelah kelas/jadwal berhasil di-resolve di bawah, jadi
                // error sebelum itu (validasi Waktu Mulai, panjang teks, dst) belum menyertakannya.
                $logKodeMatkul = $kodeMatkul !== '' ? $kodeMatkul : null;
                $logProdiNama = null;

                $waktuMulai = $this->parseImportDateTime($waktuMulaiRaw);
                $hasPerkuliahanRow = $waktuMulai !== null;

                if (! $hasPerkuliahanRow && $pathMateriFileRaw === '') {
                    $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Isi Waktu Mulai (perkuliahan) atau Path file materi (minimal salah satu).';
                    $failedCount++;

                    continue;
                }

                if ($hasPerkuliahanRow && $namaMateriFile !== '' && $pathMateriFileRaw === '') {
                    $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Kolom Nama berkas materi diisi tetapi Path file materi kosong.';
                    $failedCount++;

                    continue;
                }

                $waktuSelesai = $this->parseImportDateTime($waktuSelesaiRaw);

                if ($hasPerkuliahanRow) {
                    if ($waktuSelesai && $waktuSelesai->lte($waktuMulai)) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Waktu Selesai harus setelah Waktu Mulai.';
                        $failedCount++;

                        continue;
                    }

                    if (strlen($materi) > 255) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Materi ringkas maksimal 255 karakter.';
                        $failedCount++;

                        continue;
                    }
                    /*
                    if (strlen($realisasiMateri) > 255) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).": Realisasi Materi maksimal 255 karakter.";

                        continue;
                    }
                    */
                }

                if (strlen($namaMateriFile) > 255) {
                    $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Nama berkas materi maksimal 255 karakter.';
                    $failedCount++;

                    continue;
                }

                $jadwal = null;

                if ($idJadwalRaw !== '' && ctype_digit($idJadwalRaw)) {
                    $jid = (int) $idJadwalRaw;
                    $jadwal = Jadwal::with(['kelas.prodi', 'kelas.kurikulumMatkul.matkul'])->whereNull('deleted_at')->find($jid);
                    if (! $jadwal) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).": id_jadwal {$jid} tidak ditemukan.";
                        $failedCount++;

                        continue;
                    }
                    if ($logKodeMatkul === null) {
                        $logKodeMatkul = $jadwal->kelas?->kurikulumMatkul?->kodeMatkulLabel();
                    }
                    $logProdiNama = $jadwal->kelas?->prodi?->nama;
                    if ($allowedProdiIds !== null && ! in_array((int) $jadwal->kelas->id_prodi, $allowedProdiIds, true)) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Tidak ada akses ke prodi kelas jadwal ini.';
                        $skipCount++;

                        continue;
                    }
                } else {
                    if ($kodeSemester === '' || $kodeMatkul === '') {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Isi id_jadwal atau kombinasi Kode Semester + Kode Mata Kuliah.';
                        $failedCount++;

                        continue;
                    }
                    if ($urutanRaw === '' || ! ctype_digit($urutanRaw) || (int) $urutanRaw < 1 || (int) $urutanRaw > 99) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Pertemuan ke- wajib (angka 1-99).';
                        $failedCount++;

                        continue;
                    }
                    $urutan = (int) $urutanRaw;

                    [$kelas, $kelasErr] = $this->resolveKelasFromImportKeys($kodeSemester, $kodeMatkul, $namaKelompokKelas);
                    if ($kelasErr !== null) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).": {$kelasErr}";
                        $failedCount++;

                        continue;
                    }
                    $logProdiNama = $kelas->prodi?->nama;
                    if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Tidak ada akses ke prodi ini.';
                        $skipCount++;

                        continue;
                    }

                    [$jadwal, $jadwalErr] = $this->findJadwalSlotForImport($kelas, $urutan, $namaRuangan);
                    if ($jadwalErr !== null || ! $jadwal) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': '.($jadwalErr ?? 'Jadwal tidak ditemukan.');
                        $failedCount++;

                        continue;
                    }
                }

                $materiVal = $materi !== '' ? $materi : null;
                $realisasiVal = $realisasiMateri !== '' ? $realisasiMateri : null;

                $createdPerkuliahan = false;

                if ($hasPerkuliahanRow) {
                    $exists = Perkuliahan::where('id_jadwal', $jadwal->id)
                        ->where('waktu_mulai', $waktuMulai->format('Y-m-d H:i:s'))
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($exists) {
                        $skipCount++;
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Sudah ada perkuliahan untuk jadwal ini pada waktu mulai yang sama (diabaikan).';
                    } else {
                        Perkuliahan::create([
                            'id_jadwal' => $jadwal->id,
                            'tanggal' => $waktuMulai->toDateString(),
                            'waktu_mulai' => $waktuMulai,
                            'waktu_selesai' => $waktuSelesai,
                            'materi' => $materiVal,
                            'realisasi_materi' => $realisasiVal,
                            'created_by' => $actor,
                        ]);
                        $createdPerkuliahan = true;
                        $perkuliahanSuccessCount++;
                    }
                }

                if ($pathMateriFileRaw !== '') {
                    $normalizedPath = $this->normalizeMateriFilePathForImport($pathMateriFileRaw);
                    if ($normalizedPath === '') {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Path file materi tidak valid.';
                        $failedCount++;

                        continue;
                    }
                    if (strlen($normalizedPath) > 255) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Path file materi maksimal 255 karakter.';
                        $failedCount++;

                        continue;
                    }
                    if (! Storage::disk('public')->exists($normalizedPath)) {
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).": Berkas tidak ditemukan di storage public: {$normalizedPath} (unggah ke storage/app/public atau salin ke folder tersebut).";
                        $failedCount++;

                        continue;
                    }

                    $dupMateri = MateriPerkuliahan::where('id_jadwal', $jadwal->id)
                        ->where('file', $normalizedPath)
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($dupMateri) {
                        $skipCount++;
                        $errors[] = "Baris {$rowNumber}".$this->importLogContext($logKodeMatkul, $logProdiNama).': Materi file dengan path yang sama sudah ada untuk slot jadwal ini (diabaikan).';
                    } else {
                        MateriPerkuliahan::create([
                            'id_jadwal' => $jadwal->id,
                            'nama' => $namaMateriFile !== '' ? $namaMateriFile : null,
                            'file' => $normalizedPath,
                        ]);
                        $materiPerkuliahanSuccessCount++;
                    }
                } elseif ($pathMateriFileRaw === '' && $hasPerkuliahanRow && ! $createdPerkuliahan) {
                    continue;
                }
            }

            $totalSuccess = $perkuliahanSuccessCount + $materiPerkuliahanSuccessCount;

            if (! empty($errors) && $totalSuccess === 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang berhasil diimport.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            $parts = [];
            if ($perkuliahanSuccessCount > 0) {
                $parts[] = "{$perkuliahanSuccessCount} perkuliahan";
            }
            if ($materiPerkuliahanSuccessCount > 0) {
                $parts[] = "{$materiPerkuliahanSuccessCount} materi file (materi_perkuliahan)";
            }
            $summary = $parts !== [] ? 'Berhasil mengimport '.implode(', ', $parts).'.' : '';

            return response()->json([
                'success' => true,
                'message' => $summary.($skipCount > 0 ? " {$skipCount} baris diabaikan." : ''),
                'data' => [
                    'success_count' => $perkuliahanSuccessCount,
                    'materi_perkuliahan_count' => $materiPerkuliahanSuccessCount,
                    'skip_count' => $skipCount,
                    'failed_count' => $failedCount,
                    'error_count' => count($errors),
                ],
                'errors' => $errors,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengimport data: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    /**
     * Info tambahan (kode mata kuliah & nama prodi) yang ditempel ke setiap pesan log import supaya
     * mudah dilacak baris mana yang bermasalah tanpa perlu buka file sumber lagi — dikosongkan kalau
     * belum diketahui di titik proses baris tersebut (mis. sebelum kelas/jadwal berhasil di-resolve).
     */
    private function importLogContext(?string $kodeMatkul, ?string $prodiNama): string
    {
        $parts = [];
        if ($kodeMatkul !== null && $kodeMatkul !== '') {
            $parts[] = "Matkul {$kodeMatkul}";
        }
        if ($prodiNama !== null && $prodiNama !== '') {
            $parts[] = "Prodi {$prodiNama}";
        }

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    /**
     * Normalisasi path relatif ke root disk "public" (storage/app/public).
     */
    private function normalizeMateriFilePathForImport(string $raw): string
    {
        $p = trim(str_replace('\\', '/', $raw));
        $p = ltrim($p, '/');
        if (str_starts_with($p, 'storage/')) {
            $p = substr($p, strlen('storage/'));
        }

        return $p;
    }

    private function parseImportDateTime(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return Carbon::instance(SpreadsheetDate::excelToDateTimeObject((float) $value));
            } catch (\Throwable) {
                // lanjut coba string
            }
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        try {
            return Carbon::parse($s);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?Kelas, 1: ?string} Kelas atau pesan error
     */
    private function resolveKelasFromImportKeys(string $kodeSemester, string $kodeMatkul, string $namaKelompokKelas): array
    {
        $semester = Semester::where('kode', $kodeSemester)->first();
        if (! $semester) {
            return [null, "Semester '{$kodeSemester}' tidak ditemukan."];
        }

        $kurikulumMatkulIds = KurikulumMatkul::query()
            ->where('kode_matkul', $kodeMatkul)
            ->pluck('id');
        if ($kurikulumMatkulIds->isEmpty()) {
            return [null, "Mata kuliah dengan kode '{$kodeMatkul}' tidak ditemukan di kurikulum."];
        }

        $kelasQuery = Kelas::query()
            ->with('prodi')
            ->whereIn('id_kurikulum_matkul', $kurikulumMatkulIds)
            ->where('id_semester', $semester->id);

        if ($namaKelompokKelas !== '') {
            $kelompok = KelompokKelas::query()
                ->whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower($namaKelompokKelas)])
                ->first();
            if (! $kelompok) {
                return [null, "Kelas mahasiswa '{$namaKelompokKelas}' tidak ditemukan."];
            }
            $kelasQuery->where('id_kelompok_kelas', $kelompok->id);
        } else {
            $kelasQuery->whereNull('id_kelompok_kelas');
        }

        $kelasCandidates = $kelasQuery->get();
        if ($kelasCandidates->isEmpty()) {
            return [null, 'Kelas tidak ditemukan untuk kombinasi semester, kode mata kuliah, dan nama kelas mahasiswa (kosong = tanpa kelas mahasiswa).'];
        }
        if ($kelasCandidates->count() > 1) {
            return [null, 'Ditemukan beberapa baris kelas yang cocok — perjelas di data master kelas.'];
        }

        return [$kelasCandidates->first(), null];
    }

    /**
     * @return array{0: ?Jadwal, 1: ?string}
     */
    private function findJadwalSlotForImport(Kelas $kelas, int $urutan, string $namaRuangan): array
    {
        $candidates = Jadwal::where('id_kelas', $kelas->id)
            ->where('urutan_pertemuan', $urutan)
            ->whereNull('deleted_at')
            ->get();

        if ($candidates->isEmpty()) {
            return [null, "Tidak ada slot jadwal untuk pertemuan ke-{$urutan} pada kelas ini."];
        }

        if ($candidates->count() === 1) {
            return [$candidates->first(), null];
        }

        if ($namaRuangan !== '') {
            $ruangan = Ruangan::where('nama', 'like', '%'.$namaRuangan.'%')->first();
            if (! $ruangan) {
                return [null, "Ruangan '{$namaRuangan}' tidak ditemukan (diperlukan untuk membedakan beberapa slot jadwal)."];
            }
            $match = $candidates->firstWhere('id_ruangan', $ruangan->id);
            if (! $match) {
                return [null, 'Tidak ada jadwal dengan ruangan tersebut untuk pertemuan ini.'];
            }

            return [$match, null];
        }

        return [null, 'Ditemukan beberapa slot jadwal untuk pertemuan ini — isi kolom Nama Ruangan atau gunakan id_jadwal.'];
    }

    /**
     * Pastikan dosen pengampu tercatat di jadwal_dosen untuk slot jadwal (aktif / restore jika soft-deleted).
     */
    private function ensureJadwalDosenAktif(int $jadwalId, int $dosenId): void
    {
        $existing = JadwalDosen::withTrashed()
            ->where('id_jadwal', $jadwalId)
            ->where('id_dosen', $dosenId)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            if ($existing->status !== 'active') {
                $existing->update(['status' => 'active']);
            }

            return;
        }

        JadwalDosen::create([
            'id_jadwal' => $jadwalId,
            'id_dosen' => $dosenId,
            'status' => 'active',
        ]);
    }

    /**
     * Nama dosen lengkap (gelar + nama) untuk kolom created_by / updated_by.
     */
    private function namaLengkapDosen(Dosen $dosen, ?Authenticatable $user = null): string
    {
        $nama = trim(
            ($dosen->gelar_depan ? $dosen->gelar_depan.' ' : '').
            (string) ($dosen->nama ?? '').
            ($dosen->gelar_belakang ? ', '.$dosen->gelar_belakang : '')
        );

        if ($nama !== '') {
            return $nama;
        }

        if ($user && isset($user->name) && trim((string) $user->name) !== '') {
            return trim((string) $user->name);
        }

        return (string) ($user->id ?? $dosen->id);
    }

    private function dosenBisaAksesPerkuliahan(Dosen $dosen, Perkuliahan $perkuliahan): bool
    {
        $kelas = $perkuliahan->jadwal->kelas;
        if ((int) $kelas->id_dosen_pic === (int) $dosen->id) {
            return true;
        }

        if (KelasDosen::where('id_dosen', $dosen->id)
            ->where('id_kelas', $kelas->id)
            ->whereNull('deleted_at')
            ->exists()) {
            return true;
        }

        return JadwalDosen::where('id_jadwal', $perkuliahan->id_jadwal)
            ->where('id_dosen', $dosen->id)
            ->where('status', 'active')
            ->exists();
    }

    private function dosenBisaAksesSlotJadwal(Dosen $dosen, Kelas $kelas, Jadwal $jadwal): bool
    {
        if ((int) $kelas->id_dosen_pic === (int) $dosen->id) {
            return true;
        }

        if (KelasDosen::where('id_dosen', $dosen->id)
            ->where('id_kelas', $kelas->id)
            ->whereNull('deleted_at')
            ->exists()) {
            return true;
        }

        return JadwalDosen::where('id_dosen', $dosen->id)
            ->where('id_jadwal', $jadwal->id)
            ->where('status', 'active')
            ->exists();
    }
}
