<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\KelompokKelas;
use App\Models\Krs;
use App\Models\Kurikulum;
use App\Models\KurikulumMatkul;
use App\Models\Matkul;
use App\Models\Perkuliahan;
use App\Models\Prodi;
use App\Models\Semester;
use App\Services\KelasAngkatanService;
use App\Services\KelasKodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KelasController extends Controller
{
    /**
     * Cek duplikat berdasarkan unique (id_kelompok_kelas, id_kurikulum_matkul, id_semester, id_angkatan).
     */
    private function kelasDuplicateExists(array $data, ?int $exceptId = null): bool
    {
        $q = Kelas::query()
            ->where('id_kurikulum_matkul', $data['id_kurikulum_matkul'])
            ->where('id_semester', $data['id_semester'])
            ->where('id_angkatan', $data['id_angkatan']);
        if (! empty($data['id_kelompok_kelas'])) {
            $q->where('id_kelompok_kelas', (int) $data['id_kelompok_kelas']);
        } else {
            $q->whereNull('id_kelompok_kelas');
        }
        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        return $q->exists();
    }

    /**
     * Sinkronisasi kelas_dosen: tim pengampu + dosen PIC (is_pic = true).
     * PIC selalu masuk satu baris dengan is_pic true; tim tidak termasuk duplikat kecuali sama orang (satu baris, is_pic true).
     */
    private function syncKelasDosen(Kelas $kelas, ?int $picId, array $timDosenIds): void
    {
        $timIds = array_values(array_unique(array_map('intval', array_filter(
            $timDosenIds,
            fn ($v) => $v !== null && $v !== ''
        ))));
        $picId = $picId ? (int) $picId : null;

        $allIds = $timIds;
        if ($picId !== null && ! in_array($picId, $allIds, true)) {
            $allIds[] = $picId;
        }

        $rows = KelasDosen::withTrashed()->where('id_kelas', $kelas->id)->get();
        $byDosen = $rows->keyBy('id_dosen');

        foreach ($allIds as $dosenId) {
            $isPic = $picId !== null && $dosenId === $picId;
            $existing = $byDosen->get($dosenId);
            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                if ((bool) $existing->is_pic !== $isPic) {
                    $existing->update(['is_pic' => $isPic]);
                }
            } else {
                KelasDosen::create([
                    'id_kelas' => $kelas->id,
                    'id_dosen' => $dosenId,
                    'is_pic' => $isPic,
                ]);
            }
        }

        foreach ($rows as $row) {
            if ($row->trashed()) {
                continue;
            }
            if (! in_array((int) $row->id_dosen, $allIds, true)) {
                $row->delete();
            }
        }
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi');
        $semesterId = $request->get('id_semester');
        $kelompokKelasId = $request->get('id_kelompok_kelas');

        $query = Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi.jenjang',
            'semester',
            'angkatan',
            'dosenPic',
            'kelompokKelas',
            'kelasDosen.dosen',
        ])
            ->withCount('jadwal');

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id_prodi', $allowedProdiIds);
                if ($prodiId && ! in_array((int) $prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('kurikulumMatkul.matkul', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode', 'like', "%{$search}%");
                })
                    ->orWhereHas('prodi', function ($q) use ($search) {
                        $q->where('nama', 'like', "%{$search}%")
                            ->orWhere('kode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('dosenPic', function ($q) use ($search) {
                        $q->where('nama', 'like', "%{$search}%")
                            ->orWhere('kode_dosen', 'like', "%{$search}%");
                    });
            });
        }

        if ($prodiId) {
            $query->where('id_prodi', $prodiId);
        }

        if ($semesterId) {
            $query->where('id_semester', $semesterId);
        }

        if ($kelompokKelasId) {
            $query->where('id_kelompok_kelas', $kelompokKelasId);
        }

        $data = $query->orderBy('id')->paginate($perPage);
        $this->applySemesterKuliahKeToCollection($data->getCollection());

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            '*.id_kurikulum_matkul' => ['required', 'integer', 'exists:kurikulum_matkul,id'],
            '*.id_prodi' => ['required', 'integer', 'exists:prodi,id'],
            '*.id_semester' => ['required', 'integer', 'exists:semester,id'],
            '*.id_angkatan' => ['required', 'integer', 'exists:semester,id'],
            '*.id_dosen_pic' => ['nullable', 'integer', 'exists:dosen,id'],
            '*.id_kelompok_kelas' => ['nullable', 'integer', 'exists:kelompok_kelas,id'],
            '*.kode' => ['nullable', 'string', 'max:255'],
            '*.jml_pertemuan' => ['nullable', 'integer', 'min:1', 'max:99'],
            '*.is_mingguan' => ['nullable', 'boolean'],
            '*.is_active' => ['nullable', 'boolean'],
            '*.kuota' => ['nullable', 'integer', 'min:0', 'max:32767'],
            '*.dosen_tim_ids' => ['nullable', 'array'],
            '*.dosen_tim_ids.*' => ['integer', 'exists:dosen,id'],
        ]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                foreach ($validated as $data) {
                    if (! in_array((int) $data['id_prodi'], $allowedProdiIds, true)) {
                        abort(403, 'Anda tidak memiliki akses ke program studi ini.');
                    }
                }
            }
        }

        $results = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($validated as $index => $data) {
                $dosenTimIds = $data['dosen_tim_ids'] ?? [];
                unset($data['dosen_tim_ids']);

                if (($data['kode'] ?? '') === '') {
                    $data['kode'] = KelasKodeGenerator::untukKelas(
                        $data['id_kelompok_kelas'] ?? null,
                        $data['id_kurikulum_matkul'] ?? null,
                    );
                }
                $data['jml_pertemuan'] = $data['jml_pertemuan'] ?? 16;
                if (! array_key_exists('is_mingguan', $data) || $data['is_mingguan'] === null) {
                    $data['is_mingguan'] = true;
                }
                // Kolom kelas.is_active NOT NULL default false — menulis null ke situ ditolak di
                // strict mode dan diam-diam jadi 0 di mode longgar.
                if (! array_key_exists('is_active', $data) || $data['is_active'] === null) {
                    $data['is_active'] = false;
                }
                $data['kuota'] = $data['kuota'] ?? 0;

                $pesanAngkatan = KelasAngkatanService::pesanKetidakcocokan(
                    $data['id_kelompok_kelas'] ?? null,
                    $data['id_angkatan'] ?? null,
                );
                if ($pesanAngkatan !== null) {
                    $errors[] = [
                        'index' => $index,
                        'message' => $pesanAngkatan,
                        'field' => 'id_angkatan',
                    ];

                    continue;
                }

                if ($this->kelasDuplicateExists($data)) {
                    $errors[] = [
                        'index' => $index,
                        'message' => 'Kelas dengan kombinasi kelompok, kurikulum mata kuliah, semester berjalan, dan angkatan yang sama sudah ada.',
                        'field' => 'id_kurikulum_matkul',
                    ];

                    continue;
                }

                $kelas = Kelas::create($data);
                $picId = $kelas->id_dosen_pic ? (int) $kelas->id_dosen_pic : null;
                $this->syncKelasDosen($kelas, $picId, is_array($dosenTimIds) ? $dosenTimIds : []);
                $kelas->load([
                    'kurikulumMatkul.matkul',
                    'kurikulumMatkul.kurikulum',
                    'prodi',
                    'semester',
                    'angkatan',
                    'dosenPic',
                    'kelasDosen.dosen',
                ]);
                $results[] = $kelas;
            }

            if (! empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Beberapa kelas gagal disimpan karena duplikasi atau kesalahan lainnya.',
                    'errors' => $errors,
                    'data' => $results,
                ], 422);
            }

            DB::commit();

            return response()->json($results, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan kelas: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    public function edit(Request $request, Kelas $kelas): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $kelas->load([
            'kurikulumMatkul' => function ($query) {
                $query->withTrashed();
            },
            'kurikulumMatkul.matkul' => function ($query) {
                $query->withTrashed();
            },
            'kurikulumMatkul.kurikulum' => function ($query) {
                $query->withTrashed();
            },
            'kurikulumMatkul.kurikulum.prodi' => function ($query) {
                $query->withTrashed();
            },
            'prodi' => function ($query) {
                $query->withTrashed();
            },
            'prodi.jenjang' => function ($query) {
                $query->withTrashed();
            },
            'semester' => function ($query) {
                $query->withTrashed();
            },
            'dosenPic' => function ($query) {
                $query->withTrashed();
            },
            'kelompokKelas',
            'angkatan' => function ($query) {
                $query->withTrashed();
            },
            'kelasDosen' => function ($query) {
                $query->whereNull('deleted_at');
            },
            'kelasDosen.dosen' => function ($query) {
                $query->withTrashed();
            },
        ]);

        $this->applySemesterKuliahKe($kelas);

        return response()->json($kelas);
    }

    /**
     * Get detail kelas dengan jadwal dan perkuliahan untuk admin
     */
    public function getDetailWithJadwal(Request $request, int $id): JsonResponse
    {
        $kelas = Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi.jenjang',
            'semester',
            'angkatan',
            'dosenPic',
            'kelasDosen.dosen',
        ])->find($id);

        if (! $kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $this->applySemesterKuliahKe($kelas);

        // Ambil semua jadwal untuk kelas ini
        $jadwalList = Jadwal::with([
            'jenisKuliah',
            'ruangan',
            'dosen.dosen',
        ])
            ->where('id_kelas', $id)
            ->orderBy('hari')
            ->orderBy('jam_mulai')
            ->get();

        // Ambil semua jadwal untuk kelas ini
        $jadwalIds = $jadwalList->pluck('id')->toArray();

        // Ambil semua perkuliahan untuk jadwal-jadwal kelas ini
        $perkuliahanList = Perkuliahan::whereIn('id_jadwal', $jadwalIds)
            ->orderByRaw('waktu_mulai IS NULL')
            ->orderBy('waktu_mulai', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Hitung jumlah mahasiswa yang mengambil kelas ini dari KRS
        $jumlahMahasiswa = Krs::where('id_kelas', $id)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(DISTINCT id_mahasiswa) as count')
            ->value('count') ?? 0;

        // Hitung jumlah mahasiswa hadir per perkuliahan
        $perkuliahanIds = $perkuliahanList->pluck('id')->toArray();
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

        // Tambahkan jumlah hadir dan total ke setiap perkuliahan
        $perkuliahanList = $perkuliahanList->map(function ($perkuliahan) use ($jumlahHadirPerPerkuliahan, $jumlahMahasiswa) {
            $perkuliahan->jumlah_hadir = $jumlahHadirPerPerkuliahan[$perkuliahan->id] ?? 0;
            $perkuliahan->jumlah_total = $jumlahMahasiswa;

            return $perkuliahan;
        });

        // Group perkuliahan by jadwal (jika ada relasi)
        // Untuk sekarang, kita akan mengasumsikan perkuliahan terkait dengan kelas, bukan jadwal spesifik
        // Tapi kita bisa menambahkan relasi jika diperlukan

        return response()->json([
            'kelas' => $kelas,
            'jadwal' => $jadwalList,
            'perkuliahan' => $perkuliahanList,
            'jumlah_mahasiswa' => $jumlahMahasiswa,
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        // Load relasi dengan withTrashed untuk memastikan relasi yang sudah di-soft delete tetap dimuat
        $kelas = Kelas::with([
            'kurikulumMatkul' => function ($query) {
                $query->withTrashed();
            },
            'kurikulumMatkul.matkul' => function ($query) {
                $query->withTrashed();
            },
            'kurikulumMatkul.kurikulum' => function ($query) {
                $query->withTrashed();
            },
            'kurikulumMatkul.kurikulum.prodi' => function ($query) {
                $query->withTrashed();
            },
            'prodi' => function ($query) {
                $query->withTrashed();
            },
            'semester' => function ($query) {
                $query->withTrashed();
            },
            'dosenPic' => function ($query) {
                $query->withTrashed();
            },
            'angkatan' => function ($query) {
                $query->withTrashed();
            },
            'kelasDosen' => function ($query) {
                $query->whereNull('deleted_at');
            },
            'kelasDosen.dosen' => function ($query) {
                $query->withTrashed();
            },
        ])->find($id);
        if (! $kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $this->applySemesterKuliahKe($kelas);

        return response()->json($kelas);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $kelas = Kelas::find($id);
        if (! $kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $validated = $request->validate([
            'id_kurikulum_matkul' => ['sometimes', 'required', 'integer', 'exists:kurikulum_matkul,id'],
            'id_prodi' => ['sometimes', 'required', 'integer', 'exists:prodi,id'],
            'id_semester' => ['sometimes', 'required', 'integer', 'exists:semester,id'],
            'id_angkatan' => ['sometimes', 'required', 'integer', 'exists:semester,id'],
            'id_dosen_pic' => ['nullable', 'integer', 'exists:dosen,id'],
            'id_kelompok_kelas' => ['nullable', 'integer', 'exists:kelompok_kelas,id'],
            'kode' => ['nullable', 'string', 'max:255'],
            'jml_pertemuan' => ['nullable', 'integer', 'min:1', 'max:99'],
            'is_mingguan' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'kuota' => ['nullable', 'integer', 'min:0', 'max:32767'],
            'dosen_tim_ids' => ['nullable', 'array'],
            'dosen_tim_ids.*' => ['integer', 'exists:dosen,id'],
        ]);

        $dosenTimIds = null;
        if (array_key_exists('dosen_tim_ids', $validated)) {
            $dosenTimIds = $validated['dosen_tim_ids'] ?? [];
            unset($validated['dosen_tim_ids']);
        }

        $merged = array_merge(
            [
                'id_kurikulum_matkul' => (int) $kelas->id_kurikulum_matkul,
                'id_semester' => (int) $kelas->id_semester,
                'id_angkatan' => (int) $kelas->id_angkatan,
                'id_kelompok_kelas' => $kelas->id_kelompok_kelas,
            ],
            $validated
        );

        // Kode dikosongkan admin -> dibuatkan sistem dari kelompok kelas hasil merge (bukan
        // hanya yang dikirim), supaya edit yang tidak menyentuh kelompok kelas tetap benar.
        if (array_key_exists('kode', $validated) && ($validated['kode'] === '' || $validated['kode'] === null)) {
            $validated['kode'] = KelasKodeGenerator::untukKelas(
                $merged['id_kelompok_kelas'] !== null ? (int) $merged['id_kelompok_kelas'] : null,
                (int) $merged['id_kurikulum_matkul'],
            );
        }

        $checkDuplicate = [
            'id_kurikulum_matkul' => (int) ($merged['id_kurikulum_matkul'] ?? $kelas->id_kurikulum_matkul),
            'id_semester' => (int) ($merged['id_semester'] ?? $kelas->id_semester),
            'id_angkatan' => (int) ($merged['id_angkatan'] ?? $kelas->id_angkatan),
            'id_kelompok_kelas' => array_key_exists('id_kelompok_kelas', $merged)
                ? $merged['id_kelompok_kelas']
                : $kelas->id_kelompok_kelas,
        ];

        $pesanAngkatan = KelasAngkatanService::pesanKetidakcocokan(
            $checkDuplicate['id_kelompok_kelas'] !== null ? (int) $checkDuplicate['id_kelompok_kelas'] : null,
            (int) $checkDuplicate['id_angkatan'],
        );
        if ($pesanAngkatan !== null) {
            return response()->json([
                'message' => $pesanAngkatan,
                'errors' => [
                    'id_angkatan' => [$pesanAngkatan],
                ],
            ], 422);
        }

        if ($this->kelasDuplicateExists($checkDuplicate, (int) $kelas->id)) {
            return response()->json([
                'message' => 'Kelas dengan kombinasi kelompok, kurikulum mata kuliah, semester berjalan, dan angkatan yang sama sudah ada.',
                'errors' => [
                    'id_kurikulum_matkul' => ['Kelas dengan kombinasi ini sudah ada.'],
                ],
            ], 422);
        }

        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if (array_key_exists('id_prodi', $validated) && $allowedProdiIds !== null && ! in_array((int) $validated['id_prodi'], $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke program studi ini.');
            }
        }

        $kelas->update($validated);
        $kelas->refresh();

        $timForSync = $dosenTimIds !== null
            ? $dosenTimIds
            : KelasDosen::where('id_kelas', $kelas->id)->where('is_pic', false)->pluck('id_dosen')->all();
        $picId = $kelas->id_dosen_pic ? (int) $kelas->id_dosen_pic : null;
        $this->syncKelasDosen($kelas, $picId, $timForSync);

        $kelas->refresh();
        $kelas->load([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi',
            'semester',
            'angkatan',
            'dosenPic',
            'kelasDosen.dosen',
        ]);

        $this->applySemesterKuliahKe($kelas);

        return response()->json($kelas);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $kelas = Kelas::find($id);
        if (! $kelas) {
            return response()->json(['message' => 'Kelas tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        $kelas->delete();

        return response()->json(['message' => 'Kelas dihapus']);
    }

    public function getKurikulumMatkulOptions(Request $request): JsonResponse
    {
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi');
        $perPage = (int) $request->get('per_page', 100);

        $query = KurikulumMatkul::with(['matkul', 'kurikulum.prodi']);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('kurikulum', function ($q) use ($allowedProdiIds) {
                    $q->whereIn('id_prodi', $allowedProdiIds);
                });
                if ($prodiId && ! in_array((int) $prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('matkul', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode', 'like', "%{$search}%");
                })->orWhereHas('kurikulum', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode', 'like', "%{$search}%");
                });
            });
        }

        if ($prodiId) {
            $query->whereHas('kurikulum', function ($q) use ($prodiId) {
                $q->where('id_prodi', $prodiId);
            });
        }

        $data = $query->orderBy('id')->paginate($perPage);

        return response()->json($data);
    }

    private static function normalizeKodeSemesterCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            $f = (float) $value;
            if ($f == floor($f)) {
                return (string) (int) $f;
            }
        }

        return trim((string) $value);
    }

    private static function parseIntCell(mixed $value, ?int $default = null): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return $default;
        }
        if (is_numeric($s)) {
            return (int) round((float) $s);
        }

        return $default;
    }

    private static function parseMingguanCell(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower(trim((string) $value));
        if ($s === '' || in_array($s, ['ya', 'y', 'yes', '1', 'true', 'benar'], true)) {
            return true;
        }
        if (in_array($s, ['tidak', 't', 'no', '0', 'false', 'salah'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Pecah daftar kode dosen (tim): koma, titik koma, atau baris baru.
     *
     * @return list<string>
     */
    private static function splitKodeDosenList(string $raw): array
    {
        $parts = preg_split('/[,;\n\r]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map('trim', $parts ?: []))));
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        $headers = [
            'Kode Prodi*',
            'Kode Kurikulum*',
            'Kode Mata Kuliah*',
            'Kode Semester (berjalan)*',
            'Kode Kelas',
            'Jml Pertemuan',
            'Mingguan (Ya/Tidak)',
            'Kuota',
            'Kode Dosen PIC',
            'Kode Tim Dosen (koma)',
            'Nama Kelas Mahasiswa',
            'Kode Angkatan',
            'Aktif (Ya/Tidak)',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $cols = ['A' => 14, 'B' => 18, 'C' => 18, 'D' => 22, 'E' => 12, 'F' => 14, 'G' => 18, 'H' => 10, 'I' => 16, 'J' => 28, 'K' => 22, 'L' => 22, 'M' => 16];
        foreach ($cols as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ];
        $sheet->getStyle('A1:M1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(40);

        $exampleRow = [
            'TI',
            'KUR-2024',
            'MK001',
            '20241',
            'A',
            '16',
            'Ya',
            '40',
            'DSN001',
            'DSN002, DSN003',
            'Reguler Pagi',
            '',
            'Tidak',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');
        $sheet->freezePane('A2');

        $petunjuk = $spreadsheet->createSheet();
        $petunjuk->setTitle('Petunjuk');
        $petunjuk->getColumnDimension('A')->setWidth(102);
        $lines = [
            ['PANDUAN IMPORT KELAS'],
            [''],
            ['Urutan kolom (baris 1 = header, data mulai baris 2):'],
            ['A — Kode Prodi*     : kode program studi.'],
            ['B — Kode Kurikulum* : harus ada di sistem untuk prodi tersebut (penentuan MK kurikulum).'],
            ['C — Kode Mata Kuliah* : kode matkul pada kurikulum tersebut.'],
            ['D — Kode Semester berjalan* : sama dengan kode di master Semester (mis. 20241).'],
            ['E — Kode Kelas      : opsional, maks. 255 karakter.'],
            ['F — Jml pertemuan   : opsional, default 16.'],
            ['G — Mingguan        : Ya/Tidak (default Ya).'],
            ['H — Kuota           : opsional, default 0.'],
            ['I — Kode Dosen PIC  : opsional.'],
            ['J — Tim dosen       : opsional, beberapa kode dosen dipisah koma.'],
            ['K — Nama kelas mahasiswa   : opsional (harus cocok master kelas mahasiswa).'],
            ['L — Kode angkatan   : semester MASUK mahasiswa (bukan semester berjalan); kosong = diambil dari angkatan mahasiswa di kolom K.'],
            ['M — Aktif           : opsional Ya/Tidak (default Tidak = is_active di tabel kelas).'],
            [''],
            ['File lama (6 kolom: Matkul, Prodi, Semester, Dosen PIC, Kelompok, Angkatan) masih didukung.'],
        ];
        $r = 1;
        foreach ($lines as $line) {
            $petunjuk->setCellValue('A'.$r, $line[0]);
            $r++;
        }
        $petunjuk->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'template_import_kelas_'.date('YmdHis').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $user = $request->user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $worksheet = $spreadsheet->getSheetByName('Data') ?? $spreadsheet->getActiveSheet();
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
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row, fn ($v) => $v !== null && $v !== '' && trim((string) $v) !== ''))) {
                    continue;
                }

                $col0 = trim((string) ($row[0] ?? ''));
                $col1 = trim((string) ($row[1] ?? ''));
                $prodiByCol0 = $col0 !== '' ? Prodi::where('kode', $col0)->first() : null;

                $kodeMatkul = '';
                $kodeSemester = '';
                $kodeDosen = '';
                $namaKelompokKelas = '';
                $kodeAngkatan = '';
                $kodeKelas = null;
                $jmlPertemuan = 16;
                $isMingguan = true;
                $kuota = 0;
                $timDosenRaw = '';
                $prodi = null;
                $kurikulum = null;
                $isActive = false;

                if ($prodiByCol0) {
                    $prodi = $prodiByCol0;
                    if ($col1 === '') {
                        $errors[] = "Baris {$rowNumber}: Kode kurikulum (kolom B) wajib diisi untuk format baru.";

                        continue;
                    }
                    $kurikulum = Kurikulum::where('id_prodi', $prodi->id)->where('kode', $col1)->first();
                    if (! $kurikulum) {
                        $errors[] = "Baris {$rowNumber}: Kurikulum dengan kode '{$col1}' untuk prodi '{$col0}' tidak ditemukan.";

                        continue;
                    }
                    $kodeMatkul = trim((string) ($row[2] ?? ''));
                    $kodeSemester = self::normalizeKodeSemesterCell($row[3] ?? null);
                    $kKelas = trim((string) ($row[4] ?? ''));
                    if ($kKelas !== '') {
                        if (mb_strlen($kKelas) > 255) {
                            $errors[] = "Baris {$rowNumber}: Kode kelas maksimal 255 karakter.";

                            continue;
                        }
                        $kodeKelas = $kKelas;
                    }
                    $jmlPertemuan = self::parseIntCell($row[5] ?? null, 16) ?? 16;
                    if ($jmlPertemuan < 1 || $jmlPertemuan > 99) {
                        $errors[] = "Baris {$rowNumber}: Jumlah pertemuan harus antara 1 dan 99.";

                        continue;
                    }
                    $m = self::parseMingguanCell($row[6] ?? null);
                    $isMingguan = $m !== null ? $m : true;
                    $kuota = self::parseIntCell($row[7] ?? null, 0) ?? 0;
                    if ($kuota < 0 || $kuota > 32767) {
                        $errors[] = "Baris {$rowNumber}: Kuota harus 0–32767.";

                        continue;
                    }
                    $kodeDosen = trim((string) ($row[8] ?? ''));
                    $timDosenRaw = trim((string) ($row[9] ?? ''));
                    $namaKelompokKelas = trim((string) ($row[10] ?? ''));
                    $kodeAngkatan = self::normalizeKodeSemesterCell($row[11] ?? null);
                    $activeParsed = self::parseMingguanCell($row[12] ?? null);
                    $isActive = $activeParsed !== null ? $activeParsed : false;
                } else {
                    $kodeMatkul = trim((string) ($row[0] ?? ''));
                    $kodeProdi = trim((string) ($row[1] ?? ''));
                    $kodeSemester = self::normalizeKodeSemesterCell($row[2] ?? null);
                    $kodeDosen = trim((string) ($row[3] ?? ''));
                    $namaKelompokKelas = trim((string) ($row[4] ?? ''));
                    $kodeAngkatan = self::normalizeKodeSemesterCell($row[5] ?? null);

                    if ($kodeMatkul === '') {
                        $errors[] = "Baris {$rowNumber}: Kode mata kuliah wajib diisi (format lama: kolom A = kode MK, B = kode prodi).";

                        continue;
                    }
                    if ($kodeProdi === '') {
                        $errors[] = "Baris {$rowNumber}: Kode prodi wajib diisi.";

                        continue;
                    }
                    $prodi = Prodi::where('kode', $kodeProdi)->first();
                    if (! $prodi) {
                        $errors[] = "Baris {$rowNumber}: Prodi dengan kode '{$kodeProdi}' tidak ditemukan.";

                        continue;
                    }

                    $kurikulum = Kurikulum::query()
                        ->join('semester as sem_th', 'sem_th.id', '=', 'kurikulum.id_tahun_berlaku')
                        ->where('kurikulum.id_prodi', $prodi->id)
                        ->where('kurikulum.status', 'active')
                        ->orderBy('sem_th.kode', 'desc')
                        ->select('kurikulum.*')
                        ->first();

                    if (! $kurikulum) {
                        $kurikulum = Kurikulum::query()
                            ->join('semester as sem_th', 'sem_th.id', '=', 'kurikulum.id_tahun_berlaku')
                            ->where('kurikulum.id_prodi', $prodi->id)
                            ->orderBy('sem_th.kode', 'desc')
                            ->select('kurikulum.*')
                            ->first();
                    }

                    if (! $kurikulum) {
                        $errors[] = "Baris {$rowNumber}: Kurikulum untuk prodi '{$kodeProdi}' tidak ditemukan.";

                        continue;
                    }
                }

                if ($kodeMatkul === '') {
                    $errors[] = "Baris {$rowNumber}: Kode mata kuliah wajib diisi.";

                    continue;
                }

                if ($kodeSemester === '') {
                    $errors[] = "Baris {$rowNumber}: Kode semester berjalan wajib diisi (sama dengan kode di master Semester, mis. 20241).";

                    continue;
                }

                $matkul = Matkul::where('kode', $kodeMatkul)
                    ->whereHas('prodi', function ($query) use ($prodi) {
                        $query->where('id', $prodi->id);
                    })->first();

                if (! $matkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah dengan kode '{$kodeMatkul}' untuk prodi '{$prodi->kode}' tidak ditemukan.";

                    continue;
                }

                $kurikulumMatkul = KurikulumMatkul::where('id_matkul', $matkul->id)
                    ->where('id_kurikulum', $kurikulum->id)
                    ->first();

                if (! $kurikulumMatkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah '{$kodeMatkul}' tidak ada pada kurikulum '{$kurikulum->kode}'.";

                    continue;
                }

                $semester = Semester::where('kode', $kodeSemester)->first();
                if (! $semester) {
                    $hint = preg_match('/^\d{4}$/', $kodeSemester) ? ' Gunakan kode semester lengkap (bukan hanya tahun).' : '';
                    $errors[] = "Baris {$rowNumber}: Semester berjalan dengan kode '{$kodeSemester}' tidak ditemukan.{$hint}";

                    continue;
                }

                if ($allowedProdiIds !== null && ! in_array((int) $prodi->id, $allowedProdiIds, true)) {
                    $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke program studi '{$prodi->kode}'.";
                    $skipCount++;

                    continue;
                }

                $dosenId = null;
                if ($kodeDosen !== '') {
                    $dosen = Dosen::where('kode_dosen', $kodeDosen)->first();
                    if (! $dosen) {
                        $errors[] = "Baris {$rowNumber}: Dosen PIC dengan kode '{$kodeDosen}' tidak ditemukan.";

                        continue;
                    }
                    $dosenId = $dosen->id;
                }

                $kelompokKelasId = null;
                if ($namaKelompokKelas !== '') {
                    $kelompokKelas = KelompokKelas::where('nama', $namaKelompokKelas)->first();
                    if (! $kelompokKelas) {
                        $errors[] = "Baris {$rowNumber}: Kelas mahasiswa '{$namaKelompokKelas}' tidak ditemukan.";

                        continue;
                    }
                    $kelompokKelasId = $kelompokKelas->id;
                }

                // id_angkatan = semester masuk mahasiswa, bukan semester berjalan. Kolom kosong
                // diturunkan dari kelas mahasiswa yang dipilih; kalau tidak bisa (kelompok kosong
                // atau angkatannya campuran) baru jatuh ke semester berjalan.
                $semesterAngkatan = $semester;
                if ($kodeAngkatan !== '') {
                    $semesterAngkatan = Semester::where('kode', $kodeAngkatan)->first();
                    if (! $semesterAngkatan) {
                        $errors[] = "Baris {$rowNumber}: Angkatan/semester dengan kode '{$kodeAngkatan}' tidak ditemukan.";

                        continue;
                    }
                } else {
                    $saranAngkatan = KelasAngkatanService::angkatanSaranForKelompokKelas($kelompokKelasId);
                    if ($saranAngkatan !== null) {
                        $semesterAngkatan = Semester::find($saranAngkatan) ?? $semester;
                    }
                }

                $pesanAngkatan = KelasAngkatanService::pesanKetidakcocokan($kelompokKelasId, (int) $semesterAngkatan->id);
                if ($pesanAngkatan !== null) {
                    $errors[] = "Baris {$rowNumber}: {$pesanAngkatan}";

                    continue;
                }

                $kelasData = [
                    'kode' => $kodeKelas,
                    'id_kurikulum_matkul' => $kurikulumMatkul->id,
                    'id_prodi' => $prodi->id,
                    'id_semester' => $semester->id,
                    'id_angkatan' => $semesterAngkatan->id,
                    'id_dosen_pic' => $dosenId,
                    'id_kelompok_kelas' => $kelompokKelasId,
                    'jml_pertemuan' => $jmlPertemuan,
                    'is_mingguan' => $isMingguan,
                    'kuota' => $kuota,
                    'is_active' => $isActive,
                ];

                if ($this->kelasDuplicateExists($kelasData)) {
                    $skipCount++;
                    $errors[] = "Baris {$rowNumber}: Kelas dengan kombinasi yang sama sudah ada (diabaikan).";

                    continue;
                }

                $dosenTimIdsResolved = [];
                if ($timDosenRaw !== '') {
                    foreach (self::splitKodeDosenList($timDosenRaw) as $kd) {
                        $d = Dosen::where('kode_dosen', $kd)->first();
                        if (! $d) {
                            $errors[] = "Baris {$rowNumber}: Tim dosen — kode '{$kd}' tidak ditemukan.";

                            continue 2;
                        }
                        $dosenTimIdsResolved[] = (int) $d->id;
                    }
                }

                $kelas = Kelas::create($kelasData);
                $this->syncKelasDosen($kelas, $dosenId ? (int) $dosenId : null, $dosenTimIdsResolved);

                $processedRows[] = [
                    'row' => $rowNumber,
                    'id' => $kelas->id,
                ];
                $successCount++;
            }

            if (! empty($errors) && $successCount === 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Terbaca '.count($rows).' baris, tetapi tidak ada data yang berhasil diimport.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Berhasil mengimport {$successCount} kelas dari ".count($rows).' baris.'.($skipCount > 0 ? " {$skipCount} kelas diabaikan karena duplikasi." : ''),
                'data' => [
                    'success_count' => $successCount,
                    'skip_count' => $skipCount,
                    'error_count' => count($errors),
                    'processed_rows' => $processedRows,
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
     * Urutan master semester (kode ASC). id_semester = semester berjalan kelas, id_angkatan = semester masuk mahasiswa.
     * Semester kuliah ke-N = selisih posisi + 1 (N=1 saat semester berjalan = angkatan).
     */
    private function applySemesterKuliahKe(Kelas $kelas): void
    {
        $map = $this->semesterIdToIndexMap();
        $idxS = $map[(int) $kelas->id_semester] ?? null;
        $idxA = $map[(int) $kelas->id_angkatan] ?? null;
        if ($idxS === null || $idxA === null || $idxS < $idxA) {
            $kelas->setAttribute('semester_kuliah_ke', null);

            return;
        }
        $kelas->setAttribute('semester_kuliah_ke', $idxS - $idxA + 1);
    }

    /**
     * @param  Collection<int, Kelas>  $collection
     */
    private function applySemesterKuliahKeToCollection($collection): void
    {
        foreach ($collection as $kelas) {
            $this->applySemesterKuliahKe($kelas);
        }
    }

    /**
     * @return array<int, int> id semester => indeks urut (0-based) menurut kode
     */
    private function semesterIdToIndexMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $ordered = Semester::withTrashed()->orderBy('kode')->pluck('id')->values();
        $map = [];
        foreach ($ordered as $i => $semId) {
            $map[(int) $semId] = $i;
        }

        return $map;
    }
}
