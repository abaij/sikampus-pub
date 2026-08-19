<?php

namespace App\Http\Controllers;

use App\Models\Jadwal;
use App\Models\Kehadiran;
use App\Models\Kelas;
use App\Models\Krs;
use App\Models\KurikulumMatkul;
use App\Models\Mahasiswa;
use App\Models\Matkul;
use App\Models\MatkulPrasyarat;
use App\Models\Nilai;
use App\Models\Notifikasi;
use App\Models\Perkuliahan;
use App\Models\Semester;
use App\Services\KeuanganAksesMahasiswaService;
use App\Services\UrutanMatkulService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KrsController extends Controller
{
    /**
     * Mahasiswa yang tidak memiliki baris KRS untuk kelas pada semester tertentu.
     *
     * @param  array<int>|null  $allowedProdiIds  jika tidak null, batasi ke prodi ini
     */
    private function mahasiswaTanpaKrsSemesterQuery(
        int $semesterId,
        ?string $search,
        ?int $prodiId,
        mixed $semesterMasukId,
        mixed $grupMahasiswaId,
        ?array $allowedProdiIds
    ): \Illuminate\Database\Eloquent\Builder {
        $q = Mahasiswa::query()
            ->select([
                'mahasiswa.id as id_mahasiswa',
                'mahasiswa.nim',
                'mahasiswa.nama',
                'prodi.id as id_prodi',
                'prodi.nama as prodi_nama',
                'prodi.id_jenjang',
            ])
            ->join('prodi', 'mahasiswa.id_prodi', '=', 'prodi.id')
            ->whereNull('mahasiswa.deleted_at')
            ->whereNull('prodi.deleted_at')
            ->whereNotExists(function ($sub) use ($semesterId): void {
                $sub->select(DB::raw('1'))
                    ->from('krs')
                    ->join('kelas', 'krs.id_kelas', '=', 'kelas.id')
                    ->whereColumn('krs.id_mahasiswa', 'mahasiswa.id')
                    ->where('kelas.id_semester', $semesterId)
                    ->whereNull('krs.deleted_at');
            });

        if ($allowedProdiIds !== null) {
            $q->whereIn('mahasiswa.id_prodi', $allowedProdiIds);
        }

        if ($search && trim((string) $search) !== '') {
            $s = trim((string) $search);
            $q->where(function ($w) use ($s): void {
                $w->where('mahasiswa.nama', 'like', "%{$s}%")
                    ->orWhere('mahasiswa.nim', 'like', "%{$s}%");
            });
        }

        if ($prodiId) {
            $q->where('mahasiswa.id_prodi', $prodiId);
        }

        if ($semesterMasukId) {
            $q->where('mahasiswa.id_semester_masuk', $semesterMasukId);
        }

        if ($grupMahasiswaId) {
            $q->where('mahasiswa.id_grup_mahasiswa', $grupMahasiswaId);
        }

        return $q;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $results
     * @return array<int, array<string, mixed>>
     */
    private function mapKrsIndexRows(Collection $results, array $dosenWaliData, array $jenjangData): array
    {
        return $results->map(function ($item) use ($dosenWaliData, $jenjangData) {
            return [
                'id_mahasiswa' => (int) $item->id_mahasiswa,
                'nim' => $item->nim,
                'nama' => $item->nama,
                'prodi' => [
                    'id' => (int) $item->id_prodi,
                    'nama' => $item->prodi_nama,
                    'jenjang' => $jenjangData[$item->id_prodi] ?? null,
                ],
                'dosen_wali' => $dosenWaliData[$item->id_mahasiswa] ?? '-',
                'sks_diajukan' => isset($item->sks_diajukan) ? (int) $item->sks_diajukan : 0,
                'sks_diacc' => isset($item->sks_diacc) ? (int) $item->sks_diacc : 0,
                'total_kelas' => isset($item->total_kelas) ? (int) $item->total_kelas : 0,
            ];
        })->values()->all();
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi') ? (int) $request->get('id_prodi') : null;
        $semesterMasukId = $request->get('id_semester_masuk');
        $semesterId = $request->get('id_semester') ? (int) $request->get('id_semester') : null;
        $grupMahasiswaId = $request->get('id_grup_mahasiswa');
        $rawStatus = $request->get('status_pengajuan');
        $statusPengajuan = in_array($rawStatus, ['belum_mengajukan', 'ada_belum_acc', 'sudah_acc_semua'], true)
            ? $rawStatus
            : null;

        $user = $request->user();
        $allowedProdiIds = null;
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && $prodiId && ! in_array($prodiId, $allowedProdiIds, true)) {
                $prodiId = null;
            }
        }

        if ($statusPengajuan === 'belum_mengajukan') {
            if (! $semesterId) {
                return response()->json([
                    'message' => 'Parameter id_semester wajib untuk status belum mengajukan.',
                ], 422);
            }

            $mQuery = $this->mahasiswaTanpaKrsSemesterQuery(
                $semesterId,
                $search ? (string) $search : null,
                $prodiId,
                $semesterMasukId,
                $grupMahasiswaId,
                $allowedProdiIds
            );

            $total = (clone $mQuery)->count();
            $page = (int) $request->get('page', 1);
            $offset = ($page - 1) * $perPage;

            $results = $mQuery->orderBy('mahasiswa.nim')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $mahasiswaIds = $results->pluck('id_mahasiswa')->toArray();
            $dosenWaliData = [];
            if (! empty($mahasiswaIds)) {
                $dosenWaliResults = DB::table('dosen_wali')
                    ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
                    ->whereIn('dosen_wali.id_mahasiswa', $mahasiswaIds)
                    ->where('dosen_wali.status', 'active')
                    ->whereNull('dosen_wali.deleted_at')
                    ->select('dosen_wali.id_mahasiswa', 'dosen.nama as dosen_nama')
                    ->get();

                foreach ($dosenWaliResults as $dw) {
                    $dosenWaliData[$dw->id_mahasiswa] = $dw->dosen_nama;
                }
            }

            $prodiIds = $results->pluck('id_prodi')->filter()->unique()->toArray();
            $jenjangData = [];
            if (! empty($prodiIds)) {
                $jenjangResults = DB::table('prodi')
                    ->join('jenjang', 'prodi.id_jenjang', '=', 'jenjang.id')
                    ->whereIn('prodi.id', $prodiIds)
                    ->whereNull('prodi.deleted_at')
                    ->whereNull('jenjang.deleted_at')
                    ->select('prodi.id as prodi_id', 'jenjang.id as jenjang_id', 'jenjang.kode as jenjang_kode', 'jenjang.nama as jenjang_nama')
                    ->get();

                foreach ($jenjangResults as $j) {
                    $jenjangData[$j->prodi_id] = [
                        'id' => $j->jenjang_id,
                        'kode' => $j->jenjang_kode,
                        'nama' => $j->jenjang_nama,
                    ];
                }
            }

            $data = $this->mapKrsIndexRows($results, $dosenWaliData, $jenjangData);
            $lastPage = (int) ceil($total / $perPage) ?: 1;
            $from = $total > 0 ? $offset + 1 : 0;
            $to = min($offset + $perPage, $total);

            return response()->json([
                'data' => $data,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
            ]);
        }

        // Query untuk mendapatkan data KRS yang dikelompokkan per mahasiswa
        $query = Krs::select([
            'krs.id_mahasiswa',
            DB::raw('MAX(mahasiswa.nim) as nim'),
            DB::raw('MAX(mahasiswa.nama) as nama'),
            DB::raw('MAX(prodi.id) as id_prodi'),
            DB::raw('MAX(prodi.nama) as prodi_nama'),
            DB::raw('MAX(prodi.id_jenjang) as id_jenjang'),
            DB::raw('COUNT(DISTINCT krs.id) as total_kelas'),
            DB::raw('COALESCE(SUM(CASE WHEN krs.approved_at IS NOT NULL THEN matkul.sks ELSE 0 END), 0) as sks_diacc'),
            DB::raw('COALESCE(SUM(matkul.sks), 0) as sks_diajukan'),
        ])
            ->join('mahasiswa', 'krs.id_mahasiswa', '=', 'mahasiswa.id')
            ->join('prodi', 'mahasiswa.id_prodi', '=', 'prodi.id')
            ->join('kelas', 'krs.id_kelas', '=', 'kelas.id')
            ->join('kurikulum_matkul', 'kelas.id_kurikulum_matkul', '=', 'kurikulum_matkul.id')
            ->join('matkul', 'kurikulum_matkul.id_matkul', '=', 'matkul.id')
            ->whereNull('krs.deleted_at')
            ->whereNull('mahasiswa.deleted_at')
            ->groupBy('krs.id_mahasiswa');

        if ($allowedProdiIds !== null) {
            $query->whereIn('mahasiswa.id_prodi', $allowedProdiIds);
        }

        // Filter berdasarkan pencarian nama atau nim
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('mahasiswa.nama', 'like', "%{$search}%")
                    ->orWhere('mahasiswa.nim', 'like', "%{$search}%");
            });
        }

        // Filter berdasarkan prodi
        if ($prodiId) {
            $query->where('mahasiswa.id_prodi', $prodiId);
        }

        // Filter berdasarkan semester masuk mahasiswa
        if ($semesterMasukId) {
            $query->where('mahasiswa.id_semester_masuk', $semesterMasukId);
        }

        // Filter berdasarkan semester kelas (semester dari kelas KRS)
        if ($semesterId) {
            $query->where('kelas.id_semester', $semesterId);
        }

        if ($grupMahasiswaId) {
            $query->where('mahasiswa.id_grup_mahasiswa', $grupMahasiswaId);
        }

        if ($statusPengajuan === 'ada_belum_acc') {
            $query->havingRaw('SUM(CASE WHEN krs.approved_at IS NULL THEN 1 ELSE 0 END) > 0');
        } elseif ($statusPengajuan === 'sudah_acc_semua') {
            $query->havingRaw('COUNT(DISTINCT krs.id) > 0 AND SUM(CASE WHEN krs.approved_at IS NULL THEN 1 ELSE 0 END) = 0');
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

        // Ambil dosen wali untuk setiap mahasiswa
        $mahasiswaIds = $results->pluck('id_mahasiswa')->toArray();
        $dosenWaliData = [];
        if (! empty($mahasiswaIds)) {
            $dosenWaliResults = DB::table('dosen_wali')
                ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
                ->whereIn('dosen_wali.id_mahasiswa', $mahasiswaIds)
                ->where('dosen_wali.status', 'active')
                ->whereNull('dosen_wali.deleted_at')
                ->select('dosen_wali.id_mahasiswa', 'dosen.nama as dosen_nama')
                ->get();

            foreach ($dosenWaliResults as $dw) {
                $dosenWaliData[$dw->id_mahasiswa] = $dw->dosen_nama;
            }
        }

        // Ambil data jenjang untuk setiap prodi
        $prodiIds = $results->pluck('id_prodi')->filter()->unique()->toArray();
        $jenjangData = [];
        if (! empty($prodiIds)) {
            $jenjangResults = DB::table('prodi')
                ->join('jenjang', 'prodi.id_jenjang', '=', 'jenjang.id')
                ->whereIn('prodi.id', $prodiIds)
                ->whereNull('prodi.deleted_at')
                ->whereNull('jenjang.deleted_at')
                ->select('prodi.id as prodi_id', 'jenjang.id as jenjang_id', 'jenjang.kode as jenjang_kode', 'jenjang.nama as jenjang_nama')
                ->get();

            foreach ($jenjangResults as $j) {
                $jenjangData[$j->prodi_id] = [
                    'id' => $j->jenjang_id,
                    'kode' => $j->jenjang_kode,
                    'nama' => $j->jenjang_nama,
                ];
            }
        }

        $data = $this->mapKrsIndexRows($results, $dosenWaliData, $jenjangData);

        $lastPage = (int) ceil($total / $perPage);
        $from = $total > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $total);

        return response()->json([
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Daftar KRS mahasiswa untuk admin prodi (hanya mahasiswa di scope prodi user).
     * Query: id_semester (periode), id_semester_masuk (angkatan), id_grup_mahasiswa, search, per_page, page.
     */
    public function indexProdi(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowedProdiIds = $user && $user->hasScopeRestriction() ? $user->getAllowedProdiIds() : null;
        if ($allowedProdiIds === null || empty($allowedProdiIds)) {
            return response()->json([
                'data' => [],
                'total' => 0,
                'per_page' => (int) $request->get('per_page', 10),
                'current_page' => 1,
                'last_page' => 1,
                'from' => 0,
                'to' => 0,
            ]);
        }

        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');
        $semesterMasukId = $request->get('id_semester_masuk');
        $semesterIdRaw = $request->get('id_semester');
        $semesterIdInt = $semesterIdRaw !== null && $semesterIdRaw !== '' ? (int) $semesterIdRaw : null;
        if ($semesterIdInt === 0) {
            $semesterIdInt = null;
        }
        $grupMahasiswaId = $request->get('id_grup_mahasiswa');
        $rawStatus = $request->get('status_pengajuan');
        $statusPengajuan = in_array($rawStatus, ['belum_mengajukan', 'ada_belum_acc', 'sudah_acc_semua'], true)
            ? $rawStatus
            : null;

        if ($statusPengajuan === 'belum_mengajukan') {
            if (! $semesterIdInt) {
                return response()->json([
                    'message' => 'Parameter id_semester wajib untuk status belum mengajukan.',
                ], 422);
            }

            $mQuery = $this->mahasiswaTanpaKrsSemesterQuery(
                $semesterIdInt,
                $search && trim((string) $search) !== '' ? trim((string) $search) : null,
                null,
                $semesterMasukId,
                $grupMahasiswaId,
                $allowedProdiIds
            );

            $total = (clone $mQuery)->count();
            $page = (int) $request->get('page', 1);
            $offset = ($page - 1) * $perPage;

            $results = $mQuery->orderBy('mahasiswa.nim')
                ->offset($offset)
                ->limit($perPage)
                ->get();

            $mahasiswaIds = $results->pluck('id_mahasiswa')->toArray();
            $dosenWaliData = [];
            if (! empty($mahasiswaIds)) {
                $dosenWaliResults = DB::table('dosen_wali')
                    ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
                    ->whereIn('dosen_wali.id_mahasiswa', $mahasiswaIds)
                    ->where('dosen_wali.status', 'active')
                    ->whereNull('dosen_wali.deleted_at')
                    ->select('dosen_wali.id_mahasiswa', 'dosen.nama as dosen_nama')
                    ->get();
                foreach ($dosenWaliResults as $dw) {
                    $dosenWaliData[$dw->id_mahasiswa] = $dw->dosen_nama;
                }
            }

            $prodiIds = $results->pluck('id_prodi')->filter()->unique()->toArray();
            $jenjangData = [];
            if (! empty($prodiIds)) {
                $jenjangResults = DB::table('prodi')
                    ->join('jenjang', 'prodi.id_jenjang', '=', 'jenjang.id')
                    ->whereIn('prodi.id', $prodiIds)
                    ->whereNull('prodi.deleted_at')
                    ->whereNull('jenjang.deleted_at')
                    ->select('prodi.id as prodi_id', 'jenjang.id as jenjang_id', 'jenjang.kode as jenjang_kode', 'jenjang.nama as jenjang_nama')
                    ->get();
                foreach ($jenjangResults as $j) {
                    $jenjangData[$j->prodi_id] = [
                        'id' => $j->jenjang_id,
                        'kode' => $j->jenjang_kode,
                        'nama' => $j->jenjang_nama,
                    ];
                }
            }

            $data = $this->mapKrsIndexRows($results, $dosenWaliData, $jenjangData);
            $lastPage = (int) ceil(max(1, $total) / $perPage);
            $from = $total > 0 ? $offset + 1 : 0;
            $to = min($offset + $perPage, $total);

            return response()->json([
                'data' => $data,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
            ]);
        }

        $query = Krs::select([
            'krs.id_mahasiswa',
            DB::raw('MAX(mahasiswa.nim) as nim'),
            DB::raw('MAX(mahasiswa.nama) as nama'),
            DB::raw('MAX(prodi.id) as id_prodi'),
            DB::raw('MAX(prodi.nama) as prodi_nama'),
            DB::raw('MAX(prodi.id_jenjang) as id_jenjang'),
            DB::raw('COUNT(DISTINCT krs.id) as total_kelas'),
            DB::raw('COALESCE(SUM(CASE WHEN krs.approved_at IS NOT NULL THEN matkul.sks ELSE 0 END), 0) as sks_diacc'),
            DB::raw('COALESCE(SUM(matkul.sks), 0) as sks_diajukan'),
        ])
            ->join('mahasiswa', 'krs.id_mahasiswa', '=', 'mahasiswa.id')
            ->join('prodi', 'mahasiswa.id_prodi', '=', 'prodi.id')
            ->join('kelas', 'krs.id_kelas', '=', 'kelas.id')
            ->join('kurikulum_matkul', 'kelas.id_kurikulum_matkul', '=', 'kurikulum_matkul.id')
            ->join('matkul', 'kurikulum_matkul.id_matkul', '=', 'matkul.id')
            ->whereNull('krs.deleted_at')
            ->whereNull('mahasiswa.deleted_at')
            ->whereIn('mahasiswa.id_prodi', $allowedProdiIds)
            ->groupBy('krs.id_mahasiswa');

        if ($search && trim($search) !== '') {
            $search = trim($search);
            $query->where(function ($q) use ($search) {
                $q->where('mahasiswa.nama', 'like', "%{$search}%")
                    ->orWhere('mahasiswa.nim', 'like', "%{$search}%");
            });
        }

        if ($semesterMasukId) {
            $query->where('mahasiswa.id_semester_masuk', $semesterMasukId);
        }

        if ($semesterIdInt) {
            $query->where('kelas.id_semester', $semesterIdInt);
        }

        if ($grupMahasiswaId) {
            $query->where('mahasiswa.id_grup_mahasiswa', $grupMahasiswaId);
        }

        if ($statusPengajuan === 'ada_belum_acc') {
            $query->havingRaw('SUM(CASE WHEN krs.approved_at IS NULL THEN 1 ELSE 0 END) > 0');
        } elseif ($statusPengajuan === 'sudah_acc_semua') {
            $query->havingRaw('COUNT(DISTINCT krs.id) > 0 AND SUM(CASE WHEN krs.approved_at IS NULL THEN 1 ELSE 0 END) = 0');
        }

        $totalQuery = clone $query;
        $total = DB::table(DB::raw("({$totalQuery->toSql()}) as sub"))
            ->mergeBindings($totalQuery->getQuery())
            ->count();

        $page = (int) $request->get('page', 1);
        $offset = ($page - 1) * $perPage;

        $results = $query->orderBy('mahasiswa.nim')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $mahasiswaIds = $results->pluck('id_mahasiswa')->toArray();
        $dosenWaliData = [];
        if (! empty($mahasiswaIds)) {
            $dosenWaliResults = DB::table('dosen_wali')
                ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
                ->whereIn('dosen_wali.id_mahasiswa', $mahasiswaIds)
                ->where('dosen_wali.status', 'active')
                ->whereNull('dosen_wali.deleted_at')
                ->select('dosen_wali.id_mahasiswa', 'dosen.nama as dosen_nama')
                ->get();
            foreach ($dosenWaliResults as $dw) {
                $dosenWaliData[$dw->id_mahasiswa] = $dw->dosen_nama;
            }
        }

        $prodiIds = $results->pluck('id_prodi')->filter()->unique()->toArray();
        $jenjangData = [];
        if (! empty($prodiIds)) {
            $jenjangResults = DB::table('prodi')
                ->join('jenjang', 'prodi.id_jenjang', '=', 'jenjang.id')
                ->whereIn('prodi.id', $prodiIds)
                ->whereNull('prodi.deleted_at')
                ->whereNull('jenjang.deleted_at')
                ->select('prodi.id as prodi_id', 'jenjang.id as jenjang_id', 'jenjang.kode as jenjang_kode', 'jenjang.nama as jenjang_nama')
                ->get();
            foreach ($jenjangResults as $j) {
                $jenjangData[$j->prodi_id] = [
                    'id' => $j->jenjang_id,
                    'kode' => $j->jenjang_kode,
                    'nama' => $j->jenjang_nama,
                ];
            }
        }

        $data = $this->mapKrsIndexRows($results, $dosenWaliData, $jenjangData);

        $lastPage = (int) ceil(max(1, $total) / $perPage);
        $from = $total > 0 ? $offset + 1 : 0;
        $to = min($offset + $perPage, $total);

        return response()->json([
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_mahasiswa' => ['required', 'integer', 'exists:mahasiswa,id'],
            'krs' => ['required', 'array', 'min:1'],
            'krs.*.id_kelas' => ['required', 'integer', 'exists:kelas,id'],
            'krs.*.status' => ['nullable', 'string', Rule::in(['pending', 'acc', 'approved'])],
        ]);

        $idMahasiswa = $validated['id_mahasiswa'];
        $krsData = $validated['krs'];
        $user = $request->user();

        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $mahasiswa = Mahasiswa::find($idMahasiswa);
                if (! $mahasiswa || ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke mahasiswa ini.');
                }
                foreach ($krsData as $data) {
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
            foreach ($krsData as $index => $data) {
                // Check unique constraint: id_mahasiswa, id_kelas
                $exists = Krs::where('id_mahasiswa', $idMahasiswa)
                    ->where('id_kelas', $data['id_kelas'])
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    $errors[] = [
                        'index' => $index,
                        'message' => 'KRS dengan kelas ini sudah ada untuk mahasiswa ini.',
                        'field' => 'id_kelas',
                    ];

                    continue;
                }

                $status = $data['status'] ?? 'pending';
                $isApproved = in_array($status, ['acc', 'approved'], true);

                $krs = Krs::create([
                    'id_mahasiswa' => $idMahasiswa,
                    'id_kelas' => $data['id_kelas'],
                    'approved_by' => $isApproved ? ($user->name ?? $user->email ?? null) : null,
                    'approved_at' => $isApproved ? now() : null,
                    'created_by' => $user->name ?? $user->email ?? null,
                ]);

                $krs->load(['mahasiswa.prodi', 'kelas.kurikulumMatkul.matkul', 'kelas.semester']);
                $results[] = $krs;
            }

            if (! empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Beberapa KRS gagal disimpan karena duplikasi atau kesalahan lainnya.',
                    'errors' => $errors,
                    'data' => $results,
                ], 422);
            }

            DB::commit();

            return response()->json($results, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan KRS: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    public function getMahasiswaOptions(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 100);
        $search = $request->get('search');

        $query = \App\Models\Mahasiswa::with(['prodi', 'semester_masuk', 'kelompok_kelas']);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id_prodi', $allowedProdiIds);
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nim', 'like', "%{$search}%");
            });
        }

        $data = $query->orderBy('nim')->paginate($perPage);

        return response()->json($data);
    }

    public function getMahasiswaDetail(Request $request, $id): JsonResponse
    {
        $mahasiswa = \App\Models\Mahasiswa::with([
            'prodi',
            'semester_masuk',
            'kelompok_kelas',
        ])->find($id);

        if (! $mahasiswa) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke mahasiswa ini.');
            }
        }

        // Ambil dosen wali aktif
        $dosenWali = DB::table('dosen_wali')
            ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
            ->where('dosen_wali.id_mahasiswa', $mahasiswa->id)
            ->where('dosen_wali.status', 'active')
            ->whereNull('dosen_wali.deleted_at')
            ->select('dosen.nama as dosen_nama')
            ->first();

        return response()->json([
            'id' => $mahasiswa->id,
            'nim' => $mahasiswa->nim,
            'nama' => $mahasiswa->nama,
            'prodi' => $mahasiswa->prodi ? [
                'id' => $mahasiswa->prodi->id,
                'nama' => $mahasiswa->prodi->nama,
                'kode' => $mahasiswa->prodi->kode ?? null,
            ] : null,
            'dosen_wali' => $dosenWali ? $dosenWali->dosen_nama : '-',
            'semester_masuk' => $mahasiswa->semester_masuk ? [
                'id' => $mahasiswa->semester_masuk->id,
                'nama' => $mahasiswa->semester_masuk->nama,
                'kode' => $mahasiswa->semester_masuk->kode,
            ] : null,
            'kelompok_kelas' => $mahasiswa->kelompok_kelas ? [
                'id' => $mahasiswa->kelompok_kelas->id,
                'nama' => $mahasiswa->kelompok_kelas->nama,
            ] : null,
        ]);
    }

    public function getKelasOptions(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 100);
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi');
        $semesterId = $request->get('id_semester');

        $query = \App\Models\Kelas::with([
            'kurikulumMatkul.matkul',
            'kurikulumMatkul.kurikulum',
            'prodi',
            'semester',
            'kelompokKelas',
        ]);

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

        $data = $query->orderBy('id')->paginate($perPage);

        return response()->json($data);
    }

    public function show(Request $request, $idMahasiswa): JsonResponse
    {
        // Ambil detail mahasiswa
        $mahasiswa = \App\Models\Mahasiswa::with([
            'prodi',
            'semester_masuk',
        ])->find($idMahasiswa);

        if (! $mahasiswa) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data KRS mahasiswa ini.');
            }
        }

        // Ambil dosen wali aktif
        $dosenWali = DB::table('dosen_wali')
            ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
            ->where('dosen_wali.id_mahasiswa', $mahasiswa->id)
            ->where('dosen_wali.status', 'active')
            ->whereNull('dosen_wali.deleted_at')
            ->select('dosen.nama as dosen_nama')
            ->first();

        // Ambil semua KRS untuk mahasiswa ini
        $krsQuery = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $idMahasiswa)
            ->whereNull('deleted_at');

        // Filter berdasarkan semester jika ada
        $idSemester = $request->get('id_semester');
        if ($idSemester) {
            $krsQuery->whereHas('kelas', function ($q) use ($idSemester) {
                $q->where('id_semester', $idSemester);
            });
        }

        $krsList = UrutanMatkulService::urutkanKrs($krsQuery->orderBy('created_at', 'desc')->get());

        // Hitung total SKS
        $totalSksDiajukan = 0;
        $totalSksDiacc = 0;

        foreach ($krsList as $krs) {
            $sks = $krs->kelas->kurikulumMatkul->matkul->sks ?? 0;
            $totalSksDiajukan += $sks;
            if ($krs->approved_at) {
                $totalSksDiacc += $sks;
            }
        }

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
                'dosen_wali' => $dosenWali ? $dosenWali->dosen_nama : '-',
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'nama' => $mahasiswa->semester_masuk->nama,
                    'kode' => $mahasiswa->semester_masuk->kode,
                ] : null,
            ],
            'krs_list' => $krsList->map(function ($krs) {
                $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
                $semester = $krs->kelas->semester ?? null;
                $dosenPic = $krs->kelas->dosenPic ?? null;

                return [
                    'id' => $krs->id,
                    'id_kelas' => $krs->id_kelas,
                    'approved_at' => $krs->approved_at ? $krs->approved_at->format('Y-m-d H:i:s') : null,
                    'approved_by' => $krs->approved_by,
                    'kelas' => [
                        'id' => $krs->kelas->id,
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
                        'dosen_pic' => $dosenPic ? [
                            'id' => $dosenPic->id,
                            'nama' => $dosenPic->nama,
                        ] : null,
                    ],
                ];
            }),
            'summary' => [
                'total_krs' => $krsList->count(),
                'sks_diajukan' => $totalSksDiajukan,
                'sks_diacc' => $totalSksDiacc,
            ],
        ]);
    }

    /**
     * @return array{mahasiswa: array, data: array<int, array>}|null
     */
    protected function buildKrsBySemesterPayload(int $idMahasiswa): ?array
    {
        $mahasiswa = Mahasiswa::with([
            'prodi',
            'semester_masuk',
        ])->find($idMahasiswa);

        if (! $mahasiswa) {
            return null;
        }

        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $idMahasiswa)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $krsList = UrutanMatkulService::urutkanKrs($krsList);

        $krsBySemester = [];

        foreach ($krsList as $krs) {
            $semester = $krs->kelas->semester;

            if (! $semester) {
                continue;
            }

            $semesterId = $semester->id;

            if (! isset($krsBySemester[$semesterId])) {
                $krsBySemester[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'krs' => [],
                    'total_sks_diajukan' => 0,
                    'total_sks_diacc' => 0,
                ];
            }

            $sks = $krs->kelas->kurikulumMatkul->matkul->sks ?? 0;
            $krsBySemester[$semesterId]['total_sks_diajukan'] += $sks;

            if ($krs->approved_at) {
                $krsBySemester[$semesterId]['total_sks_diacc'] += $sks;
            }

            $krsBySemester[$semesterId]['krs'][] = [
                'id' => $krs->id,
                'matkul' => [
                    'id' => $krs->kelas->kurikulumMatkul->matkul->id ?? null,
                    'kode' => $krs->kelas->kurikulumMatkul->matkul->kode ?? null,
                    'nama' => $krs->kelas->kurikulumMatkul->matkul->nama ?? null,
                    'sks' => $sks,
                ],
                'kelas' => [
                    'id' => $krs->kelas->id ?? null,
                    'nama' => $krs->kelas->nama ?? null,
                ],
                'dosen' => [
                    'id' => $krs->kelas->dosenPic->id ?? null,
                    'nama' => $krs->kelas->dosenPic->nama ?? null,
                ],
                'status' => $krs->approved_at ? 'approved' : 'pending',
                'approved_at' => $krs->approved_at ? $krs->approved_at->format('Y-m-d H:i:s') : null,
                'approved_by' => $krs->approved_by,
                'created_at' => $krs->created_at ? $krs->created_at->format('Y-m-d H:i:s') : null,
            ];
        }

        usort($krsBySemester, function ($a, $b) {
            return $b['semester']['id'] <=> $a['semester']['id'];
        });

        return [
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
            'data' => array_values($krsBySemester),
        ];
    }

    /**
     * Get KRS untuk mahasiswa tertentu yang dikelompokkan berdasarkan semester
     */
    public function getKrsBySemesterForMahasiswa(Request $request, $idMahasiswa): JsonResponse
    {
        $idMahasiswa = (int) $idMahasiswa;

        $payload = $this->buildKrsBySemesterPayload($idMahasiswa);

        if (! $payload) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            $prodiBlock = $payload['mahasiswa']['prodi'] ?? null;
            $idProdi = is_array($prodiBlock) && isset($prodiBlock['id']) ? (int) $prodiBlock['id'] : 0;
            if ($allowedProdiIds !== null && ! in_array($idProdi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data KRS mahasiswa ini.');
            }
        }

        return response()->json($payload);
    }

    /**
     * KRS per semester untuk mahasiswa bimbingan (dosen wali aktif).
     */
    public function getKrsBySemesterForBimbinganWali(Request $request, int $idMahasiswa): JsonResponse
    {
        $user = $request->user();
        $dosen = \App\Models\Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $dosenWali = \App\Models\DosenWali::where('id_dosen', $dosen->id)
            ->where('id_mahasiswa', $idMahasiswa)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (! $dosenWali) {
            return response()->json([
                'message' => 'Mahasiswa bukan bimbingan Anda atau tidak aktif.',
            ], 404);
        }

        $payload = $this->buildKrsBySemesterPayload($idMahasiswa);

        if (! $payload) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        return response()->json($payload);
    }

    /**
     * Get KRS untuk mahasiswa tertentu (scope prodi) - untuk modal detail di halaman prodi/krs
     */
    public function getKrsBySemesterForMahasiswaProdi(Request $request, $idMahasiswa): JsonResponse
    {
        $user = $request->user();
        $allowedProdiIds = $user && $user->hasScopeRestriction() ? $user->getAllowedProdiIds() : null;
        if ($allowedProdiIds === null || empty($allowedProdiIds)) {
            abort(403, 'Anda tidak memiliki akses.');
        }

        $mahasiswa = \App\Models\Mahasiswa::with(['prodi', 'semester_masuk'])->find($idMahasiswa);
        if (! $mahasiswa || ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
            return response()->json(['message' => 'Mahasiswa tidak ditemukan'], 404);
        }

        $semesterIdFilter = $request->get('id_semester') ? (int) $request->get('id_semester') : null;

        $query = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $idMahasiswa)
            ->whereNull('krs.deleted_at');

        if ($semesterIdFilter) {
            $query->whereHas('kelas', function ($q) use ($semesterIdFilter) {
                $q->where('id_semester', $semesterIdFilter);
            });
        }

        $krsList = UrutanMatkulService::urutkanKrs($query->orderBy('created_at', 'desc')->get());

        $krsBySemester = [];
        foreach ($krsList as $krs) {
            $semester = $krs->kelas->semester;
            if (! $semester) {
                continue;
            }
            $semesterId = $semester->id;
            if (! isset($krsBySemester[$semesterId])) {
                $krsBySemester[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'krs' => [],
                    'total_sks_diajukan' => 0,
                    'total_sks_diacc' => 0,
                ];
            }
            $sks = $krs->kelas->kurikulumMatkul->matkul->sks ?? 0;
            $krsBySemester[$semesterId]['total_sks_diajukan'] += $sks;
            if ($krs->approved_at) {
                $krsBySemester[$semesterId]['total_sks_diacc'] += $sks;
            }
            $krsBySemester[$semesterId]['krs'][] = [
                'id' => $krs->id,
                'matkul' => [
                    'id' => $krs->kelas->kurikulumMatkul->matkul->id ?? null,
                    'kode' => $krs->kelas->kurikulumMatkul->matkul->kode ?? null,
                    'nama' => $krs->kelas->kurikulumMatkul->matkul->nama ?? null,
                    'sks' => $sks,
                ],
                'kelas' => [
                    'id' => $krs->kelas->id ?? null,
                    'nama' => $krs->kelas->nama ?? null,
                ],
                'dosen' => [
                    'id' => $krs->kelas->dosenPic->id ?? null,
                    'nama' => $krs->kelas->dosenPic->nama ?? null,
                ],
                'status' => $krs->approved_at ? 'approved' : 'pending',
                'approved_at' => $krs->approved_at ? $krs->approved_at->format('Y-m-d H:i:s') : null,
                'approved_by' => $krs->approved_by,
                'created_at' => $krs->created_at->format('Y-m-d H:i:s'),
            ];
        }
        usort($krsBySemester, function ($a, $b) {
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
            'data' => array_values($krsBySemester),
        ]);
    }

    public function edit(Request $request, $id): JsonResponse
    {
        $krs = Krs::with([
            'mahasiswa.prodi',
            'mahasiswa.semester_masuk',
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
        ])->find($id);

        if (! $krs) {
            return response()->json(['message' => 'KRS tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $mahasiswa = $krs->mahasiswa ?? Mahasiswa::find($krs->id_mahasiswa);
            if ($mahasiswa) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke data KRS ini.');
                }
            }
        }

        // Ambil dosen wali aktif
        $dosenWali = DB::table('dosen_wali')
            ->join('dosen', 'dosen_wali.id_dosen', '=', 'dosen.id')
            ->where('dosen_wali.id_mahasiswa', $krs->id_mahasiswa)
            ->where('dosen_wali.status', 'active')
            ->whereNull('dosen_wali.deleted_at')
            ->select('dosen.nama as dosen_nama')
            ->first();

        // Tentukan status (acc atau pending)
        $status = $krs->approved_at ? 'acc' : 'pending';

        return response()->json([
            'id' => $krs->id,
            'id_mahasiswa' => $krs->id_mahasiswa,
            'id_kelas' => $krs->id_kelas,
            'status' => $status,
            'mahasiswa' => [
                'id' => $krs->mahasiswa->id,
                'nim' => $krs->mahasiswa->nim,
                'nama' => $krs->mahasiswa->nama,
                'prodi' => $krs->mahasiswa->prodi ? [
                    'id' => $krs->mahasiswa->prodi->id,
                    'nama' => $krs->mahasiswa->prodi->nama,
                    'kode' => $krs->mahasiswa->prodi->kode ?? null,
                    'id_jenjang' => $krs->mahasiswa->prodi->id_jenjang ?? null,
                ] : null,
                'dosen_wali' => $dosenWali ? $dosenWali->dosen_nama : '-',
                'semester_masuk' => $krs->mahasiswa->semester_masuk ? [
                    'id' => $krs->mahasiswa->semester_masuk->id,
                    'nama' => $krs->mahasiswa->semester_masuk->nama,
                    'kode' => $krs->mahasiswa->semester_masuk->kode,
                ] : null,
            ],
            'kelas' => [
                'id' => $krs->kelas->id,
                'matkul' => $krs->kelas->kurikulumMatkul->matkul ? [
                    'id' => $krs->kelas->kurikulumMatkul->matkul->id,
                    'kode' => $krs->kelas->kurikulumMatkul->matkul->kode,
                    'nama' => $krs->kelas->kurikulumMatkul->matkul->nama,
                    'sks' => $krs->kelas->kurikulumMatkul->matkul->sks,
                ] : null,
                'semester' => $krs->kelas->semester ? [
                    'id' => $krs->kelas->semester->id,
                    'kode' => $krs->kelas->semester->kode,
                    'nama' => $krs->kelas->semester->nama,
                ] : null,
            ],
        ]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $krs = Krs::with('mahasiswa')->find($id);

        if (! $krs) {
            return response()->json(['message' => 'KRS tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $mahasiswa = $krs->mahasiswa ?? Mahasiswa::find($krs->id_mahasiswa);
            if ($mahasiswa) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke data KRS ini.');
                }
            }
        }

        $validated = $request->validate([
            'id_kelas' => ['required', 'integer', 'exists:kelas,id'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'acc'])],
        ]);

        // Check unique constraint: id_mahasiswa, id_kelas (jika id_kelas berubah)
        if ($validated['id_kelas'] != $krs->id_kelas) {
            $exists = Krs::where('id_mahasiswa', $krs->id_mahasiswa)
                ->where('id_kelas', $validated['id_kelas'])
                ->where('id', '!=', $krs->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'KRS dengan kelas ini sudah ada untuk mahasiswa ini.',
                    'errors' => [
                        'id_kelas' => ['KRS dengan kelas ini sudah ada untuk mahasiswa ini.'],
                    ],
                ], 422);
            }
        }

        if ($user && $user->hasScopeRestriction() && (int) $validated['id_kelas'] !== (int) $krs->id_kelas) {
            $newKelas = Kelas::find($validated['id_kelas']);
            if ($newKelas) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $newKelas->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke kelas ini.');
                }
            }
        }

        $status = $validated['status'] ?? ($krs->approved_at ? 'acc' : 'pending');
        $isApproved = $status === 'acc';

        $krs->update([
            'id_kelas' => $validated['id_kelas'],
            'status' => 'active', // Status selalu 'active' untuk KRS yang valid
            'approved_by' => $isApproved ? ($user->name ?? $user->email ?? null) : null,
            'approved_at' => $isApproved ? now() : null,
        ]);

        $krs->load(['mahasiswa.prodi', 'kelas.kurikulumMatkul.matkul', 'kelas.semester']);

        return response()->json($krs);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $krs = Krs::with('mahasiswa')->find($id);

        if (! $krs) {
            return response()->json(['message' => 'KRS tidak ditemukan'], 404);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $mahasiswa = $krs->mahasiswa ?? Mahasiswa::find($krs->id_mahasiswa);
            if ($mahasiswa) {
                $allowedProdiIds = $user->getAllowedProdiIds();
                if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    abort(403, 'Anda tidak memiliki akses ke data KRS ini.');
                }
            }
        }

        $krs->delete();

        return response()->json(['message' => 'KRS dihapus']);
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'NIM*',
            'Kode Mata Kuliah*',
            'Kode Semester (Opsional)',
            'Status (pending/acc)',
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(25);
        $sheet->getColumnDimension('D')->setWidth(20);

        // Style header
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER],
        ];
        $sheet->getStyle('A1:D1')->applyFromArray($headerStyle);

        // Add example row
        $exampleRow = [
            '2024001',
            'MK001',
            '20241',
            'pending',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_krs_'.date('YmdHis').'.xlsx';

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
        $user = $request->user();

        // Get active semester (jika kode semester tidak diisi)
        $activeSemester = Semester::where('is_active', true)->first();

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
                $status = trim(strtolower($row[3] ?? ''));

                // Validate required fields
                if (empty($nim)) {
                    $errors[] = "Baris {$rowNumber}: NIM wajib diisi.";

                    continue;
                }

                if (empty($kodeMatkul)) {
                    $errors[] = "Baris {$rowNumber}: Kode Mata Kuliah wajib diisi.";

                    continue;
                }

                // Validate status
                if (! empty($status) && ! in_array($status, ['pending', 'acc'])) {
                    $errors[] = "Baris {$rowNumber}: Status harus 'pending' atau 'acc'.";

                    continue;
                }

                // Find mahasiswa by NIM
                $mahasiswa = Mahasiswa::where('nim', $nim)->first();
                if (! $mahasiswa) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa dengan NIM '{$nim}' tidak ditemukan.";

                    continue;
                }

                // Find matkul by kode
                // Satu kode mata kuliah bisa dipakai beberapa prodi sekaligus, masing-masing
                // sebagai baris matkul terpisah (mis. 'MKW201' ada di 3 prodi). ->first() polos
                // memilih baris milik prodi mana saja, sehingga pencarian kelas di bawah meleset
                // ke kurikulum prodi lain — kelasnya dilaporkan "tidak ditemukan" padahal ada,
                // atau lebih buruk: mahasiswa masuk ke kelas milik prodi lain lewat fallback.
                $matkul = Matkul::where('kode', $kodeMatkul)->where('id_prodi', $mahasiswa->id_prodi)->first()
                    ?: Matkul::where('kode', $kodeMatkul)->first();
                if (! $matkul) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah dengan kode '{$kodeMatkul}' tidak ditemukan.";

                    continue;
                }

                // Find semester
                $semester = null;
                if (! empty($kodeSemester)) {
                    $semester = Semester::where('kode', $kodeSemester)->first();
                    if (! $semester) {
                        $errors[] = "Baris {$rowNumber}: Semester dengan kode '{$kodeSemester}' tidak ditemukan.";

                        continue;
                    }
                } else {
                    // Use active semester if not provided
                    if (! $activeSemester) {
                        $errors[] = "Baris {$rowNumber}: Kode Semester wajib diisi atau set semester aktif di sistem.";

                        continue;
                    }
                    $semester = $activeSemester;
                }

                // Find kurikulum_matkul by matkul
                $kurikulumMatkulList = KurikulumMatkul::where('id_matkul', $matkul->id)->get();
                if ($kurikulumMatkulList->isEmpty()) {
                    $errors[] = "Baris {$rowNumber}: Mata kuliah '{$kodeMatkul}' tidak ditemukan dalam kurikulum.";

                    continue;
                }

                // Find kelas by kurikulum_matkul and semester
                // Kelas WAJIB milik prodi mahasiswa. Fallback lintas-prodi sudah dihapus: kalau
                // prodi mahasiswa tidak punya kelasnya, mendaftarkan dia ke kelas prodi lain
                // menghasilkan data yang salah tanpa peringatan apa pun.
                $kelas = Kelas::whereIn('id_kurikulum_matkul', $kurikulumMatkulList->pluck('id'))
                    ->where('id_semester', $semester->id)
                    ->where('id_prodi', $mahasiswa->id_prodi)
                    ->first();

                if (! $kelas) {
                    // Kalau kelasnya ternyata ada di prodi lain, sebutkan — supaya admin tahu ini
                    // soal ketidakcocokan prodi, bukan kelas yang belum dibuat.
                    $prodiKelasLain = Kelas::with('prodi')
                        ->whereIn('id_kurikulum_matkul', $kurikulumMatkulList->pluck('id'))
                        ->where('id_semester', $semester->id)
                        ->first()?->prodi?->nama;

                    $errors[] = "Baris {$rowNumber}: Kelas dengan semester '{$semester->kode}' dan mata kuliah '{$kodeMatkul}' tidak ditemukan pada prodi mahasiswa."
                        .($prodiKelasLain ? " Kelas mata kuliah ini adanya di prodi '{$prodiKelasLain}', dan mahasiswa tidak bisa didaftarkan ke kelas prodi lain." : '');

                    continue;
                }

                // Cukup cek prodi mahasiswa: kelas di atas sudah dipastikan berprodi sama.
                if ($user && $user->hasScopeRestriction()) {
                    $allowedProdiIds = $user->getAllowedProdiIds();
                    if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                        $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke mahasiswa NIM '{$nim}' (prodi di luar scope).";

                        continue;
                    }
                }

                // Check unique constraint: id_mahasiswa, id_kelas
                $exists = Krs::where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_kelas', $kelas->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if ($exists) {
                    // Sengaja tidak masuk $errors — ini bukan masalah yang perlu ditinjau admin,
                    // cukup dihitung lewat skip_count (ditampilkan di kartu "Dilewati").
                    $skipCount++;

                    continue;
                }

                // Determine status
                $finalStatus = $status ?: 'pending';
                $isApproved = $finalStatus === 'acc';

                // Create KRS
                $krs = Krs::create([
                    'id_mahasiswa' => $mahasiswa->id,
                    'id_kelas' => $kelas->id,
                    'status' => 'active',
                    'approved_by' => $isApproved ? ($user->name ?? $user->email ?? null) : null,
                    'approved_at' => $isApproved ? now() : null,
                ]);

                $processedRows[] = [
                    'row' => $rowNumber,
                    'id' => $krs->id,
                    'nim' => $nim,
                    'kode_matkul' => $kodeMatkul,
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
                'message' => "Import berhasil. {$successCount} data berhasil diimport".($skipCount > 0 ? ", {$skipCount} data diabaikan (duplikat)." : '.'),
                'data' => [
                    'success_count' => $successCount,
                    'skip_count' => $skipCount,
                    'error_count' => count($errors),
                    'processed_rows' => $processedRows,
                ],
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengimport: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    /**
     * Get jadwal kuliah untuk mahasiswa yang sedang login
     * Berdasarkan KRS (kelas yang diambil). Filter semester: id_semester (opsional), default semester aktif.
     */
    public function getJadwalKuliah(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ambil data mahasiswa dari user
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (! $mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan',
            ], 404);
        }

        $activeSemester = Semester::where('is_active', true)->first();
        $idSemester = $request->get('id_semester');
        if ($idSemester) {
            $idSemester = (int) $idSemester;
        } else {
            $idSemester = $activeSemester ? $activeSemester->id : null;
        }

        // Daftar semester yang punya KRS (untuk filter dropdown)
        $krsSemesters = Krs::with('kelas.semester')
            ->where('id_mahasiswa', $mahasiswa->id)
            ->whereNull('deleted_at')
            ->whereHas('kelas.semester')
            ->get()
            ->pluck('kelas.semester')
            ->filter()
            ->unique('id')
            ->sortByDesc('id')
            ->values();

        $semestersList = $krsSemesters->map(function ($s) {
            return [
                'id' => $s->id,
                'kode' => $s->kode,
                'nama' => $s->nama,
            ];
        })->toArray();

        // Sertakan semester aktif di list jika belum ada
        if ($activeSemester && $krsSemesters->where('id', $activeSemester->id)->isEmpty()) {
            array_unshift($semestersList, [
                'id' => $activeSemester->id,
                'kode' => $activeSemester->kode,
                'nama' => $activeSemester->nama,
            ]);
        }

        $selectedSemester = $idSemester
            ? Semester::find($idSemester)
            : $activeSemester;

        if (! $selectedSemester) {
            return response()->json([
                'semester' => null,
                'semesters' => $semestersList,
                'kelas_kontrak' => [],
                'data' => [],
            ]);
        }

        // KRS mahasiswa untuk semester terpilih
        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $mahasiswa->id)
            ->whereNull('deleted_at')
            ->whereHas('kelas', function ($q) use ($selectedSemester) {
                $q->where('id_semester', $selectedSemester->id);
            })
            ->get();

        $kelasKontrak = $krsList->map(function ($krs) {
            $kelas = $krs->kelas;
            $matkul = $kelas->kurikulumMatkul->matkul ?? null;

            return [
                'id_kelas' => $kelas->id,
                'nama_kelas' => $kelas->nama ?? null,
                'matkul' => [
                    'id' => $matkul->id ?? null,
                    'kode' => $matkul->kode ?? null,
                    'nama' => $matkul->nama ?? null,
                    'sks' => $matkul->sks ?? null,
                ],
                'krs_status' => $krs->approved_at ? 'approved' : 'pending',
            ];
        })->sortBy(function ($row) {
            return ($row['matkul']['kode'] ?? '').($row['matkul']['nama'] ?? '');
        })->values()->all();

        $idKelasFilter = $request->get('id_kelas');
        $idKelasFilter = $idKelasFilter !== null && $idKelasFilter !== '' ? (int) $idKelasFilter : null;

        $allowedKelasIds = $krsList->pluck('id_kelas')->map(fn ($id) => (int) $id)->all();

        if ($idKelasFilter !== null) {
            if (! in_array($idKelasFilter, $allowedKelasIds, true)) {
                return response()->json([
                    'message' => 'Kelas tidak ditemukan pada KRS Anda untuk semester ini.',
                ], 422);
            }
            $kelasIds = [$idKelasFilter];
        } else {
            $kelasIds = [];
        }

        if (empty($kelasIds)) {
            return response()->json([
                'semester' => [
                    'id' => $selectedSemester->id,
                    'kode' => $selectedSemester->kode,
                    'nama' => $selectedSemester->nama,
                ],
                'semesters' => $semestersList,
                'kelas_kontrak' => $kelasKontrak,
                'data' => [],
            ]);
        }

        // Jadwal untuk kelas terpilih (atau semua kelas KRS jika di masa depan diperlukan)
        $jadwalList = Jadwal::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
            'ruangan',
            'jenisKuliah',
            'dosen.dosen',
        ])
            ->whereIn('id_kelas', $kelasIds)
            ->whereNull('deleted_at')
            ->get();

        $jadwalIds = $jadwalList->pluck('id')->filter()->values()->all();
        $perkuliahanRows = $jadwalIds === []
            ? collect()
            : Perkuliahan::whereIn('id_jadwal', $jadwalIds)->whereNull('deleted_at')->get();

        $formattedJadwal = $jadwalList->map(function ($jadwal) use ($krsList, $perkuliahanRows) {
            $krs = $krsList->firstWhere('id_kelas', $jadwal->id_kelas);
            $kelas = $jadwal->kelas;
            $p = $this->findPerkuliahanForJadwalSlotKrs($jadwal, $perkuliahanRows);
            $sesi = $this->sesiStatusForPerkuliahanKrs($p);

            return [
                'id' => $jadwal->id,
                'hari' => $jadwal->hari,
                'jam_mulai' => $jadwal->jam_mulai,
                'jam_selesai' => $jadwal->jam_selesai,
                'matkul' => [
                    'id' => $kelas->kurikulumMatkul->matkul->id ?? null,
                    'kode' => $kelas->kurikulumMatkul->matkul->kode ?? null,
                    'nama' => $kelas->kurikulumMatkul->matkul->nama ?? null,
                    'sks' => $kelas->kurikulumMatkul->matkul->sks ?? null,
                ],
                'kelas' => [
                    'id' => $kelas->id ?? null,
                    'nama' => $kelas->nama ?? ($kelas->kurikulumMatkul->matkul->nama ?? null),
                ],
                'dosen' => $jadwal->dosen->map(function ($jd) {
                    return [
                        'id' => $jd->dosen->id ?? null,
                        'nama' => $jd->dosen->nama ?? null,
                    ];
                })->toArray(),
                'ruangan' => [
                    'id' => $jadwal->ruangan->id ?? null,
                    'nama' => $jadwal->ruangan->nama ?? null,
                ],
                'jenis_kuliah' => [
                    'id' => $jadwal->jenisKuliah->id ?? null,
                    'nama' => $jadwal->jenisKuliah->nama ?? null,
                ],
                'krs_status' => $krs ? ($krs->approved_at ? 'approved' : 'pending') : null,
                'sesi_status' => $sesi['sesi_status'],
                'sesi_status_label' => $sesi['sesi_status_label'],
            ];
        })->sortBy(function ($item) {
            $hariOrder = [
                'senin' => 1,
                'selasa' => 2,
                'rabu' => 3,
                'kamis' => 4,
                'jumat' => 5,
                'sabtu' => 6,
                'minggu' => 7,
            ];
            $hariNum = $hariOrder[strtolower($item['hari'])] ?? 8;
            $jamMulai = str_replace(':', '', $item['jam_mulai']);

            return $hariNum * 10000 + (int) $jamMulai;
        })->values();

        return response()->json([
            'semester' => [
                'id' => $selectedSemester->id,
                'kode' => $selectedSemester->kode,
                'nama' => $selectedSemester->nama,
            ],
            'semesters' => $semestersList,
            'kelas_kontrak' => $kelasKontrak,
            'data' => $formattedJadwal,
        ]);
    }

    /**
     * Detail satu slot jadwal untuk mahasiswa: info jadwal, daftar perkuliahan (materi + lampiran), kehadiran sendiri.
     */
    public function getJadwalDetailMahasiswa(Request $request, int $idJadwal): JsonResponse
    {
        $user = $request->user();
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $jadwal = Jadwal::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.semester',
            'ruangan',
            'jenisKuliah',
            'dosen.dosen',
        ])
            ->whereNull('deleted_at')
            ->find($idJadwal);

        if (! $jadwal) {
            return response()->json(['message' => 'Jadwal tidak ditemukan'], 404);
        }

        $hasKrs = Krs::where('id_mahasiswa', $mahasiswa->id)
            ->where('id_kelas', $jadwal->id_kelas)
            ->whereNull('deleted_at')
            ->exists();

        if (! $hasKrs) {
            return response()->json(['message' => 'Anda tidak memiliki KRS untuk kelas pada jadwal ini'], 403);
        }

        $krs = Krs::where('id_mahasiswa', $mahasiswa->id)
            ->where('id_kelas', $jadwal->id_kelas)
            ->whereNull('deleted_at')
            ->first();

        $kelas = $jadwal->kelas;
        $matkul = $kelas->kurikulumMatkul->matkul ?? null;

        $jadwalFormatted = [
            'id' => $jadwal->id,
            'hari' => $jadwal->hari,
            'tanggal' => $jadwal->tanggal ? $jadwal->tanggal->format('Y-m-d') : null,
            'jam_mulai' => $jadwal->jam_mulai,
            'jam_selesai' => $jadwal->jam_selesai,
            'urutan_pertemuan' => $jadwal->urutan_pertemuan,
            'matkul' => [
                'id' => $matkul->id ?? null,
                'kode' => $matkul->kode ?? null,
                'nama' => $matkul->nama ?? null,
                'sks' => $matkul->sks ?? null,
            ],
            'kelas' => [
                'id' => $kelas->id ?? null,
                'nama' => $kelas->nama ?? ($matkul->nama ?? null),
            ],
            'dosen' => $jadwal->dosen->map(function ($jd) {
                return [
                    'id' => $jd->dosen->id ?? null,
                    'nama' => $jd->dosen->nama ?? null,
                ];
            })->toArray(),
            'ruangan' => [
                'id' => $jadwal->ruangan->id ?? null,
                'nama' => $jadwal->ruangan->nama ?? null,
            ],
            'jenis_kuliah' => [
                'id' => $jadwal->jenisKuliah->id ?? null,
                'nama' => $jadwal->jenisKuliah->nama ?? null,
            ],
            'krs_status' => $krs && $krs->approved_at ? 'approved' : 'pending',
        ];

        $semesterOut = $kelas->semester ? [
            'id' => $kelas->semester->id,
            'kode' => $kelas->semester->kode,
            'nama' => $kelas->semester->nama,
        ] : null;

        $perkuliahanRows = Perkuliahan::with('materiPerkuliahan')
            ->where('id_jadwal', $jadwal->id)
            ->whereNull('deleted_at')
            ->orderByRaw('waktu_mulai IS NULL')
            ->orderBy('waktu_mulai', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $baseUrl = rtrim((string) config('app.url'), '/');

        $perkuliahanFormatted = $perkuliahanRows->values()->map(function ($p, $idx) use ($mahasiswa, $baseUrl) {
            $kehadiran = Kehadiran::where('id_perkuliahan', $p->id)
                ->where('id_mhs', $mahasiswa->id)
                ->whereNull('deleted_at')
                ->first();

            $materiList = $p->materiPerkuliahan->map(function ($m) use ($baseUrl) {
                $file = $m->file ?? '';

                return [
                    'id' => $m->id,
                    'nama' => $m->nama,
                    'file' => $file,
                    'url' => $file !== '' ? $baseUrl.'/storage/'.ltrim($file, '/') : null,
                ];
            })->values()->all();

            $attrs = $p->getAttributes();
            $realisasi = array_key_exists('realisasi_materi', $attrs) ? $attrs['realisasi_materi'] : null;

            return [
                'id' => $p->id,
                'pertemuan_ke' => $idx + 1,
                'tanggal' => $p->waktu_mulai?->format('Y-m-d'),
                'waktu_mulai' => $p->waktu_mulai?->format('Y-m-d H:i:s'),
                'waktu_selesai' => $p->waktu_selesai?->format('Y-m-d H:i:s'),
                'materi' => $p->materi,
                'realisasi_materi' => $realisasi,
                'materi_perkuliahan' => $materiList,
                'kehadiran_saya' => $kehadiran ? [
                    'status' => $kehadiran->status,
                    'keterangan' => $kehadiran->keterangan,
                ] : null,
            ];
        })->values()->all();

        return response()->json([
            'jadwal' => $jadwalFormatted,
            'semester' => $semesterOut,
            'perkuliahan' => $perkuliahanFormatted,
        ]);
    }

    /**
     * Get KRS untuk mahasiswa yang sedang login
     * Dikelompokkan berdasarkan semester (tahun akademik)
     */
    public function getKrsBySemester(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ambil data mahasiswa dari user
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (! $mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan',
            ], 404);
        }

        // Ambil semua KRS untuk mahasiswa ini
        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $mahasiswa->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $krsList = UrutanMatkulService::urutkanKrs($krsList);

        // Kelompokkan KRS berdasarkan semester
        $krsBySemester = [];

        foreach ($krsList as $krs) {
            $semester = $krs->kelas->semester;

            if (! $semester) {
                continue;
            }

            $semesterId = $semester->id;

            if (! isset($krsBySemester[$semesterId])) {
                $krsBySemester[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'krs' => [],
                    'total_sks_diajukan' => 0,
                    'total_sks_diacc' => 0,
                ];
            }

            $sks = $krs->kelas->kurikulumMatkul->matkul->sks ?? 0;
            $krsBySemester[$semesterId]['total_sks_diajukan'] += $sks;

            if ($krs->approved_at) {
                $krsBySemester[$semesterId]['total_sks_diacc'] += $sks;
            }

            $krsBySemester[$semesterId]['krs'][] = [
                'id' => $krs->id,
                'matkul' => [
                    'id' => $krs->kelas->kurikulumMatkul->matkul->id ?? null,
                    'kode' => $krs->kelas->kurikulumMatkul->matkul->kode ?? null,
                    'nama' => $krs->kelas->kurikulumMatkul->matkul->nama ?? null,
                    'sks' => $sks,
                ],
                'kelas' => [
                    'id' => $krs->kelas->id ?? null,
                    'nama' => $krs->kelas->kurikulumMatkul->matkul->nama ?? null,
                ],
                'dosen' => [
                    'id' => $krs->kelas->dosenPic->id ?? null,
                    'nama' => $krs->kelas->dosenPic->nama ?? null,
                ],
                'status' => $krs->approved_at ? 'approved' : 'pending',
                'approved_at' => $krs->approved_at ? $krs->approved_at->format('Y-m-d H:i:s') : null,
                'approved_by' => $krs->approved_by,
                'created_at' => $krs->created_at->format('Y-m-d H:i:s'),
            ];
        }

        // Sort berdasarkan semester (terbaru dulu)
        usort($krsBySemester, function ($a, $b) {
            return $b['semester']['id'] <=> $a['semester']['id'];
        });

        return response()->json([
            'mahasiswa' => [
                'id' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
            ],
            'data' => array_values($krsBySemester),
        ]);
    }

    /**
     * Export KRS mahasiswa (yang login) ke PDF via Dompdf.
     */
    public function exportKrsPdf(Request $request): StreamedResponse
    {
        $user = $request->user();
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (! $mahasiswa) {
            abort(404, 'Data mahasiswa tidak ditemukan');
        }

        $krsList = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $mahasiswa->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        $krsList = UrutanMatkulService::urutkanKrs($krsList);

        $krsBySemester = [];
        foreach ($krsList as $krs) {
            $semester = $krs->kelas->semester;
            if (! $semester) {
                continue;
            }
            $semesterId = $semester->id;
            if (! isset($krsBySemester[$semesterId])) {
                $krsBySemester[$semesterId] = [
                    'semester' => [
                        'id' => $semester->id,
                        'kode' => $semester->kode,
                        'nama' => $semester->nama,
                    ],
                    'krs' => [],
                    'total_sks_diajukan' => 0,
                    'total_sks_diacc' => 0,
                ];
            }
            $sks = $krs->kelas->kurikulumMatkul->matkul->sks ?? 0;
            $krsBySemester[$semesterId]['total_sks_diajukan'] += $sks;
            if ($krs->approved_at) {
                $krsBySemester[$semesterId]['total_sks_diacc'] += $sks;
            }
            $krsBySemester[$semesterId]['krs'][] = [
                'id' => $krs->id,
                'matkul' => [
                    'kode' => $krs->kelas->kurikulumMatkul->matkul->kode ?? null,
                    'nama' => $krs->kelas->kurikulumMatkul->matkul->nama ?? null,
                    'sks' => $sks,
                ],
                'dosen' => [
                    'nama' => $krs->kelas->dosenPic->nama ?? null,
                ],
                'status' => $krs->approved_at ? 'approved' : 'pending',
                'approved_at' => $krs->approved_at ? $krs->approved_at->format('Y-m-d H:i:s') : null,
            ];
        }
        usort($krsBySemester, fn ($a, $b) => $b['semester']['id'] <=> $a['semester']['id']);
        $krsBySemester = array_values($krsBySemester);

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; }
        .title { text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 4px; }
        .subtitle { text-align: center; margin-bottom: 16px; }
        .section { margin-bottom: 14px; }
        .section-title { font-size: 11pt; font-weight: bold; margin-bottom: 6px; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { background-color: #4472C4; color: white; padding: 6px; border: 1px solid #000; text-align: center; }
        td { padding: 5px; border: 1px solid #000; }
        td.num { text-align: center; }
        .footer { margin-top: 20px; text-align: right; font-size: 9pt; }
        </style></head><body>';

        $html .= '<div class="title">KARTU RENCANA STUDI (KRS)</div>';
        $html .= '<div class="subtitle">'.htmlspecialchars($mahasiswa->nim.' - '.$mahasiswa->nama).'</div>';

        foreach ($krsBySemester as $group) {
            $html .= '<div class="section">';
            $html .= '<div class="section-title">'.htmlspecialchars($group['semester']['nama'].' ('.$group['semester']['kode'].')')
                .' — Total SKS: '.(int) $group['total_sks_diacc'].' / '.(int) $group['total_sks_diajukan'].'</div>';
            $html .= '<table><thead><tr>';
            $html .= '<th style="width:8%">No</th><th style="width:12%">Kode</th><th style="width:32%">Mata Kuliah</th>';
            $html .= '<th style="width:8%">SKS</th><th style="width:25%">Dosen</th><th style="width:15%">Status</th><th style="width:20%">Tgl Disetujui</th>';
            $html .= '</tr></thead><tbody>';

            $no = 1;
            foreach ($group['krs'] as $item) {
                $statusText = ($item['status'] === 'approved') ? 'Disetujui' : 'Menunggu';
                $approvedAt = $item['approved_at']
                    ? date('d/m/Y', strtotime($item['approved_at']))
                    : '-';
                $html .= '<tr>';
                $html .= '<td class="num">'.$no.'</td>';
                $html .= '<td>'.htmlspecialchars($item['matkul']['kode'] ?? '-').'</td>';
                $html .= '<td>'.htmlspecialchars($item['matkul']['nama'] ?? '-').'</td>';
                $html .= '<td class="num">'.(int) ($item['matkul']['sks'] ?? 0).'</td>';
                $html .= '<td>'.htmlspecialchars($item['dosen']['nama'] ?? '-').'</td>';
                $html .= '<td class="num">'.htmlspecialchars($statusText).'</td>';
                $html .= '<td>'.htmlspecialchars($approvedAt).'</td>';
                $html .= '</tr>';
                $no++;
            }
            $html .= '</tbody></table></div>';
        }

        if (empty($krsBySemester)) {
            $html .= '<p>Tidak ada data KRS.</p>';
        }

        $html .= '<div class="footer">Dicetak: '.date('d/m/Y H:i').'</div></body></html>';

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'KRS_'.preg_replace('/\s+/', '_', $mahasiswa->nim).'_'.date('Y-m-d').'.pdf';

        return response()->streamDownload(function () use ($dompdf) {
            echo $dompdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Get kelas yang tersedia untuk pengajuan KRS mahasiswa.
     * Data kelas diambil pada semester aktif, sesuai prodi, angkatan (semester masuk), dan kelas mahasiswa.
     */
    public function getJadwalPengajuan(Request $request): JsonResponse
    {
        $user = $request->user();

        $mahasiswa = Mahasiswa::with(['prodi', 'semester_masuk', 'kelompok_kelas'])
            ->where('id_user', $user->id)->first();

        if (! $mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan',
            ], 404);
        }

        $activeSemester = Semester::where('is_active', true)->first();
        if (! $activeSemester) {
            return response()->json([
                'message' => 'Tidak ada semester aktif',
            ], 404);
        }

        // Query Kelas: semester aktif, prodi, angkatan (id_angkatan = semester masuk), kelas mahasiswa
        $query = Kelas::with([
            'kurikulumMatkul.matkul',
            'prodi',
            'semester',
            'dosenPic',
            'kelompokKelas',
            'jadwal' => function ($q) {
                $q->with(['ruangan', 'jenisKuliah'])->orderBy('hari')->orderBy('jam_mulai');
            },
        ])
            ->whereNull('kelas.deleted_at')
            ->where('id_semester', $activeSemester->id)
            ->where('is_active', true);

        if ($mahasiswa->id_prodi) {
            $query->where('id_prodi', $mahasiswa->id_prodi);
        }
        if ($mahasiswa->id_semester_masuk) {
            $query->where('id_angkatan', $mahasiswa->id_semester_masuk);
        }
        if ($mahasiswa->id_kelompok_kelas !== null && $mahasiswa->id_kelompok_kelas !== '') {
            $query->where('id_kelompok_kelas', $mahasiswa->id_kelompok_kelas);
        }

        $kelasList = $query->orderBy('id')->get();

        $krsByKelas = Krs::where('id_mahasiswa', $mahasiswa->id)
            ->whereNull('deleted_at')
            ->whereIn('id_kelas', $kelasList->pluck('id'))
            ->get()
            ->keyBy('id_kelas');

        $formattedKelas = $kelasList->map(function ($kelas) use ($krsByKelas) {
            $krs = $krsByKelas->get($kelas->id);
            $sudahDipilih = $krs !== null;
            $krsStatus = $krs ? ($krs->approved_at ? 'acc' : 'pending') : null;
            $idKrs = $krs?->id;
            $matkul = $kelas->kurikulumMatkul->matkul ?? null;
            $dosenPic = $kelas->dosenPic ?? null;
            $semester = $kelas->semester ?? null;
            $jadwalFormatted = $kelas->jadwal->map(function ($j) {
                return [
                    'id' => $j->id,
                    'hari' => $j->hari,
                    'jam_mulai' => $j->jam_mulai,
                    'jam_selesai' => $j->jam_selesai,
                    'ruangan' => $j->ruangan ? ['id' => $j->ruangan->id, 'nama' => $j->ruangan->nama] : null,
                    'jenis_kuliah' => $j->jenisKuliah ? ['id' => $j->jenisKuliah->id, 'nama' => $j->jenisKuliah->nama] : null,
                ];
            })->values()->all();

            return [
                'id_kelas' => $kelas->id,
                'kode_kelas' => $kelas->kode ?? null,
                'matkul' => [
                    'id' => $matkul->id ?? null,
                    'kode' => $matkul->kode ?? null,
                    'nama' => $matkul->nama ?? null,
                    'sks' => $matkul->sks ?? 0,
                ],
                'kelas' => [
                    'id' => $kelas->id,
                    'nama' => $matkul->nama ?? $kelas->kode ?? '-',
                ],
                'dosen' => [
                    'id' => $dosenPic->id ?? null,
                    'nama' => $dosenPic->nama ?? null,
                ],
                'semester' => [
                    'id' => $semester->id ?? null,
                    'kode' => $semester->kode ?? null,
                    'nama' => $semester->nama ?? null,
                ],
                'jadwal' => $jadwalFormatted,
                'sudah_dipilih' => $sudahDipilih,
                'krs_status' => $krsStatus,
                'id_krs' => $idKrs,
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
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'kode' => $mahasiswa->semester_masuk->kode,
                    'nama' => $mahasiswa->semester_masuk->nama,
                ] : null,
                'kelompok_kelas' => $mahasiswa->kelompok_kelas ? [
                    'id' => $mahasiswa->kelompok_kelas->id,
                    'nama' => $mahasiswa->kelompok_kelas->nama ?? null,
                ] : null,
            ],
            'semester_aktif' => [
                'id' => $activeSemester->id,
                'kode' => $activeSemester->kode,
                'nama' => $activeSemester->nama,
            ],
            'data' => $formattedKelas->values()->all(),
        ]);
    }

    /**
     * Submit pengajuan KRS untuk mahasiswa yang sedang login
     */
    public function submitPengajuanKrs(Request $request): JsonResponse
    {
        $user = $request->user();

        // Ambil data mahasiswa dari user
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (! $mahasiswa) {
            return response()->json([
                'message' => 'Data mahasiswa tidak ditemukan',
            ], 404);
        }

        $activeSemester = Semester::where('is_active', true)->first();
        $aksesKeuangan = KeuanganAksesMahasiswaService::canAccessByKode(
            $mahasiswa->id,
            'krs',
            $activeSemester?->id
        );
        if (! $aksesKeuangan['allowed']) {
            return response()->json([
                'message' => 'Pengajuan KRS belum dapat dilakukan karena persyaratan administratif keuangan belum terpenuhi. Silakan menyelesaikan pembayaran tagihan sesuai ketentuan.',
                'akses_keuangan' => [
                    'persentase_pembayaran' => $aksesKeuangan['persentase_pembayaran'],
                    'persentase_minimum_required' => $aksesKeuangan['persentase_minimum_required'],
                    'total_tagihan_berlaku' => $aksesKeuangan['total_tagihan_berlaku'],
                    'total_terbayar_disetujui' => $aksesKeuangan['total_terbayar_disetujui'],
                ],
            ], 422);
        }

        $validated = $request->validate([
            'krs' => ['required', 'array', 'min:1'],
            'krs.*.id_kelas' => ['required', 'integer', 'exists:kelas,id'],
        ]);

        $krsData = $validated['krs'];
        $results = [];
        $errors = [];

        // Validasi prasyarat: untuk setiap kelas baru/restored, MK prasyarat harus sudah lulus (nilai huruf minimal C di tabel nilai)
        $prasyaratViolations = [];
        foreach ($krsData as $data) {
            $existing = Krs::withTrashed()
                ->where('id_mahasiswa', $mahasiswa->id)
                ->where('id_kelas', $data['id_kelas'])
                ->first();

            if ($existing && ! $existing->trashed()) {
                continue;
            }

            $kelas = Kelas::with(['kurikulumMatkul.matkul'])->find($data['id_kelas']);
            if (! $kelas || ! $kelas->kurikulumMatkul) {
                continue;
            }

            $idMatkulInduk = (int) $kelas->kurikulumMatkul->id_matkul;
            $namaMatkulInduk = $kelas->kurikulumMatkul->nama_matkul
                ?: ($kelas->kurikulumMatkul->matkul->nama ?? 'Mata kuliah');

            $prasyaratIds = MatkulPrasyarat::query()
                ->where('id_matkul', $idMatkulInduk)
                ->whereNull('deleted_at')
                ->pluck('id_matkul_prasyarat')
                ->unique()
                ->values();

            foreach ($prasyaratIds as $idMatkulPrasyarat) {
                $idMatkulPrasyarat = (int) $idMatkulPrasyarat;
                if ($this->mahasiswaHasLulusMatkulMinimalC($mahasiswa->id, $idMatkulPrasyarat)) {
                    continue;
                }
                $mkPr = Matkul::find($idMatkulPrasyarat);
                $prasyaratViolations[] = [
                    'id_kelas' => $data['id_kelas'],
                    'matkul_diajukan' => $namaMatkulInduk,
                    'kode_matkul_prasyarat' => $mkPr->kode ?? null,
                    'matkul_prasyarat' => $mkPr->nama ?? 'Mata kuliah prasyarat',
                ];
            }
        }

        if (! empty($prasyaratViolations)) {
            return response()->json([
                'message' => 'Mata kuliah prasyarat belum terpenuhi. Mahasiswa harus memiliki nilai minimal C (lulus) untuk mata kuliah prasyarat pada riwayat nilai.',
                'prasyarat_tidak_terpenuhi' => $prasyaratViolations,
            ], 422);
        }

        $jumlahPengajuanBaru = 0;

        DB::beginTransaction();
        try {
            foreach ($krsData as $index => $data) {
                // Cek KRS yang ada (termasuk soft-deleted) agar tidak melanggar unique constraint
                $existing = Krs::withTrashed()
                    ->where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_kelas', $data['id_kelas'])
                    ->first();

                if ($existing) {
                    if (! $existing->trashed()) {
                        // Sudah ada KRS aktif: skip (idempotent), masukkan ke results
                        $existing->load(['mahasiswa.prodi', 'kelas.kurikulumMatkul.matkul', 'kelas.semester']);
                        $results[] = $existing;

                        continue;
                    }
                    // Restore KRS yang pernah dihapus (soft delete) dan set ulang ke pending
                    $existing->restore();
                    $existing->update([
                        'status' => 'pending',
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                    $krs = $existing;
                } else {
                    $krs = Krs::create([
                        'id_mahasiswa' => $mahasiswa->id,
                        'id_kelas' => $data['id_kelas'],
                        'status' => 'pending',
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                }

                $jumlahPengajuanBaru++;
                $krs->load(['mahasiswa.prodi', 'kelas.kurikulumMatkul.matkul', 'kelas.semester']);
                $results[] = $krs;
            }

            if (! empty($errors)) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Beberapa KRS gagal disimpan karena duplikasi atau kesalahan lainnya.',
                    'errors' => $errors,
                    'data' => $results,
                ], 422);
            }

            DB::commit();

            if ($jumlahPengajuanBaru > 0) {
                $dosenWaliAktif = \App\Models\DosenWali::where('id_mahasiswa', $mahasiswa->id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->with('dosen')
                    ->first();
                $idUserDosenWali = $dosenWaliAktif?->dosen?->id_user;
                if ($idUserDosenWali) {
                    $pesan = $jumlahPengajuanBaru === 1
                        ? "{$mahasiswa->nama} mengajukan 1 mata kuliah KRS yang perlu Anda setujui."
                        : "{$mahasiswa->nama} mengajukan {$jumlahPengajuanBaru} mata kuliah KRS yang perlu Anda setujui.";
                    Notifikasi::kirim(
                        idUser: $idUserDosenWali,
                        tipe: 'krs_diajukan',
                        judul: 'Pengajuan KRS baru',
                        pesan: $pesan,
                        url: '/dosen/perwalian/persetujuan-krs',
                    );
                }
            }

            return response()->json([
                'message' => 'Pengajuan KRS berhasil disimpan',
                'data' => $results,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyimpan pengajuan KRS: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batalkan pengajuan KRS (hapus) untuk mahasiswa yang login. Hanya KRS dengan status pending (belum disetujui) yang boleh dibatalkan.
     */
    public function cancelPengajuanKrs(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $mahasiswa = Mahasiswa::where('id_user', $user->id)->first();

        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $krs = Krs::where('id', $id)
            ->where('id_mahasiswa', $mahasiswa->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $krs) {
            return response()->json(['message' => 'KRS tidak ditemukan atau tidak dapat dibatalkan'], 404);
        }

        if ($krs->approved_at !== null) {
            return response()->json(['message' => 'KRS yang sudah disetujui tidak dapat dibatalkan'], 422);
        }

        $krs->delete();

        return response()->json(['message' => 'Pengajuan KRS berhasil dibatalkan']);
    }

    /**
     * Get count of mahasiswa by kelas IDs (batch)
     */
    public function getMahasiswaCount(Request $request): JsonResponse
    {
        $kelasIds = $request->get('kelas_ids');

        if (! $kelasIds || ! is_array($kelasIds)) {
            return response()->json([
                'counts' => [],
            ]);
        }

        // Get counts
        $counts = Krs::whereIn('id_kelas', $kelasIds)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->selectRaw('id_kelas, COUNT(DISTINCT id_mahasiswa) as count')
            ->groupBy('id_kelas')
            ->pluck('count', 'id_kelas')
            ->toArray();

        // Format response
        $result = [];
        foreach ($kelasIds as $kelasId) {
            $result[$kelasId] = $counts[$kelasId] ?? 0;
        }

        return response()->json([
            'counts' => $result,
        ]);
    }

    /**
     * Get mahasiswa bimbingan dengan statistik KRS untuk dosen yang sedang login
     */
    public function getMahasiswaBimbingan(Request $request): JsonResponse
    {
        $user = $request->user();
        $dosen = \App\Models\Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $idSemester = $request->get('id_semester');
        $search = $request->get('search');
        $perPage = (int) $request->get('per_page', 100);
        $page = (int) $request->get('page', 1);

        // Jika tidak ada parameter, gunakan semester aktif
        if (! $idSemester) {
            $activeSemester = Semester::where('is_active', true)->first();
            if ($activeSemester) {
                $idSemester = $activeSemester->id;
            }
        }

        // Query mahasiswa bimbingan dosen
        $query = \App\Models\DosenWali::with([
            'mahasiswa.prodi',
            'mahasiswa.prodi.jenjang',
            'mahasiswa.semester_masuk',
        ])
            ->where('id_dosen', $dosen->id)
            ->where('status', 'active')
            ->whereNull('deleted_at');

        // Filter berdasarkan pencarian nama atau nim
        if ($search) {
            $query->whereHas('mahasiswa', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nim', 'like', "%{$search}%");
            });
        }

        // Hitung total sebelum pagination
        $total = $query->count();

        // Pagination
        $offset = ($page - 1) * $perPage;
        $mahasiswaBimbingan = $query->offset($offset)->limit($perPage)->get();

        // Hitung statistik KRS untuk setiap mahasiswa
        $result = $mahasiswaBimbingan->map(function ($dosenWali) use ($idSemester) {
            $mahasiswa = $dosenWali->mahasiswa;

            // Query KRS untuk mahasiswa ini (hanya yang berlaku: active + pending pengajuan; bukan inactive)
            $krsQuery = Krs::where('id_mahasiswa', $mahasiswa->id)
                ->whereNull('deleted_at');

            // Filter berdasarkan semester jika ada
            if ($idSemester) {
                $krsQuery->whereHas('kelas', function ($q) use ($idSemester) {
                    $q->where('id_semester', $idSemester);
                });
            }

            $krsList = $krsQuery
                ->with(['kelas.kurikulumMatkul.matkul:id,sks'])
                ->get();

            // Hitung statistik
            $totalKrs = $krsList->count();
            $krsDiacc = $krsList->whereNotNull('approved_at')->count();
            $persentaseDiacc = $totalKrs > 0 ? round(($krsDiacc / $totalKrs) * 100, 2) : 0;
            $sksDiajukan = (int) $krsList->sum(function (Krs $krs): int {
                return (int) ($krs->kelas?->kurikulumMatkul?->matkul?->sks ?? 0);
            });
            $sksDiacc = (int) $krsList
                ->whereNotNull('approved_at')
                ->sum(function (Krs $krs): int {
                    return (int) ($krs->kelas?->kurikulumMatkul?->matkul?->sks ?? 0);
                });
            $sksBelumDiacc = max($sksDiajukan - $sksDiacc, 0);

            return [
                'id_mahasiswa' => $mahasiswa->id,
                'nim' => $mahasiswa->nim,
                'nama' => $mahasiswa->nama,
                'prodi' => $mahasiswa->prodi ? [
                    'id' => $mahasiswa->prodi->id,
                    'nama' => $mahasiswa->prodi->nama,
                    'jenjang' => $mahasiswa->prodi->jenjang ? [
                        'id' => $mahasiswa->prodi->jenjang->id,
                        'nama' => $mahasiswa->prodi->jenjang->nama,
                    ] : null,
                ] : null,
                'semester_masuk' => $mahasiswa->semester_masuk ? [
                    'id' => $mahasiswa->semester_masuk->id,
                    'kode' => $mahasiswa->semester_masuk->kode,
                    'nama' => $mahasiswa->semester_masuk->nama,
                ] : null,
                'statistik_krs' => [
                    'total' => $totalKrs,
                    'diacc' => $krsDiacc,
                    'persentase_diacc' => $persentaseDiacc,
                    'sks_diajukan' => $sksDiajukan,
                    'sks_diacc' => $sksDiacc,
                    'sks_belum_diacc' => $sksBelumDiacc,
                ],
            ];
        });

        // Ambil semester aktif untuk response
        $activeSemester = Semester::where('is_active', true)->first();
        $selectedSemester = $idSemester
            ? Semester::find($idSemester)
            : $activeSemester;

        return response()->json([
            'semester' => $selectedSemester ? [
                'id' => $selectedSemester->id,
                'kode' => $selectedSemester->kode,
                'nama' => $selectedSemester->nama,
            ] : null,
            'data' => $result,
        ]);
    }

    /**
     * Get KRS yang belum disetujui untuk mahasiswa bimbingan dosen
     */
    public function getKrsPending(Request $request, int $idMahasiswa): JsonResponse
    {
        $user = $request->user();
        $dosen = \App\Models\Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        // Verifikasi bahwa mahasiswa adalah bimbingan dosen ini
        $dosenWali = \App\Models\DosenWali::where('id_dosen', $dosen->id)
            ->where('id_mahasiswa', $idMahasiswa)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->first();

        if (! $dosenWali) {
            return response()->json([
                'message' => 'Mahasiswa bukan bimbingan Anda',
            ], 403);
        }

        $idSemester = $request->get('id_semester');
        $modeAll = $request->get('mode') === 'all';

        // Jika tidak ada parameter, gunakan semester aktif
        if (! $idSemester) {
            $activeSemester = Semester::where('is_active', true)->first();
            if ($activeSemester) {
                $idSemester = $activeSemester->id;
            }
        }

        // Ambil KRS: mode=all = semua (disetujui + pending), default = hanya yang belum disetujui
        $krsQuery = Krs::with([
            'kelas.kurikulumMatkul.matkul',
            'kelas.kurikulumMatkul.kurikulum',
            'kelas.prodi',
            'kelas.semester',
            'kelas.dosenPic',
        ])
            ->where('id_mahasiswa', $idMahasiswa)
            ->whereNull('deleted_at');

        if (! $modeAll) {
            $krsQuery->whereNull('approved_at');
        }

        // Filter berdasarkan semester jika ada
        if ($idSemester) {
            $krsQuery->whereHas('kelas', function ($q) use ($idSemester) {
                $q->where('id_semester', $idSemester);
            });
        }

        $krsList = UrutanMatkulService::urutkanKrs($krsQuery->orderBy('created_at', 'desc')->get());

        $result = $krsList->map(function ($krs) {
            $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
            $kurikulumMatkul = $krs->kelas->kurikulumMatkul ?? null;
            $semester = $krs->kelas->semester ?? null;
            $dosenPic = $krs->kelas->dosenPic ?? null;
            $prodi = $krs->kelas->prodi ?? null;

            $item = [
                'id' => $krs->id,
                'kode_matkul' => $kurikulumMatkul->kode_matkul ?? $matkul->kode ?? null,
                'nama_matkul' => $kurikulumMatkul->nama_matkul ?? $matkul->nama ?? null,
                'sks' => $kurikulumMatkul->sks ?? $matkul->sks ?? 0,
                'semester' => $semester ? [
                    'id' => $semester->id,
                    'kode' => $semester->kode,
                    'nama' => $semester->nama,
                ] : null,
                'dosen_pic' => $dosenPic ? [
                    'id' => $dosenPic->id,
                    'nama' => $dosenPic->nama,
                ] : null,
                'prodi' => $prodi ? [
                    'id' => $prodi->id,
                    'nama' => $prodi->nama,
                ] : null,
                'kelas' => [
                    'id' => $krs->kelas->id,
                    'nama' => $krs->kelas->nama ?? null,
                ],
            ];

            // Status persetujuan (untuk UI): acc vs pending — selalu kirim bersama approved_at
            if ($krs->approved_at) {
                $item['status'] = 'acc';
                $item['approved_at'] = $krs->approved_at;
            } else {
                $item['status'] = 'pending';
                $item['approved_at'] = null;
            }

            return $item;
        });

        // Ambil semester aktif untuk response
        $activeSemester = Semester::where('is_active', true)->first();
        $selectedSemester = $idSemester
            ? Semester::find($idSemester)
            : $activeSemester;

        return response()->json([
            'semester' => $selectedSemester ? [
                'id' => $selectedSemester->id,
                'kode' => $selectedSemester->kode,
                'nama' => $selectedSemester->nama,
            ] : null,
            'data' => $result,
        ]);
    }

    /**
     * Approve KRS (bulk)
     */
    public function approveKrs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'krs_ids' => ['required', 'array', 'min:1'],
            'krs_ids.*' => ['required', 'integer', 'exists:krs,id'],
        ]);

        $user = $request->user();
        $dosen = \App\Models\Dosen::where('id_user', $user->id)->first();

        if (! $dosen) {
            return response()->json([
                'message' => 'Data dosen tidak ditemukan',
            ], 404);
        }

        $krsIds = $validated['krs_ids'];
        $approvedBy = $user->name ?? $user->email ?? null;

        DB::beginTransaction();
        try {
            // Verifikasi bahwa semua KRS adalah bimbingan dosen ini
            $krsList = Krs::whereIn('id', $krsIds)
                ->whereNull('deleted_at')
                ->get();

            foreach ($krsList as $krs) {
                $dosenWali = \App\Models\DosenWali::where('id_dosen', $dosen->id)
                    ->where('id_mahasiswa', $krs->id_mahasiswa)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();

                if (! $dosenWali) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Salah satu KRS bukan bimbingan Anda',
                    ], 403);
                }
            }

            // Mahasiswa yang KRS-nya benar-benar berubah dari pending -> approved (untuk notifikasi)
            $idMahasiswaBaruDisetujui = $krsList->whereNull('approved_at')->pluck('id_mahasiswa')->unique();

            // Update KRS
            Krs::whereIn('id', $krsIds)
                ->whereNull('approved_at')
                ->update([
                    'approved_by' => $approvedBy,
                    'approved_at' => now(),
                ]);

            DB::commit();

            $idUserPerMahasiswa = Mahasiswa::whereIn('id', $idMahasiswaBaruDisetujui)
                ->whereNotNull('id_user')
                ->pluck('id_user');
            foreach ($idUserPerMahasiswa as $idUser) {
                Notifikasi::kirim(
                    idUser: $idUser,
                    tipe: 'krs_disetujui',
                    judul: 'KRS disetujui',
                    pesan: 'KRS Anda sudah disetujui dosen wali.',
                    url: '/mahasiswa/krs',
                );
            }

            return response()->json([
                'message' => 'KRS berhasil disetujui',
                'approved_count' => count($krsIds),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Terjadi kesalahan saat menyetujui KRS: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apakah huruf mutu memenuhi kelulusan minimal C (A, B, C dan varian; D/E tidak).
     */
    private function hurufMutuMemenuhiMinimalC(?string $huruf): bool
    {
        if ($huruf === null || trim($huruf) === '') {
            return false;
        }
        $base = strtoupper(substr(trim($huruf), 0, 1));

        return in_array($base, ['A', 'B', 'C'], true);
    }

    /**
     * Mahasiswa punya nilai final (minimal C) untuk id_matkul lewat salah satu KRS + baris nilai.
     */
    private function mahasiswaHasLulusMatkulMinimalC(int $idMahasiswa, int $idMatkul): bool
    {
        return Nilai::query()
            ->whereHas('krs', function ($q) use ($idMahasiswa, $idMatkul) {
                $q->where('id_mahasiswa', $idMahasiswa)
                    ->whereNull('deleted_at')
                    ->whereHas('kelas.kurikulumMatkul', function ($q2) use ($idMatkul) {
                        $q2->where('id_matkul', $idMatkul);
                    });
            })
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('is_final', true)->orWhereNull('is_final');
            })
            ->get()
            ->contains(fn ($n) => $this->hurufMutuMemenuhiMinimalC($n->huruf_mutu));
    }

    /**
     * Cocokkan baris perkuliahan terkait untuk satu slot jadwal (sama logika ringkas halaman dosen).
     *
     * @param  Collection<int, Perkuliahan>  $perkuliahanRows
     */
    private function findPerkuliahanForJadwalSlotKrs(Jadwal $j, Collection $perkuliahanRows): ?Perkuliahan
    {
        $slotId = (int) $j->id;
        $candidates = $perkuliahanRows->filter(fn ($p) => (int) $p->id_jadwal === $slotId);

        $ts = static function (?Perkuliahan $p): int {
            if ($p === null || ! $p->waktu_mulai) {
                return 0;
            }

            return \Carbon\Carbon::parse($p->waktu_mulai)->getTimestamp();
        };

        $ongoing = $candidates
            ->filter(function (Perkuliahan $p) {
                return $p->waktu_mulai && ! $p->waktu_selesai;
            })
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
     * Status sesi untuk tampilan mahasiswa (apakah pertemuan sudah dilaksanakan).
     *
     * @return array{sesi_status: string, sesi_status_label: string}
     */
    private function sesiStatusForPerkuliahanKrs(?Perkuliahan $p): array
    {
        if ($p === null) {
            return [
                'sesi_status' => 'belum_mulai',
                'sesi_status_label' => 'Belum dilaksanakan',
            ];
        }

        $mulai = $p->waktu_mulai !== null && trim((string) $p->waktu_mulai) !== '';
        $selesai = $p->waktu_selesai !== null && trim((string) $p->waktu_selesai) !== '';

        if (! $mulai) {
            return [
                'sesi_status' => 'belum_mulai',
                'sesi_status_label' => 'Belum dilaksanakan',
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
            'sesi_status_label' => 'Sudah dilaksanakan',
        ];
    }
}
