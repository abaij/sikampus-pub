<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\JenisKuliah;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\KelasDosen;
use App\Models\KelompokKelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Matkul;
use App\Models\Perkuliahan;
use App\Models\Ruangan;
use App\Models\Semester;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JadwalController extends Controller
{
    /** Senin–Minggu dari tanggal (timezone aplikasi). */
    private function hariDariTanggal(Carbon $dt): string
    {
        $idx = (int) $dt->format('N') - 1; // ISO: 1=Senin … 7=Minggu

        return Jadwal::HARI[$idx] ?? 'senin';
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');
        $kelasId = $request->get('id_kelas');
        $hari = $request->get('hari');
        $prodiId = $request->get('id_prodi');
        $semesterId = $request->get('id_semester');
        $isActiveFilter = $request->get('is_active');

        $query = Jadwal::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.prodi.jenjang',
            'kelas.semester',
            'jenisKuliah',
            'ruangan',
            'dosen.dosen',
        ])->whereHas('kelas', function ($q) {
            $q->whereNull('deleted_at');
        });

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('kelas', function ($q) use ($allowedProdiIds) {
                    $q->whereIn('id_prodi', $allowedProdiIds);
                });
                if ($prodiId && ! in_array((int) $prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode', 'like', "%{$search}%");
                })
                    ->orWhereHas('ruangan', function ($q) use ($search) {
                        $q->where('nama', 'like', "%{$search}%");
                    })
                    ->orWhere('hari', 'like', "%{$search}%");
            });
        }

        if ($prodiId) {
            $query->whereHas('kelas', function ($q) use ($prodiId) {
                $q->where('id_prodi', $prodiId);
            });
        }

        if ($kelasId) {
            $query->where('id_kelas', $kelasId);
        }

        if ($semesterId) {
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        if ($hari) {
            $query->where('hari', $hari);
        }

        if ($isActiveFilter !== null && $isActiveFilter !== '') {
            $query->where('is_active', filter_var($isActiveFilter, FILTER_VALIDATE_BOOLEAN));
        }

        $paginator = $query->orderBy('id_kelas')->orderBy('urutan_pertemuan')->paginate($perPage);

        $jadwalIds = $paginator->getCollection()->pluck('id')->filter()->values()->all();
        $perkuliahanRows = $jadwalIds === []
            ? collect()
            : Perkuliahan::whereIn('id_jadwal', $jadwalIds)->whereNull('deleted_at')->get();

        $paginator->getCollection()->transform(function (Jadwal $jadwal) use ($perkuliahanRows) {
            $p = $this->findPerkuliahanForJadwalSlot($jadwal, $perkuliahanRows);
            $sesi = $this->sesiStatusForPerkuliahan($p);
            $jadwal->setAttribute('sesi_status', $sesi['sesi_status']);
            $jadwal->setAttribute('sesi_status_label', $sesi['sesi_status_label']);

            return $jadwal;
        });

        return response()->json($paginator);
    }

    /**
     * Cocokkan baris perkuliahan dengan slot jadwal (prioritas sesi berlangsung, lalu terbaru).
     */
    private function findPerkuliahanForJadwalSlot(Jadwal $j, Collection $perkuliahanRows): ?Perkuliahan
    {
        $slotId = (int) $j->id;
        $candidates = $perkuliahanRows->filter(fn ($p) => (int) $p->id_jadwal === $slotId);

        $ts = static function (?Perkuliahan $p): int {
            if ($p === null || ! $p->waktu_mulai) {
                return 0;
            }

            return Carbon::parse($p->waktu_mulai)->getTimestamp();
        };

        $ongoing = $candidates
            ->filter(fn (Perkuliahan $p) => $p->waktu_mulai && ! $p->waktu_selesai)
            ->sortByDesc(fn (Perkuliahan $p) => $ts($p))
            ->first();

        if ($ongoing) {
            return $ongoing;
        }

        return $candidates
            ->sortByDesc(fn (Perkuliahan $p) => [$ts($p), $p->id])
            ->first();
    }

    /**
     * @return array{sesi_status: string, sesi_status_label: string}
     */
    private function sesiStatusForPerkuliahan(?Perkuliahan $p): array
    {
        if ($p === null) {
            return [
                'sesi_status' => 'belum_mulai',
                'sesi_status_label' => 'Belum ada sesi',
            ];
        }

        $mulai = $p->waktu_mulai !== null && trim((string) $p->waktu_mulai) !== '';
        $selesai = $p->waktu_selesai !== null && trim((string) $p->waktu_selesai) !== '';

        if (! $mulai) {
            return [
                'sesi_status' => 'belum_mulai',
                'sesi_status_label' => 'Belum dimulai',
            ];
        }

        if (! $selesai) {
            return [
                'sesi_status' => 'sedang_berlangsung',
                'sesi_status_label' => 'Sedang berlangsung',
            ];
        }

        return [
            'sesi_status' => 'selesai',
            'sesi_status_label' => 'Selesai',
        ];
    }

    /**
     * Daftar jadwal kuliah untuk admin prodi (hanya kelas di scope prodi user).
     * Query: id_semester, id_kelas, hari, search (nama/kode matkul), per_page, page.
     */
    public function indexProdi(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedProdiIds = $user && $user->hasScopeRestriction() ? $user->getAllowedProdiIds() : null;
        if ($allowedProdiIds === null || empty($allowedProdiIds)) {
            return response()->json(['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'from' => 0, 'to' => 0]);
        }

        $perPage = (int) $request->get('per_page', 10);
        $semesterId = $request->get('id_semester');
        $kelasId = $request->get('id_kelas');
        $hari = $request->get('hari');
        $search = $request->get('search');

        $query = Jadwal::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'jenisKuliah',
            'ruangan',
            'dosen.dosen',
        ])->whereHas('kelas', function ($q) use ($allowedProdiIds) {
            $q->whereNull('kelas.deleted_at')->whereIn('kelas.id_prodi', $allowedProdiIds);
        });

        if ($semesterId) {
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        if ($kelasId) {
            $query->where('id_kelas', $kelasId);
        }

        if ($hari) {
            $query->where('hari', $hari);
        }

        if ($search && trim($search) !== '') {
            $search = trim($search);
            $query->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('kode', 'like', "%{$search}%");
            });
        }

        $data = $query->orderBy('id_kelas')->orderBy('urutan_pertemuan')->paginate($perPage);

        return response()->json($data);
    }

    /**
     * Opsi kelas untuk filter jadwal kuliah prodi (hanya kelas di scope prodi user).
     * Query: id_semester (opsional).
     */
    public function getKelasOptionsProdi(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedProdiIds = $user && $user->hasScopeRestriction() ? $user->getAllowedProdiIds() : null;
        if ($allowedProdiIds === null || empty($allowedProdiIds)) {
            return response()->json(['data' => []]);
        }

        $perPage = (int) $request->get('per_page', 100);
        $semesterId = $request->get('id_semester');

        $query = Kelas::with(['kurikulumMatkul.matkul', 'kurikulumMatkul.kurikulum', 'prodi', 'semester'])
            ->whereIn('id_prodi', $allowedProdiIds);

        if ($semesterId) {
            $query->where('id_semester', $semesterId);
        }

        $data = $query->orderBy('id')->paginate($perPage);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            '*.id_kelas' => ['required', 'integer', 'exists:kelas,id'],
            '*.jumlah_pertemuan' => ['required', 'integer', 'min:1', 'max:99'],
            '*.is_active' => ['nullable', 'boolean'],
            '*.id_jenis_kuliah' => ['nullable', 'integer', 'exists:jenis_kuliah,id'],
            '*.hari' => ['nullable', 'string', Rule::in(Jadwal::HARI)],
            '*.jam_mulai' => ['nullable', 'date_format:H:i'],
            '*.jam_selesai' => ['nullable', 'date_format:H:i'],
            '*.id_ruangan' => ['nullable', 'integer', 'exists:ruangan,id'],
            '*.dosen' => ['nullable', 'array'],
            '*.dosen.*' => ['integer', 'exists:dosen,id'],
            '*.tanggal_mulai' => ['nullable', 'date'],
            '*.tanggal_hari_otomatis' => ['nullable', 'boolean'],
        ]);

        foreach ($validated as $index => $data) {
            $otomatis = filter_var($data['tanggal_hari_otomatis'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($otomatis && empty($data['tanggal_mulai'])) {
                return response()->json([
                    'message' => 'Tanggal mulai wajib diisi jika opsi tanggal & hari otomatis diaktifkan.',
                    'errors' => [
                        $index => [
                            'tanggal_mulai' => ['Tanggal mulai wajib diisi.'],
                        ],
                    ],
                ], 422);
            }
        }

        foreach ($validated as $index => $data) {
            if (! empty($data['jam_mulai']) && ! empty($data['jam_selesai'])) {
                if (strtotime($data['jam_selesai']) <= strtotime($data['jam_mulai'])) {
                    return response()->json([
                        'message' => 'Jam selesai harus lebih besar dari jam mulai.',
                        'errors' => [
                            $index => [
                                'jam_selesai' => ['Jam selesai harus lebih besar dari jam mulai.'],
                            ],
                        ],
                    ], 422);
                }
            }
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                foreach ($validated as $data) {
                    $kelas = Kelas::find($data['id_kelas']);
                    if (! $kelas || ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                        abort(403, 'Anda tidak memiliki akses ke kelas ini.');
                    }
                }
            }
        }

        $results = [];
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($validated as $index => $data) {
                $n = (int) $data['jumlah_pertemuan'];
                $isActive = filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $dosenIds = $data['dosen'] ?? [];
                $ruanganId = $data['id_ruangan'] ?? null;
                $tanggalMulai = ! empty($data['tanggal_mulai']) ? $data['tanggal_mulai'] : null;
                $kelas = Kelas::find($data['id_kelas']);
                $tanggalHariOtomatis = filter_var($data['tanggal_hari_otomatis'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $isMingguan = ($kelas && $kelas->is_mingguan === true) || $tanggalHariOtomatis;

                for ($u = 1; $u <= $n; $u++) {
                    $slotQ = Jadwal::where('id_kelas', $data['id_kelas'])->where('urutan_pertemuan', $u);
                    if ($ruanganId) {
                        $slotQ->where('id_ruangan', $ruanganId);
                    } else {
                        $slotQ->whereNull('id_ruangan');
                    }
                    if ($slotQ->exists()) {
                        $errors[] = [
                            'index' => $index,
                            'message' => "Slot pertemuan ke-{$u} untuk kelas dan ruangan ini sudah terisi.",
                            'field' => 'jumlah_pertemuan',
                        ];

                        continue 2;
                    }
                }

                for ($u = 1; $u <= $n; $u++) {
                    $tanggal = null;
                    $hariSlot = $data['hari'] ?? null;
                    if ($tanggalMulai) {
                        if ($isMingguan) {
                            // addWeeks memakai kalender nyata (panjang bulan bervariasi tetap tepat per minggu)
                            $dt = Carbon::parse($tanggalMulai)->startOfDay()->addWeeks($u - 1);
                            $tanggal = $dt->format('Y-m-d');
                            if ($tanggalHariOtomatis || ($kelas && $kelas->is_mingguan === true)) {
                                $hariSlot = $this->hariDariTanggal($dt);
                            }
                        } else {
                            $tanggal = $u === 1 ? $tanggalMulai : null;
                        }
                    }
                    $jadwal = Jadwal::create([
                        'id_kelas' => $data['id_kelas'],
                        'id_jenis_kuliah' => $data['id_jenis_kuliah'] ?? null,
                        'tanggal' => $tanggal,
                        'hari' => $hariSlot,
                        'jam_mulai' => ! empty($data['jam_mulai']) ? $data['jam_mulai'] : null,
                        'jam_selesai' => ! empty($data['jam_selesai']) ? $data['jam_selesai'] : null,
                        'id_ruangan' => $ruanganId,
                        'urutan_pertemuan' => $u,
                        'is_active' => $isActive,
                    ]);

                    foreach ($dosenIds as $dosenId) {
                        $existing = JadwalDosen::withTrashed()
                            ->where('id_jadwal', $jadwal->id)
                            ->where('id_dosen', $dosenId)
                            ->first();
                        if ($existing) {
                            if ($existing->trashed()) {
                                $existing->restore();
                            }
                            $existing->update(['status' => 'active']);
                        } else {
                            JadwalDosen::create([
                                'id_jadwal' => $jadwal->id,
                                'id_dosen' => $dosenId,
                                'status' => 'active',
                            ]);
                        }
                    }

                    $jadwal->load([
                        'kelas.kurikulumMatkul.matkul',
                        'kelas.kurikulumMatkul.kurikulum',
                        'kelas.prodi',
                        'kelas.semester',
                        'jenisKuliah',
                        'ruangan',
                        'dosen.dosen',
                    ]);
                    $results[] = $jadwal;
                }
            }

            if (! empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Beberapa jadwal gagal disimpan karena slot pertemuan bentrok.',
                    'errors' => $errors,
                    'data' => $results,
                ], 422);
            }

            DB::commit();

            return response()->json($results, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan jadwal: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    public function edit(Request $request, Jadwal $jadwal): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $kelas = $jadwal->kelas ?? Kelas::find($jadwal->id_kelas);
            if (! $kelas) {
                abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
            }
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
            }
        }

        $jadwal->load([
            'kelas' => function ($query) {
                $query->withTrashed();
            },
            'kelas.kurikulumMatkul' => function ($query) {
                $query->withTrashed();
            },
            'kelas.kurikulumMatkul.matkul' => function ($query) {
                $query->withTrashed();
            },
            'kelas.kurikulumMatkul.kurikulum' => function ($query) {
                $query->withTrashed();
            },
            'kelas.prodi' => function ($query) {
                $query->withTrashed();
            },
            'kelas.semester' => function ($query) {
                $query->withTrashed();
            },
            'jenisKuliah' => function ($query) {
                $query->withTrashed();
            },
            'ruangan' => function ($query) {
                $query->withTrashed();
            },
            'dosen' => function ($query) {
                $query->withTrashed();
            },
            'dosen.dosen' => function ($query) {
                $query->withTrashed();
            },
        ]);

        return response()->json($jadwal);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $jadwal = Jadwal::with([
            'kelas' => function ($query) {
                $query->withTrashed();
            },
            'kelas.kurikulumMatkul' => function ($query) {
                $query->withTrashed();
            },
            'kelas.kurikulumMatkul.matkul' => function ($query) {
                $query->withTrashed();
            },
            'kelas.kurikulumMatkul.kurikulum' => function ($query) {
                $query->withTrashed();
            },
            'kelas.prodi' => function ($query) {
                $query->withTrashed();
            },
            'kelas.semester' => function ($query) {
                $query->withTrashed();
            },
            'jenisKuliah' => function ($query) {
                $query->withTrashed();
            },
            'ruangan' => function ($query) {
                $query->withTrashed();
            },
            'dosen' => function ($query) {
                $query->withTrashed();
            },
            'dosen.dosen' => function ($query) {
                $query->withTrashed();
            },
        ])->find($id);
        if (! $jadwal) {
            return response()->json(['message' => 'Jadwal tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $kelas = $jadwal->kelas ?? Kelas::find($jadwal->id_kelas);
            if ($kelas) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
                }
            }
        }

        return response()->json($jadwal);
    }

    /**
     * Detail jadwal untuk admin: info slot, riwayat perkuliahan, kehadiran mahasiswa pada sesi utama.
     */
    public function detail(Request $request, Jadwal $jadwal): JsonResponse
    {
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $kelasCheck = $jadwal->kelas ?? Kelas::find($jadwal->id_kelas);
            if ($kelasCheck) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $kelasCheck->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
                }
            }
        }

        $jadwal->load([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.prodi.jenjang',
            'kelas.semester',
            'jenisKuliah',
            'ruangan',
            'dosen.dosen',
        ]);

        $perkuliahanRows = Perkuliahan::where('id_jadwal', $jadwal->id)
            ->whereNull('deleted_at')
            ->orderByRaw('waktu_mulai IS NULL')
            ->orderBy('waktu_mulai', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $perkuliahanAktif = $this->findPerkuliahanForJadwalSlot($jadwal, $perkuliahanRows);
        $sesi = $this->sesiStatusForPerkuliahan($perkuliahanAktif);

        $jadwalData = $jadwal->toArray();
        $jadwalData['sesi_status'] = $sesi['sesi_status'];
        $jadwalData['sesi_status_label'] = $sesi['sesi_status_label'];

        $perkuliahanFormatted = $perkuliahanRows->map(function (Perkuliahan $p) {
            $attrs = $p->getAttributes();
            $status = $this->sesiStatusForPerkuliahan($p);

            return [
                'id' => $p->id,
                'tanggal' => $p->waktu_mulai ? Carbon::parse($p->waktu_mulai)->format('Y-m-d') : null,
                'waktu_mulai' => $p->waktu_mulai?->format('Y-m-d H:i:s'),
                'waktu_selesai' => $p->waktu_selesai?->format('Y-m-d H:i:s'),
                'materi' => $p->materi,
                'realisasi_materi' => $attrs['realisasi_materi'] ?? null,
                'sesi_status' => $status['sesi_status'],
                'sesi_status_label' => $status['sesi_status_label'],
            ];
        })->values()->all();

        $kehadiran = [];
        if ($perkuliahanAktif) {
            $kelasId = (int) $jadwal->id_kelas;
            $kehadiran = Krs::with([
                'mahasiswa:id,nim,nama,id_prodi',
                'mahasiswa.prodi:id,nama',
            ])
                ->where('id_kelas', $kelasId)
                ->whereNotNull('approved_at')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->map(function ($krs) use ($perkuliahanAktif) {
                    $row = Kehadiran::where('id_perkuliahan', $perkuliahanAktif->id)
                        ->where('id_mhs', $krs->id_mahasiswa)
                        ->whereNull('deleted_at')
                        ->first();

                    return [
                        'id_krs' => $krs->id,
                        'mahasiswa' => [
                            'id' => $krs->mahasiswa->id,
                            'nim' => $krs->mahasiswa->nim,
                            'nama' => $krs->mahasiswa->nama,
                            'prodi' => $krs->mahasiswa->prodi ? [
                                'id' => $krs->mahasiswa->prodi->id,
                                'nama' => $krs->mahasiswa->prodi->nama,
                            ] : null,
                        ],
                        'kehadiran' => $row ? [
                            'id' => $row->id,
                            'status' => $row->status,
                            'keterangan' => $row->keterangan,
                        ] : null,
                    ];
                })
                ->values()
                ->all();
        }

        return response()->json([
            'jadwal' => $jadwalData,
            'perkuliahan' => $perkuliahanFormatted,
            'perkuliahan_aktif_id' => $perkuliahanAktif?->id,
            'kehadiran' => $kehadiran,
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $jadwal = Jadwal::with('kelas')->find($id);
        if (! $jadwal) {
            return response()->json(['message' => 'Jadwal tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $kelas = $jadwal->kelas ?? Kelas::find($jadwal->id_kelas);
            if (! $kelas) {
                abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
            }
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
            }
        }

        $validated = $request->validate([
            'id_kelas' => ['sometimes', 'required', 'integer', 'exists:kelas,id'],
            'urutan_pertemuan' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'is_active' => ['sometimes', 'boolean'],
            'id_jenis_kuliah' => ['nullable', 'integer', 'exists:jenis_kuliah,id'],
            'hari' => ['nullable', 'string', Rule::in(Jadwal::HARI)],
            'tanggal' => ['nullable', 'date'],
            'jam_mulai' => ['nullable', 'string'],
            'jam_selesai' => ['nullable', 'string'],
            'id_ruangan' => ['nullable', 'integer', 'exists:ruangan,id'],
            'dosen' => ['nullable', 'array'],
            'dosen.*' => ['integer', 'exists:dosen,id'],
        ]);

        foreach (['jam_mulai', 'jam_selesai', 'tanggal'] as $jk) {
            if (array_key_exists($jk, $validated) && ($validated[$jk] === '' || $validated[$jk] === null)) {
                $validated[$jk] = null;
            }
        }
        foreach (['jam_mulai', 'jam_selesai'] as $jk) {
            if (! empty($validated[$jk]) && ! preg_match('/^\d{2}:\d{2}$/', $validated[$jk])) {
                return response()->json([
                    'message' => 'Format jam tidak valid (gunakan HH:MM).',
                    'errors' => [$jk => ['Format tidak valid.']],
                ], 422);
            }
        }

        $jamMulai = $validated['jam_mulai'] ?? $jadwal->jam_mulai;
        $jamSelesai = $validated['jam_selesai'] ?? $jadwal->jam_selesai;
        if ($jamMulai && $jamSelesai && strtotime($jamSelesai) <= strtotime($jamMulai)) {
            return response()->json([
                'message' => 'Jam selesai harus lebih besar dari jam mulai.',
                'errors' => ['jam_selesai' => ['Jam selesai harus lebih besar dari jam mulai.']],
            ], 422);
        }

        $idKelas = isset($validated['id_kelas']) ? (int) $validated['id_kelas'] : (int) $jadwal->id_kelas;
        $urutan = isset($validated['urutan_pertemuan']) ? (int) $validated['urutan_pertemuan'] : (int) $jadwal->urutan_pertemuan;
        $idRuangan = array_key_exists('id_ruangan', $validated)
            ? $validated['id_ruangan']
            : $jadwal->id_ruangan;

        $dupQ = Jadwal::where('id_kelas', $idKelas)
            ->where('urutan_pertemuan', $urutan)
            ->where('id', '!=', $jadwal->id);
        if ($idRuangan) {
            $dupQ->where('id_ruangan', $idRuangan);
        } else {
            $dupQ->whereNull('id_ruangan');
        }
        if ($dupQ->exists()) {
            return response()->json([
                'message' => 'Sudah ada jadwal untuk kelas, ruangan, dan urutan pertemuan ini.',
                'errors' => ['urutan_pertemuan' => ['Slot bentrok.']],
            ], 422);
        }

        if ($user && $user->hasScopeRestriction() && array_key_exists('id_kelas', $validated)) {
            $newKelas = Kelas::find($validated['id_kelas']);
            if (! $newKelas) {
                abort(404, 'Kelas tidak ditemukan.');
            }
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $newKelas->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke kelas ini.');
            }
        }

        // Extract dosen array
        $dosenIds = $validated['dosen'] ?? null;
        unset($validated['dosen']);

        DB::beginTransaction();
        try {
            $jadwal->update($validated);

            // Update jadwal_dosen jika dosen diberikan
            if ($dosenIds !== null) {
                // Hapus jadwal_dosen yang tidak ada di array baru (force delete untuk menghindari unique constraint issue)
                JadwalDosen::withTrashed()
                    ->where('id_jadwal', $jadwal->id)
                    ->whereNotIn('id_dosen', $dosenIds)
                    ->forceDelete();

                // Update atau create jadwal_dosen untuk setiap dosen di array baru
                foreach ($dosenIds as $dosenId) {
                    // Cek apakah sudah ada (termasuk soft deleted)
                    $existing = JadwalDosen::withTrashed()
                        ->where('id_jadwal', $jadwal->id)
                        ->where('id_dosen', $dosenId)
                        ->first();

                    if ($existing) {
                        // Restore dan update jika soft deleted
                        if ($existing->trashed()) {
                            $existing->restore();
                        }
                        $existing->update(['status' => 'active']);
                    } else {
                        // Create baru jika belum ada
                        JadwalDosen::create([
                            'id_jadwal' => $jadwal->id,
                            'id_dosen' => $dosenId,
                            'status' => 'active',
                        ]);
                    }
                }
            }

            $jadwal->load([
                'kelas.kurikulumMatkul.matkul',
                'kelas.kurikulumMatkul.kurikulum',
                'kelas.prodi',
                'kelas.semester',
                'jenisKuliah',
                'ruangan',
                'dosen.dosen',
            ]);

            DB::commit();

            return response()->json($jadwal);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan saat memperbarui jadwal: '.$e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $jadwal = Jadwal::with('kelas')->find($id);
        if (! $jadwal) {
            return response()->json(['message' => 'Jadwal tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $kelas = $jadwal->kelas ?? Kelas::find($jadwal->id_kelas);
            if ($kelas) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke jadwal ini.');
                }
            }
        }

        $jadwal->delete();

        return response()->json(['message' => 'Jadwal dihapus']);
    }

    public function getKelasOptions(Request $request): JsonResponse
    {
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi');
        $semesterId = $request->get('id_semester');
        $perPage = (int) $request->get('per_page', 100);

        $query = Kelas::with(['kurikulumMatkul.matkul', 'kurikulumMatkul.kurikulum', 'prodi', 'semester']);

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
                });
            });
        }

        if ($prodiId) {
            $query->where('id_prodi', $prodiId);
        }

        if ($semesterId) {
            $query->where('id_semester', $semesterId);
        }

        $kelompokKelasId = $request->get('id_kelompok_kelas');
        if ($kelompokKelasId !== null && $kelompokKelasId !== '') {
            if ((int) $kelompokKelasId === 0) {
                $query->whereNull('id_kelompok_kelas');
            } else {
                $query->where('id_kelompok_kelas', (int) $kelompokKelasId);
            }
        }

        $data = $query->orderBy('id')->paginate($perPage);

        return response()->json($data);
    }

    /**
     * Dosen pengampu kelas (kelas_dosen) untuk pilihan jadwal.
     */
    public function getDosenByKelasForJadwal(Request $request): JsonResponse
    {
        $kelasId = (int) $request->get('id_kelas', 0);
        if ($kelasId < 1) {
            return response()->json(['data' => []]);
        }

        $kelas = Kelas::find($kelasId);
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

        $rows = KelasDosen::query()
            ->where('id_kelas', $kelasId)
            ->with('dosen')
            ->orderBy('id')
            ->get();

        $seen = [];
        $data = [];
        foreach ($rows as $row) {
            $d = $row->dosen;
            if (! $d || isset($seen[$d->id])) {
                continue;
            }
            $seen[$d->id] = true;
            $data[] = $d;
        }

        return response()->json(['data' => $data]);
    }

    public function getKelompokKelasOptions(Request $request): JsonResponse
    {
        $search = $request->get('search');
        $perPage = (int) $request->get('per_page', 100);
        $prodiIdRaw = $request->get('id_prodi');
        $semesterIdRaw = $request->get('id_semester');

        $query = KelompokKelas::query();

        $adaTanpaKelompok = false;
        $appendProdiMeta = false;
        if ($prodiIdRaw !== null && $prodiIdRaw !== '') {
            $appendProdiMeta = true;
            $idProdi = (int) $prodiIdRaw;
            $kelasDalamScope = function () use ($idProdi, $semesterIdRaw) {
                $q = Kelas::query()
                    ->where('id_prodi', $idProdi)
                    ->whereNull('deleted_at');
                if ($semesterIdRaw !== null && $semesterIdRaw !== '') {
                    $q->where('id_semester', (int) $semesterIdRaw);
                }

                return $q;
            };
            $idsKelompok = $kelasDalamScope()
                ->whereNotNull('id_kelompok_kelas')
                ->distinct()
                ->pluck('id_kelompok_kelas');
            $query->whereIn('id', $idsKelompok);
            $adaTanpaKelompok = $kelasDalamScope()
                ->whereNull('id_kelompok_kelas')
                ->exists();
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('nama')->paginate($perPage);
        $payload = $paginator->toArray();
        if ($appendProdiMeta) {
            $payload['ada_tanpa_kelompok'] = $adaTanpaKelompok;
        }

        return response()->json($payload);
    }

    /**
     * Export jadwal ke Excel sesuai filter (search, id_prodi, id_semester, id_kelas).
     * Jika id_kelas diisi, hanya jadwal kelas tersebut; jika tidak, semua yang memenuhi filter lain.
     */
    public function export(Request $request): StreamedResponse
    {
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi');
        $semesterId = $request->get('id_semester');
        $kelasId = $request->get('id_kelas');

        $query = Jadwal::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'ruangan',
            'dosen.dosen',
        ])->whereHas('kelas', function ($q) {
            $q->whereNull('deleted_at');
        });

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('kelas', function ($q) use ($allowedProdiIds) {
                    $q->whereIn('id_prodi', $allowedProdiIds);
                });
                if ($prodiId && ! in_array((int) $prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode', 'like', "%{$search}%");
                })
                    ->orWhereHas('ruangan', function ($q) use ($search) {
                        $q->where('nama', 'like', "%{$search}%");
                    })
                    ->orWhere('hari', 'like', "%{$search}%");
            });
        }

        if ($prodiId) {
            $query->whereHas('kelas', function ($q) use ($prodiId) {
                $q->where('id_prodi', $prodiId);
            });
        }

        if ($semesterId) {
            $query->whereHas('kelas', function ($q) use ($semesterId) {
                $q->where('id_semester', $semesterId);
            });
        }

        if ($kelasId !== null && $kelasId !== '') {
            $query->where('id_kelas', (int) $kelasId);
        }

        $items = $query->orderBy('id_kelas')->orderBy('urutan_pertemuan')->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Mata Kuliah (Kode)',
            'Nama Mata Kuliah',
            'Prodi',
            'Semester Kelas',
            'Pertemuan ke-',
            'Aktif',
            'Hari',
            'Jam Mulai',
            'Jam Selesai',
            'Ruang',
            'Dosen',
        ];
        $sheet->fromArray([$headers], null, 'A1');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:K1')->applyFromArray($headerStyle);

        $rowNum = 2;
        foreach ($items as $row) {
            $matkul = $row->kelas->kurikulumMatkul->matkul ?? null;
            $prodi = $row->kelas->prodi ?? null;
            $semester = $row->kelas->semester ?? null;
            $ruangan = $row->ruangan ?? null;
            $dosenList = $row->dosen ?? [];
            $dosenNames = [];
            foreach ($dosenList as $jd) {
                $d = $jd->dosen ?? null;
                if ($d) {
                    $dosenNames[] = $d->nama.($d->kode_dosen ? ' ('.$d->kode_dosen.')' : '');
                }
            }

            $sheet->fromArray([[
                $matkul ? $matkul->kode : '',
                $matkul ? $matkul->nama : '',
                $prodi ? $prodi->nama : '',
                $semester ? $semester->nama : '',
                $row->urutan_pertemuan ?? '',
                $row->is_active ? 'ya' : 'tidak',
                $row->hari ?? '',
                $row->jam_mulai ?? '',
                $row->jam_selesai ?? '',
                $ruangan ? $ruangan->nama : '',
                implode(', ', $dosenNames),
            ]], null, 'A'.$rowNum);
            $rowNum++;
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'export_jadwal_'.date('YmdHis').'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="'.$filename.'"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'Kode Semester (kelas)',
            'Kode Mata Kuliah',
            'Nama Kelas Mahasiswa (kosong jika tanpa kelas mahasiswa)',
            'Pertemuan ke- (1-99, opsional)',
            'Tgl Kuliah (YYYY-MM-DD, opsional)',
            'Nama Jenis Kuliah (opsional)',
            'Aktif (ya/tidak)',
            'Hari',
            'Jam Mulai (HH:MM)',
            'Jam Selesai (HH:MM)',
            'Nama Ruangan (opsional)',
            'Kode/NIDN Dosen (beberapa: pisah koma)',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
        $widths = [22, 18, 36, 18, 28, 14, 12, 18, 18, 22, 42, 28];

        foreach ($columns as $index => $col) {
            if (isset($widths[$index])) {
                $sheet->getColumnDimension($col)->setWidth($widths[$index]);
            }
        }

        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:K1')->applyFromArray($headerStyle);

        $exampleRow = [
            '20241',
            'MK001',
            'Kelompok A',
            '1',
            '2026-01-01',
            'Teori',
            'tidak',
            'senin',
            '08:00',
            '10:00',
            'A101',
            'DSN001, 0123456789',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_jadwal_'.date('YmdHis').'.xlsx';

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

                $kodeSemester = trim((string) ($row[0] ?? ''));
                $kodeMatkul = trim((string) ($row[1] ?? ''));
                $namaKelompokKelas = trim((string) ($row[2] ?? ''));
                $urutanRaw = trim((string) ($row[3] ?? ''));
                $tglKuliah = trim((string) ($row[4] ?? ''));
                $namaJenisKuliah = trim((string) ($row[5] ?? ''));
                $aktifRaw = trim(strtolower((string) ($row[6] ?? '')));
                $hari = trim(strtolower((string) ($row[7] ?? '')));
                $jamMulai = trim((string) ($row[8] ?? ''));
                $jamSelesai = trim((string) ($row[9] ?? ''));
                $namaRuangan = trim((string) ($row[10] ?? ''));
                $kodeDosenRaw = trim((string) ($row[11] ?? ''));

                if ($kodeSemester === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Semester Kelas wajib diisi.";

                    continue;
                }
                if ($kodeMatkul === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Mata Kuliah wajib diisi.";

                    continue;
                }
                // Opsional: kosong atau di luar 1-99 disimpan null, bukan alasan untuk melewati
                // baris — lihat catatan duplikat-slot di bawah untuk konsekuensinya.
                $urutan = ($urutanRaw !== '' && ctype_digit($urutanRaw) && (int) $urutanRaw >= 1 && (int) $urutanRaw <= 99)
                    ? (int) $urutanRaw
                    : null;

                $semester = Semester::where('kode', $kodeSemester)->first();
                if (! $semester) {
                    $errors[] = "Baris {$rowNumber}: Semester '{$kodeSemester}' tidak ditemukan.";

                    continue;
                }

                $kurikulumMatkulIds = KurikulumMatkul::query()
                    ->where('kode_matkul', $kodeMatkul)
                    ->pluck('id');
                if ($kurikulumMatkulIds->isEmpty()) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah dengan kode '{$kodeMatkul}' tidak ditemukan di kurikulum.";

                    continue;
                }

                $kelasQuery = Kelas::query()
                    ->whereIn('id_kurikulum_matkul', $kurikulumMatkulIds)
                    ->where('id_semester', $semester->id);

                if ($namaKelompokKelas !== '') {
                    $kelompok = KelompokKelas::query()
                        ->whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower($namaKelompokKelas)])
                        ->first();
                    if (! $kelompok) {
                        $errors[] = "Baris {$rowNumber}: Kelas mahasiswa '{$namaKelompokKelas}' tidak ditemukan.";

                        continue;
                    }
                    $kelasQuery->where('id_kelompok_kelas', $kelompok->id);
                } else {
                    $kelasQuery->whereNull('id_kelompok_kelas');
                }

                $kelasCandidates = $kelasQuery->get();
                if ($kelasCandidates->isEmpty()) {
                    $errors[] = "Baris {$rowNumber}: Kelas tidak ditemukan untuk kombinasi semester, kode mata kuliah, dan nama kelas mahasiswa (kosong = tanpa kelas mahasiswa).";

                    continue;
                }
                if ($kelasCandidates->count() > 1) {
                    $errors[] = "Baris {$rowNumber}: Ditemukan {$kelasCandidates->count()} baris kelas yang cocok — perjelas di data master kelas (mis. kelompok atau kombinasi unik).";

                    continue;
                }
                $kelas = $kelasCandidates->first();

                if ($allowedProdiIds !== null && ! in_array((int) $kelas->id_prodi, $allowedProdiIds, true)) {
                    $errors[] = "Baris {$rowNumber}: Tidak ada akses ke prodi ini.";
                    $skipCount++;

                    continue;
                }

                $jenisKuliahId = null;
                if ($namaJenisKuliah !== '') {
                    $jk = JenisKuliah::query()
                        ->whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower($namaJenisKuliah)])
                        ->first();
                    if (! $jk) {
                        $errors[] = "Baris {$rowNumber}: Jenis kuliah '{$namaJenisKuliah}' tidak ditemukan.";

                        continue;
                    }
                    $jenisKuliahId = $jk->id;
                }

                $isActive = in_array($aktifRaw, ['ya', 'y', '1', 'true', 'yes'], true);

                if ($jamMulai !== '' && $jamSelesai !== '' && strtotime($jamSelesai) <= strtotime($jamMulai)) {
                    $errors[] = "Baris {$rowNumber}: Jam selesai harus setelah jam mulai.";

                    continue;
                }

                $ruanganId = null;
                if ($namaRuangan !== '') {
                    $ruangan = Ruangan::where('nama', 'like', '%'.$namaRuangan.'%')->first();
                    if ($ruangan) {
                        $ruanganId = $ruangan->id;
                    }
                }

                $dosenIds = [];
                if ($kodeDosenRaw !== '') {
                    foreach (preg_split('/[,;]/', $kodeDosenRaw) as $token) {
                        $token = trim($token);
                        if ($token === '') {
                            continue;
                        }
                        $dosen = Dosen::query()
                            ->where(function ($q) use ($token) {
                                $q->where('kode_dosen', $token)
                                    ->orWhere('nidn', $token);
                            })
                            ->first();
                        if (! $dosen) {
                            $errors[] = "Baris {$rowNumber}: Dosen dengan kode/NIDN '{$token}' tidak ditemukan.";

                            continue 2;
                        }
                        if (! in_array((int) $dosen->id, $dosenIds, true)) {
                            $dosenIds[] = (int) $dosen->id;
                        }
                    }
                }

                // Cek slot duplikat cuma masuk akal kalau urutan_pertemuan terisi — constraint
                // unique DB (id_kelas, id_ruangan, urutan_pertemuan) sendiri menganggap NULL tidak
                // pernah sama dengan NULL lain, jadi beberapa baris "pertemuan ke-" kosong untuk
                // kelas yang sama memang boleh dan harus tetap dibuat, bukan dianggap duplikat.
                if ($urutan !== null) {
                    $slotQ = Jadwal::where('id_kelas', $kelas->id)->where('urutan_pertemuan', $urutan);
                    if ($ruanganId) {
                        $slotQ->where('id_ruangan', $ruanganId);
                    } else {
                        $slotQ->whereNull('id_ruangan');
                    }
                    if ($slotQ->exists()) {
                        $skipCount++;
                        $errors[] = "Baris {$rowNumber}: Slot pertemuan {$urutan} sudah ada (diabaikan).";

                        continue;
                    }
                }

                $jadwal = Jadwal::create([
                    'id_kelas' => $kelas->id,
                    'id_jenis_kuliah' => $jenisKuliahId,
                    'tanggal' => $tglKuliah !== '' ? $tglKuliah : null,
                    'hari' => $hari !== '' ? $hari : null,
                    'jam_mulai' => $jamMulai !== '' ? $jamMulai : null,
                    'jam_selesai' => $jamSelesai !== '' ? $jamSelesai : null,
                    'id_ruangan' => $ruanganId,
                    'urutan_pertemuan' => $urutan,
                    'is_active' => $isActive,
                ]);

                // Create jadwal_dosen records
                foreach ($dosenIds as $dosenId) {
                    // Cek apakah sudah ada (termasuk soft deleted)
                    $existing = JadwalDosen::withTrashed()
                        ->where('id_jadwal', $jadwal->id)
                        ->where('id_dosen', $dosenId)
                        ->first();

                    if ($existing) {
                        // Restore dan update jika soft deleted
                        if ($existing->trashed()) {
                            $existing->restore();
                        }
                        $existing->update(['status' => 'active']);
                    } else {
                        // Create baru jika belum ada
                        JadwalDosen::create([
                            'id_jadwal' => $jadwal->id,
                            'id_dosen' => $dosenId,
                            'status' => 'active',
                        ]);
                    }
                }

                $processedRows[] = [
                    'row' => $rowNumber,
                    'id' => $jadwal->id,
                ];
                $successCount++;
            }

            if (! empty($errors) && $successCount === 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang berhasil diimport.',
                    'errors' => $errors,
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Berhasil mengimport {$successCount} jadwal.".($skipCount > 0 ? " {$skipCount} jadwal diabaikan karena duplikasi." : ''),
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
}
