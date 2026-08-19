<?php

namespace App\Http\Controllers;

use App\Models\Dosen;
use App\Models\JenisMatkul;
use App\Models\Krs;
use App\Models\Mahasiswa;
use App\Models\Nilai;
use App\Models\RentangNilai;
use App\Models\Semester;
use App\Models\TugasAkhir;
use App\Models\TugasAkhirBimbingan;
use App\Models\TugasAkhirPembimbing;
use App\Models\TugasAkhirStatusLog;
use App\Models\UjianSidang;
use App\Models\UjianSidangPenguji;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TugasAkhirController extends Controller
{
    /** Status pada tugas_akhir: draft, submitted, approved, rejected, returned */
    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'returned'];

    /** Keputusan admin (disimpan di log + dipetakan ke kolom status tugas_akhir). */
    private const KEPUTUSAN_STATUS = ['acc', 'returned', 'declined'];

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');
        $prodiId = $request->get('id_prodi') ? (int) $request->get('id_prodi') : null;
        $semesterId = $request->get('id_semester') ? (int) $request->get('id_semester') : null;
        $status = $request->get('status');
        $jenis = $request->get('jenis');

        $query = TugasAkhir::query()
            ->with([
                'mahasiswa.prodi',
                'semester',
            ])
            ->orderByDesc('updated_at');

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('mahasiswa', function ($q) use ($allowedProdiIds) {
                    $q->whereIn('id_prodi', $allowedProdiIds);
                });
                if ($prodiId !== null && ! in_array($prodiId, $allowedProdiIds, true)) {
                    $prodiId = null;
                }
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('mahasiswa', function ($mq) use ($search) {
                    $mq->where('nama', 'like', "%{$search}%")
                        ->orWhere('nim', 'like', "%{$search}%");
                })->orWhere('judul', 'like', "%{$search}%");
            });
        }

        if ($prodiId !== null) {
            $query->whereHas('mahasiswa', function ($q) use ($prodiId) {
                $q->where('id_prodi', $prodiId);
            });
        }

        if ($semesterId !== null) {
            $query->where('id_semester', $semesterId);
        }

        if ($status !== null && $status !== '' && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($jenis === 'proposal') {
            $query->where('is_proposal', true);
        } elseif ($jenis === 'akhir') {
            $query->where('is_proposal', false);
        }

        $data = $query->paginate($perPage);

        return response()->json($data);
    }

    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'NIM*',
            'Kode Semester*',
            'Judul*',
            'Status (Opsional, default: submitted)',
            'Judul (English) (Opsional)',
            'Topik (Opsional)',
            'Topik (English) (Opsional)',
            'Deskripsi (Opsional)',
            'Is Proposal (true/false, Opsional, default: true)',
        ];
        $sheet->fromArray([$headers], null, 'A1');

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(26);
        }

        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $exampleRow = [
            '2020001',
            '20251',
            'Sistem Informasi Akademik Berbasis Web',
            'approved',
            'Web-Based Academic Information System',
            'Rekayasa Perangkat Lunak',
            'Software Engineering',
            '',
            'false',
        ];
        $sheet->fromArray([$exampleRow], null, 'A2');

        $filename = 'template_import_tugas_akhir_'.date('YmdHis').'.xlsx';

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
     * Import massal data tugas akhir dari Excel. Tidak ada endpoint store() admin-side yang bisa
     * dicerminkan langsung (mahasiswa mengajukan sendiri lewat storePengajuanMahasiswa, terikat
     * KRS TA semester aktif) — import ini dipakai untuk mengisi data historis/hasil migrasi, jadi
     * SENGAJA tidak mensyaratkan KRS Tugas Akhir yang disetujui seperti storePengajuanMahasiswa.
     * Validasi lain yang tetap disamakan: mahasiswa & semester wajib ada, scope prodi admin
     * dihormati, dan satu mahasiswa hanya boleh punya satu baris tugas_akhir per semester (modul
     * ini tidak mendukung ubah/hapus lewat import, jadi duplikat dilaporkan sebagai error).
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (count($rows) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'File Excel kosong atau tidak valid.',
            ], 400);
        }

        array_shift($rows);

        $errors = [];
        $successCount = 0;

        $user = $request->user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;
        $actor = $user ? (trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '')) : 'system';

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $nim = trim((string) ($row[0] ?? ''));
                $kodeSemester = trim((string) ($row[1] ?? ''));
                $judul = trim((string) ($row[2] ?? ''));
                $statusRaw = trim((string) ($row[3] ?? ''));
                $isProposalRaw = trim(strtolower((string) ($row[8] ?? '')));

                if ($nim === '') {
                    $errors[] = "Baris {$rowNumber}: NIM wajib diisi.";

                    continue;
                }

                if ($kodeSemester === '') {
                    $errors[] = "Baris {$rowNumber}: Kode Semester wajib diisi.";

                    continue;
                }

                if ($judul === '') {
                    $errors[] = "Baris {$rowNumber}: Judul wajib diisi.";

                    continue;
                }

                $mahasiswa = Mahasiswa::where('nim', $nim)->first();
                if (! $mahasiswa) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa dengan NIM '{$nim}' tidak ditemukan.";

                    continue;
                }

                if ($allowedProdiIds !== null && ! in_array((int) $mahasiswa->id_prodi, $allowedProdiIds, true)) {
                    $errors[] = "Baris {$rowNumber}: Anda tidak memiliki akses ke mahasiswa NIM '{$nim}' (prodi di luar scope).";

                    continue;
                }

                $semester = Semester::where('kode', $kodeSemester)->first();
                if (! $semester) {
                    $errors[] = "Baris {$rowNumber}: Semester dengan kode '{$kodeSemester}' tidak ditemukan.";

                    continue;
                }

                $status = $statusRaw === '' ? 'submitted' : $statusRaw;
                if (! in_array($status, self::STATUSES, true)) {
                    $errors[] = "Baris {$rowNumber}: Status '{$statusRaw}' tidak valid. Gunakan salah satu: ".implode(', ', self::STATUSES).'.';

                    continue;
                }

                $duplikat = TugasAkhir::where('id_mahasiswa', $mahasiswa->id)
                    ->where('id_semester', $semester->id)
                    ->exists();
                if ($duplikat) {
                    $errors[] = "Baris {$rowNumber}: Mahasiswa NIM '{$nim}' sudah memiliki data tugas akhir untuk semester '{$kodeSemester}'.";

                    continue;
                }

                $isProposal = $isProposalRaw === '' ? true : filter_var($isProposalRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isProposal === null) {
                    $isProposal = true;
                }

                TugasAkhir::create([
                    'id_mahasiswa' => $mahasiswa->id,
                    'id_semester' => $semester->id,
                    'judul' => $judul,
                    'judul_en' => self::nullIfBlank($row[4] ?? null),
                    'topik' => self::nullIfBlank($row[5] ?? null),
                    'topik_en' => self::nullIfBlank($row[6] ?? null),
                    'deskripsi' => self::nullIfBlank($row[7] ?? null),
                    'is_proposal' => $isProposal,
                    'status' => $status,
                    'created_by' => $actor,
                    'updated_by' => $actor,
                ]);
                $successCount++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Import selesai. Berhasil: {$successCount}, Error: ".count($errors),
                'success_count' => $successCount,
                'error_count' => count($errors),
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import tugas akhir gagal', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengimpor data: '.$e->getMessage(),
                'errors' => $errors,
            ], 500);
        }
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    public function show(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $tugasAkhir->load([
            'mahasiswa.prodi',
            'mahasiswa.semester_masuk',
            'mahasiswa.status_akademik',
            'mahasiswa.grup_mahasiswa',
            'semester',
            'pembimbing.dosen',
            'ujianSidang.semester',
            'ujianSidang.penguji.dosen',
            'statusLogs.user',
        ]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && $tugasAkhir->mahasiswa
                && ! in_array((int) $tugasAkhir->mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data tugas akhir ini.');
            }
        }

        return response()->json([
            'success' => true,
            'data' => $tugasAkhir,
        ]);
    }

    /**
     * Ubah status pengajuan tugas akhir (admin) dan catat ke tugas_akhir_status_logs.
     * Keputusan: acc → approved, returned → returned, declined → rejected.
     */
    public function updateStatus(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);

        $validated = $request->validate([
            'keputusan' => ['required', 'string', Rule::in(self::KEPUTUSAN_STATUS)],
            'keterangan' => ['nullable', 'string', 'max:2000'],
        ]);

        $mapKeTugasAkhir = [
            'acc' => 'approved',
            'returned' => 'returned',
            'declined' => 'rejected',
        ];
        $statusBaru = $mapKeTugasAkhir[$validated['keputusan']];

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        DB::transaction(function () use ($tugasAkhir, $validated, $statusBaru, $user, $actor): void {
            TugasAkhirStatusLog::create([
                'id_tugas_akhir' => $tugasAkhir->id,
                'status' => $validated['keputusan'],
                'keterangan' => $validated['keterangan'] ?? null,
                'id_user' => $user->id,
            ]);

            $tugasAkhir->update([
                'status' => $statusBaru,
                'updated_by' => $actor,
            ]);
        });

        $tugasAkhir->refresh()->load([
            'mahasiswa.prodi',
            'mahasiswa.semester_masuk',
            'mahasiswa.status_akademik',
            'mahasiswa.grup_mahasiswa',
            'semester',
            'pembimbing.dosen',
            'ujianSidang.semester',
            'ujianSidang.penguji.dosen',
            'statusLogs.user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status pengajuan tugas akhir diperbarui.',
            'data' => $tugasAkhir,
        ]);
    }

    private function assertTugasAkhirProdiScope(Request $request, TugasAkhir $tugasAkhir): void
    {
        $tugasAkhir->loadMissing('mahasiswa');
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null && $tugasAkhir->mahasiswa
                && ! in_array((int) $tugasAkhir->mahasiswa->id_prodi, $allowedProdiIds, true)) {
                abort(403, 'Anda tidak memiliki akses ke data tugas akhir ini.');
            }
        }
    }

    private function assertUjianSidangForTugasAkhir(TugasAkhir $tugasAkhir, UjianSidang $ujianSidang): void
    {
        if ((int) $ujianSidang->id_tugas_akhir !== (int) $tugasAkhir->id) {
            abort(404, 'Ujian sidang tidak ditemukan untuk tugas akhir ini.');
        }
    }

    public function storePembimbing(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);

        $validated = $request->validate([
            'id_dosen' => ['required', 'integer', 'exists:dosen,id'],
            'tanggal_penugasan' => ['nullable', 'date'],
        ]);

        $dup = TugasAkhirPembimbing::query()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->where('id_dosen', $validated['id_dosen'])
            ->where('peran', 'pembimbing')
            ->exists();
        if ($dup) {
            return response()->json(['message' => 'Dosen ini sudah terdaftar sebagai pembimbing.'], 422);
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $row = TugasAkhirPembimbing::create([
            'id_tugas_akhir' => $tugasAkhir->id,
            'id_dosen' => $validated['id_dosen'],
            'peran' => 'pembimbing',
            'tanggal_penugasan' => $validated['tanggal_penugasan'] ?? null,
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);
        $row->load('dosen');

        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function updatePembimbing(Request $request, TugasAkhir $tugasAkhir, TugasAkhirPembimbing $pembimbing): JsonResponse
    {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        if ((int) $pembimbing->id_tugas_akhir !== (int) $tugasAkhir->id || $pembimbing->peran !== 'pembimbing') {
            abort(404);
        }

        $validated = $request->validate([
            'id_dosen' => ['sometimes', 'required', 'integer', 'exists:dosen,id'],
            'tanggal_penugasan' => ['nullable', 'date'],
        ]);

        if (isset($validated['id_dosen']) && (int) $validated['id_dosen'] !== (int) $pembimbing->id_dosen) {
            $dup = TugasAkhirPembimbing::query()
                ->where('id_tugas_akhir', $tugasAkhir->id)
                ->where('id_dosen', $validated['id_dosen'])
                ->where('peran', 'pembimbing')
                ->where('id', '!=', $pembimbing->id)
                ->exists();
            if ($dup) {
                return response()->json(['message' => 'Dosen ini sudah terdaftar sebagai pembimbing lain.'], 422);
            }
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $pembimbing->fill(array_merge(
            array_intersect_key($validated, array_flip(['id_dosen', 'tanggal_penugasan'])),
            ['updated_by' => $actor]
        ));
        $pembimbing->save();
        $pembimbing->load('dosen');

        return response()->json(['success' => true, 'data' => $pembimbing]);
    }

    public function destroyPembimbing(Request $request, TugasAkhir $tugasAkhir, TugasAkhirPembimbing $pembimbing): JsonResponse
    {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        if ((int) $pembimbing->id_tugas_akhir !== (int) $tugasAkhir->id || $pembimbing->peran !== 'pembimbing') {
            abort(404);
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');
        $pembimbing->update(['deleted_by' => $actor, 'updated_by' => $actor]);
        $pembimbing->delete();

        return response()->json(['success' => true, 'message' => 'Pembimbing dihapus.']);
    }

    public function storeUjianSidang(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);

        $validated = $request->validate([
            'id_semester' => ['required', 'integer', 'exists:semester,id'],
            'tanggal_ujian_mulai' => ['nullable', 'date'],
            'tanggal_ujian_selesai' => ['nullable', 'date'],
        ]);

        $mulai = $validated['tanggal_ujian_mulai'] ?? null;
        $selesai = $validated['tanggal_ujian_selesai'] ?? null;
        if ($mulai !== null && $mulai !== '' && $selesai !== null && $selesai !== '') {
            if (Carbon::parse((string) $selesai)->lt(Carbon::parse((string) $mulai))) {
                return response()->json([
                    'message' => 'Tanggal selesai ujian harus sama atau setelah tanggal mulai.',
                ], 422);
            }
        }

        $exists = UjianSidang::query()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->where('id_semester', $validated['id_semester'])
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => 'Ujian sidang untuk semester ini sudah ada.',
            ], 422);
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $row = UjianSidang::create([
            'id_tugas_akhir' => $tugasAkhir->id,
            'id_semester' => $validated['id_semester'],
            'tanggal_daftar' => now(),
            'tanggal_ujian_mulai' => ($mulai !== null && $mulai !== '') ? Carbon::parse((string) $mulai) : null,
            'tanggal_ujian_selesai' => ($selesai !== null && $selesai !== '') ? Carbon::parse((string) $selesai) : null,
            'status' => 'draft',
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);
        $row->load('semester');

        return response()->json(['success' => true, 'data' => $row], 201);
    }

    /** Status pada ujian_sidang (selaras migrasi). */
    private const UJIAN_SIDANG_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];

    /**
     * Perbarui ujian sidang (admin): jadwal mulai/selesai dan/atau status pengajuan.
     */
    public function updateUjianSidang(
        Request $request,
        TugasAkhir $tugasAkhir,
        UjianSidang $ujianSidang
    ): JsonResponse {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        $this->assertUjianSidangForTugasAkhir($tugasAkhir, $ujianSidang);

        $validated = $request->validate([
            'tanggal_ujian_mulai' => ['sometimes', 'nullable', 'date'],
            'tanggal_ujian_selesai' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(self::UJIAN_SIDANG_STATUSES)],
        ]);

        if (
            ! array_key_exists('tanggal_ujian_mulai', $validated)
            && ! array_key_exists('tanggal_ujian_selesai', $validated)
            && ! array_key_exists('status', $validated)
        ) {
            return response()->json([
                'message' => 'Sertakan minimal salah satu: tanggal_ujian_mulai, tanggal_ujian_selesai, atau status.',
            ], 422);
        }

        $finalMulai = $ujianSidang->tanggal_ujian_mulai;
        $finalSelesai = $ujianSidang->tanggal_ujian_selesai;

        if (array_key_exists('tanggal_ujian_mulai', $validated)) {
            $r = $validated['tanggal_ujian_mulai'];
            $finalMulai = ($r !== null && $r !== '') ? Carbon::parse((string) $r) : null;
        }
        if (array_key_exists('tanggal_ujian_selesai', $validated)) {
            $r = $validated['tanggal_ujian_selesai'];
            $finalSelesai = ($r !== null && $r !== '') ? Carbon::parse((string) $r) : null;
        }

        if ($finalMulai !== null && $finalSelesai !== null && $finalSelesai->lt($finalMulai)) {
            return response()->json([
                'message' => 'Waktu selesai ujian harus sama atau setelah waktu mulai.',
            ], 422);
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $update = ['updated_by' => $actor];
        if (array_key_exists('tanggal_ujian_mulai', $validated)) {
            $update['tanggal_ujian_mulai'] = $finalMulai;
        }
        if (array_key_exists('tanggal_ujian_selesai', $validated)) {
            $update['tanggal_ujian_selesai'] = $finalSelesai;
        }
        if (array_key_exists('status', $validated)) {
            $update['status'] = $validated['status'];
        }

        $ujianSidang->update($update);
        $ujianSidang->load(['semester', 'penguji.dosen']);

        return response()->json([
            'success' => true,
            'message' => 'Data ujian sidang diperbarui.',
            'data' => $ujianSidang,
        ]);
    }

    public function storePengujiSidang(
        Request $request,
        TugasAkhir $tugasAkhir,
        UjianSidang $ujianSidang
    ): JsonResponse {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        $this->assertUjianSidangForTugasAkhir($tugasAkhir, $ujianSidang);

        $validated = $request->validate([
            'id_dosen' => ['required', 'integer', 'exists:dosen,id'],
            'is_ketua' => ['sometimes', 'boolean'],
            'catatan' => ['nullable', 'string'],
            'nilai' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
        ]);

        $dup = UjianSidangPenguji::query()
            ->where('id_ujian_sidang', $ujianSidang->id)
            ->where('id_dosen', $validated['id_dosen'])
            ->exists();
        if ($dup) {
            return response()->json(['message' => 'Dosen ini sudah terdaftar sebagai penguji.'], 422);
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $row = UjianSidangPenguji::create([
            'id_ujian_sidang' => $ujianSidang->id,
            'id_dosen' => $validated['id_dosen'],
            'is_ketua' => $validated['is_ketua'] ?? false,
            'catatan' => $validated['catatan'] ?? null,
            'nilai' => $validated['nilai'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);
        $row->load('dosen');

        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function updatePengujiSidang(
        Request $request,
        TugasAkhir $tugasAkhir,
        UjianSidang $ujianSidang,
        UjianSidangPenguji $pengujiSidang
    ): JsonResponse {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        $this->assertUjianSidangForTugasAkhir($tugasAkhir, $ujianSidang);
        if ((int) $pengujiSidang->id_ujian_sidang !== (int) $ujianSidang->id) {
            abort(404);
        }

        $validated = $request->validate([
            'id_dosen' => ['sometimes', 'required', 'integer', 'exists:dosen,id'],
            'is_ketua' => ['sometimes', 'boolean'],
            'catatan' => ['nullable', 'string'],
            'nilai' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
        ]);

        if (isset($validated['id_dosen']) && (int) $validated['id_dosen'] !== (int) $pengujiSidang->id_dosen) {
            $dup = UjianSidangPenguji::query()
                ->where('id_ujian_sidang', $ujianSidang->id)
                ->where('id_dosen', $validated['id_dosen'])
                ->where('id', '!=', $pengujiSidang->id)
                ->exists();
            if ($dup) {
                return response()->json(['message' => 'Dosen ini sudah terdaftar sebagai penguji lain.'], 422);
            }
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $pengujiSidang->fill(array_merge(
            array_intersect_key($validated, array_flip(['id_dosen', 'is_ketua', 'catatan', 'nilai', 'status'])),
            ['updated_by' => $actor]
        ));
        $pengujiSidang->save();
        $pengujiSidang->load('dosen');

        return response()->json(['success' => true, 'data' => $pengujiSidang]);
    }

    public function destroyPengujiSidang(
        Request $request,
        TugasAkhir $tugasAkhir,
        UjianSidang $ujianSidang,
        UjianSidangPenguji $pengujiSidang
    ): JsonResponse {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        $this->assertUjianSidangForTugasAkhir($tugasAkhir, $ujianSidang);
        if ((int) $pengujiSidang->id_ujian_sidang !== (int) $ujianSidang->id) {
            abort(404);
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');
        $pengujiSidang->update(['deleted_by' => $actor, 'updated_by' => $actor]);
        $pengujiSidang->delete();

        return response()->json(['success' => true, 'message' => 'Penguji dihapus.']);
    }

    /**
     * Pratinjau finalisasi nilai ujian sidang: rata-rata penguji, pemetaan rentang nilai jenjang, KRS TA.
     */
    public function previewFinalisasiNilaiUjianSidang(
        Request $request,
        TugasAkhir $tugasAkhir,
        UjianSidang $ujianSidang
    ): JsonResponse {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        $this->assertUjianSidangForTugasAkhir($tugasAkhir, $ujianSidang);

        $resolved = $this->resolveFinalisasiNilaiUjianSidang($tugasAkhir, $ujianSidang);
        if (! $resolved['ok']) {
            $err = ['success' => false, 'message' => $resolved['message']];
            if (isset($resolved['extra']) && is_array($resolved['extra'])) {
                $err['data'] = $resolved['extra'];
            }

            return response()->json($err, 422);
        }

        return response()->json([
            'success' => true,
            'data' => $resolved['data'],
        ]);
    }

    /**
     * Simpan huruf mutu & angka mutu ke tabel nilai untuk KRS mata kuliah TA mahasiswa (berdasarkan rata-rata nilai penguji).
     */
    public function finalisasiNilaiUjianSidang(
        Request $request,
        TugasAkhir $tugasAkhir,
        UjianSidang $ujianSidang
    ): JsonResponse {
        $this->assertTugasAkhirProdiScope($request, $tugasAkhir);
        $this->assertUjianSidangForTugasAkhir($tugasAkhir, $ujianSidang);

        $resolved = $this->resolveFinalisasiNilaiUjianSidang($tugasAkhir, $ujianSidang);
        if (! $resolved['ok']) {
            $err = ['success' => false, 'message' => $resolved['message']];
            if (isset($resolved['extra']) && is_array($resolved['extra'])) {
                $err['data'] = $resolved['extra'];
            }

            return response()->json($err, 422);
        }

        return $this->commitFinalisasiNilaiToTranskrip($resolved['data']);
    }

    /**
     * Pratinjau finalisasi (ketua penguji): sama logika dengan admin, tanpa akses panel admin.
     */
    public function previewFinalisasiNilaiUjianSidangDosen(
        Request $request,
        UjianSidangPenguji $pengujiSidang
    ): JsonResponse {
        [$tugasAkhir, $ujianSidang] = $this->assertKetuaPengujiUntukFinalisasi($request, $pengujiSidang);

        $resolved = $this->resolveFinalisasiNilaiUjianSidang($tugasAkhir, $ujianSidang);
        if (! $resolved['ok']) {
            $err = ['success' => false, 'message' => $resolved['message']];
            if (isset($resolved['extra']) && is_array($resolved['extra'])) {
                $err['data'] = $resolved['extra'];
            }

            return response()->json($err, 422);
        }

        return response()->json([
            'success' => true,
            'data' => $resolved['data'],
        ]);
    }

    /**
     * Finalisasi nilai ke transkrip (ketua penguji).
     */
    public function finalisasiNilaiUjianSidangDosen(
        Request $request,
        UjianSidangPenguji $pengujiSidang
    ): JsonResponse {
        [$tugasAkhir, $ujianSidang] = $this->assertKetuaPengujiUntukFinalisasi($request, $pengujiSidang);

        $resolved = $this->resolveFinalisasiNilaiUjianSidang($tugasAkhir, $ujianSidang);
        if (! $resolved['ok']) {
            $err = ['success' => false, 'message' => $resolved['message']];
            if (isset($resolved['extra']) && is_array($resolved['extra'])) {
                $err['data'] = $resolved['extra'];
            }

            return response()->json($err, 422);
        }

        return $this->commitFinalisasiNilaiToTranskrip($resolved['data']);
    }

    /**
     * @return array{0: TugasAkhir, 1: UjianSidang}
     */
    private function assertKetuaPengujiUntukFinalisasi(Request $request, UjianSidangPenguji $pengujiSidang): array
    {
        $dosen = Dosen::where('id_user', $request->user()->id)->first();
        if (! $dosen) {
            abort(404, 'Data dosen tidak ditemukan.');
        }
        if ((int) $pengujiSidang->id_dosen !== (int) $dosen->id) {
            abort(403, 'Anda tidak memiliki akses ke data ini.');
        }
        if (! $pengujiSidang->is_ketua) {
            abort(403, 'Hanya ketua penguji yang dapat memfinalisasi nilai ke transkrip.');
        }

        $pengujiSidang->loadMissing(['ujianSidang']);
        $ujianSidang = $pengujiSidang->ujianSidang;
        if (! $ujianSidang) {
            abort(404, 'Ujian sidang tidak ditemukan.');
        }

        $tugasAkhir = TugasAkhir::query()->find($ujianSidang->id_tugas_akhir);
        if (! $tugasAkhir) {
            abort(404, 'Tugas akhir tidak ditemukan.');
        }

        $this->assertUjianSidangForTugasAkhir($tugasAkhir, $ujianSidang);

        return [$tugasAkhir, $ujianSidang];
    }

    /**
     * @param  array<string, mixed>  $payload  Isi dari resolveFinalisasiNilaiUjianSidang['data']
     */
    private function commitFinalisasiNilaiToTranskrip(array $payload): JsonResponse
    {
        $rentang = $payload['rentang'];
        $krsId = (int) $payload['krs']['id'];
        $sks = (int) $payload['sks'];

        DB::transaction(function () use ($krsId, $sks, $rentang): void {
            $nilai = Nilai::withTrashed()->where('id_krs', $krsId)->first();
            if ($nilai && $nilai->trashed()) {
                $nilai->restore();
            }

            Nilai::updateOrCreate(
                ['id_krs' => $krsId],
                [
                    'sks' => $sks > 0 ? $sks : null,
                    'angka_mutu' => $rentang['nilai_angka'],
                    'huruf_mutu' => $rentang['nilai_huruf'],
                    'is_final' => true,
                ]
            );
        });

        $nilaiRow = Nilai::where('id_krs', $krsId)->first();

        return response()->json([
            'success' => true,
            'message' => 'Nilai tugas akhir berhasil difinalisasi ke transkrip.',
            'data' => array_merge($payload, [
                'nilai_disimpan' => $nilaiRow ? [
                    'id' => $nilaiRow->id,
                    'id_krs' => $nilaiRow->id_krs,
                    'huruf_mutu' => $nilaiRow->huruf_mutu,
                    'angka_mutu' => $nilaiRow->angka_mutu !== null ? (float) $nilaiRow->angka_mutu : null,
                    'sks' => $nilaiRow->sks,
                    'is_final' => (bool) $nilaiRow->is_final,
                ] : null,
            ]),
        ]);
    }

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, message: string, extra?: array<string, mixed>}
     */
    private function resolveFinalisasiNilaiUjianSidang(TugasAkhir $tugasAkhir, UjianSidang $ujianSidang): array
    {
        $tugasAkhir->loadMissing(['mahasiswa.prodi.jenjang']);
        $ujianSidang->loadMissing(['penguji']);

        $penguji = $ujianSidang->penguji;
        if ($penguji->isEmpty()) {
            return ['ok' => false, 'message' => 'Belum ada dosen penguji.'];
        }

        $nilaiAngkaList = [];
        $pengujiTanpaNilai = [];
        foreach ($penguji as $p) {
            if ($p->nilai === null) {
                $pengujiTanpaNilai[] = $p->id;

                continue;
            }
            $nilaiAngkaList[] = (float) $p->nilai;
        }

        if ($pengujiTanpaNilai !== []) {
            return [
                'ok' => false,
                'message' => 'Semua dosen penguji harus mengisi nilai sebelum finalisasi.',
                'extra' => ['penguji_tanpa_nilai' => $pengujiTanpaNilai],
            ];
        }

        $rata = array_sum($nilaiAngkaList) / count($nilaiAngkaList);

        $jenjang = $tugasAkhir->mahasiswa?->prodi?->jenjang;
        if (! $jenjang) {
            return ['ok' => false, 'message' => 'Jenjang program studi mahasiswa tidak ditemukan.'];
        }

        $rentangNilaiList = RentangNilai::query()
            ->where('id_jenjang', $jenjang->id)
            ->whereNull('deleted_at')
            ->orderByDesc('nilai_tinggi')
            ->get();

        if ($rentangNilaiList->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'Rentang nilai untuk jenjang '.$jenjang->nama.' belum dikonfigurasi.',
            ];
        }

        $rentangTer = null;
        foreach ($rentangNilaiList as $rn) {
            if ($rata >= (float) $rn->nilai_rendah && $rata <= (float) $rn->nilai_tinggi) {
                $rentangTer = $rn;
                break;
            }
        }

        if (! $rentangTer) {
            return [
                'ok' => false,
                'message' => 'Rata-rata nilai tidak berada dalam rentang nilai yang dikonfigurasi untuk jenjang ini.',
                'extra' => [
                    'rata_rata' => round($rata, 2),
                    'id_jenjang' => $jenjang->id,
                ],
            ];
        }

        $idJenisTa = JenisMatkul::where('kode', self::JENIS_MATKUL_TA)->value('id');
        if (! $idJenisTa) {
            return ['ok' => false, 'message' => 'Jenis mata kuliah Tugas Akhir (kode TA) belum dikonfigurasi.'];
        }

        $krs = $this->findKrsTugasAkhirDisetujui(
            (int) $tugasAkhir->id_mahasiswa,
            (int) $tugasAkhir->id_semester,
            (int) $idJenisTa
        );
        if (! $krs) {
            return [
                'ok' => false,
                'message' => 'KRS mata kuliah Tugas Akhir (jenis TA) yang disetujui tidak ditemukan untuk semester tugas akhir ini.',
            ];
        }

        $krs->load(['kelas.kurikulumMatkul.matkul']);
        $km = $krs->kelas?->kurikulumMatkul;
        $sks = (int) ($km?->sks ?? $km?->matkul?->sks ?? 0);

        $nilaiEksisting = Nilai::where('id_krs', $krs->id)->first();

        return [
            'ok' => true,
            'data' => [
                'rata_rata' => round($rata, 2),
                'jumlah_penguji' => count($nilaiAngkaList),
                'rentang' => [
                    'nilai_huruf' => $rentangTer->nilai_huruf,
                    'nilai_angka' => (float) $rentangTer->nilai_angka,
                    'nilai_rendah' => (float) $rentangTer->nilai_rendah,
                    'nilai_tinggi' => (float) $rentangTer->nilai_tinggi,
                ],
                'jenjang' => [
                    'id' => $jenjang->id,
                    'nama' => $jenjang->nama,
                ],
                'krs' => ['id' => $krs->id],
                'sks' => $sks,
                'nilai_eksisting' => $nilaiEksisting ? [
                    'id' => $nilaiEksisting->id,
                    'huruf_mutu' => $nilaiEksisting->huruf_mutu,
                    'angka_mutu' => $nilaiEksisting->angka_mutu !== null ? (float) $nilaiEksisting->angka_mutu : null,
                    'is_final' => (bool) $nilaiEksisting->is_final,
                ] : null,
            ],
        ];
    }

    /** Kode jenis mata kuliah Tugas Akhir (lihat jenis_matkul, mis. TA). */
    private const JENIS_MATKUL_TA = 'TA';

    /**
     * Daftar tugas akhir milik mahasiswa login (filter opsional: status, id_semester).
     */
    public function listTugasAkhirMahasiswa(Request $request): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $query = TugasAkhir::query()
            ->with('semester')
            ->where('id_mahasiswa', $mahasiswa->id)
            ->orderByDesc('id');

        $status = $request->get('status');
        if ($status !== null && $status !== '' && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($request->filled('id_semester')) {
            $query->where('id_semester', (int) $request->get('id_semester'));
        }

        $rows = $query->get()
            ->map(fn (TugasAkhir $t) => $this->serializeTugasAkhirMahasiswa($t))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Daftar tugas akhir yang dibimbing dosen login (peran pembimbing), status judul disetujui.
     * Filter semester: default semester aktif; kirim semua=1 untuk semua semester.
     */
    public function listTugasAkhirBimbinganDosen(Request $request): JsonResponse
    {
        $dosen = Dosen::where('id_user', $request->user()->id)->first();
        if (! $dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan.'], 404);
        }

        $query = TugasAkhir::query()
            ->where('status', 'approved')
            ->with(['mahasiswa.prodi', 'semester'])
            ->whereHas('pembimbing', function ($q) use ($dosen) {
                $q->where('id_dosen', $dosen->id);
            })
            ->orderByDesc('updated_at');

        if ($request->boolean('semua')) {
            // tanpa filter semester
        } elseif ($request->filled('id_semester')) {
            $query->where('id_semester', (int) $request->get('id_semester'));
        } else {
            $aktif = Semester::query()->where('is_active', true)->orderByDesc('id')->first();
            if ($aktif) {
                $query->where('id_semester', $aktif->id);
            }
        }

        $rows = $query->get()
            ->map(fn (TugasAkhir $t) => $this->serializeTugasAkhirForDosenPembimbing($t))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Detail tugas akhir + riwayat bimbingan (tugas_akhir_bimbingan) untuk dosen pembimbing login.
     */
    public function showTugasAkhirDetailDosen(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $dosen = Dosen::where('id_user', $request->user()->id)->first();
        if (! $dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan.'], 404);
        }

        $isPembimbing = $tugasAkhir->pembimbing()
            ->where('id_dosen', $dosen->id)
            ->exists();

        if (! $isPembimbing) {
            return response()->json([
                'message' => 'Anda bukan pembimbing pada tugas akhir ini.',
            ], 403);
        }

        if ($tugasAkhir->status !== 'approved') {
            return response()->json([
                'message' => 'Tugas akhir tidak tersedia untuk tampilan pembimbing (judul belum disetujui).',
            ], 403);
        }

        $tugasAkhir->load(['mahasiswa.prodi', 'semester', 'pembimbing.dosen']);

        $payload = $this->serializeTugasAkhirMahasiswa($tugasAkhir);
        $m = $tugasAkhir->mahasiswa;
        $payload['mahasiswa'] = $m ? [
            'id' => $m->id,
            'nim' => $m->nim,
            'nama' => $m->nama,
            'prodi' => $m->relationLoaded('prodi') && $m->prodi
                ? [
                    'id' => $m->prodi->id,
                    'nama' => $m->prodi->nama,
                    'kode' => $m->prodi->kode ?? null,
                ]
                : null,
        ] : null;

        $riwayatBimbingan = TugasAkhirBimbingan::query()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->where('id_dosen', $dosen->id)
            ->orderByDesc('tanggal_bimbingan')
            ->orderByDesc('id')
            ->get()
            ->map(function (TugasAkhirBimbingan $b) {
                $file = $b->file;

                return [
                    'id' => $b->id,
                    'tanggal_bimbingan' => $b->tanggal_bimbingan?->format('Y-m-d'),
                    'catatan_dosen' => $b->catatan_dosen,
                    'catatan_mahasiswa' => $b->catatan_mahasiswa,
                    'file' => $file,
                    'file_url' => $file ? asset('storage/'.ltrim($file, '/')) : null,
                    'created_at' => $b->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $b->updated_at?->format('Y-m-d H:i:s'),
                    'created_by' => $b->created_by,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $payload,
            'riwayat_bimbingan' => $riwayatBimbingan,
        ]);
    }

    /**
     * Catat pertemuan bimbingan tugas akhir (dosen pembimbing).
     * Unik per (id_tugas_akhir, id_dosen, tanggal_bimbingan).
     */
    public function storeTugasAkhirBimbinganDosen(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $dosen = Dosen::where('id_user', $request->user()->id)->first();
        if (! $dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan.'], 404);
        }

        $isPembimbing = $tugasAkhir->pembimbing()
            ->where('id_dosen', $dosen->id)
            ->exists();

        if (! $isPembimbing) {
            return response()->json([
                'message' => 'Anda bukan pembimbing pada tugas akhir ini.',
            ], 403);
        }

        if ($tugasAkhir->status !== 'approved') {
            return response()->json([
                'message' => 'Judul tugas akhir belum disetujui; bimbingan tidak dapat dicatat.',
            ], 403);
        }

        $validated = $request->validate([
            'tanggal_bimbingan' => ['required', 'date'],
            'catatan_dosen' => ['nullable', 'string'],
            'catatan_mahasiswa' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);

        $tanggal = Carbon::parse($validated['tanggal_bimbingan'])->toDateString();

        $dup = TugasAkhirBimbingan::query()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->where('id_dosen', $dosen->id)
            ->whereDate('tanggal_bimbingan', $tanggal)
            ->exists();

        if ($dup) {
            return response()->json([
                'message' => 'Untuk tanggal ini sudah ada entri bimbingan. Ubah tanggal atau edit entri yang ada.',
            ], 422);
        }

        $pathFile = null;
        if ($request->hasFile('file')) {
            $pathFile = $request->file('file')->store('tugas-akhir-bimbingan', 'public');
        }

        $row = TugasAkhirBimbingan::create([
            'id_tugas_akhir' => $tugasAkhir->id,
            'id_dosen' => $dosen->id,
            'tanggal_bimbingan' => $tanggal,
            'catatan_dosen' => $validated['catatan_dosen'] ?? null,
            'catatan_mahasiswa' => $validated['catatan_mahasiswa'] ?? null,
            'file' => $pathFile,
            'created_by' => $request->user()->name,
        ]);

        $file = $row->file;

        return response()->json([
            'success' => true,
            'message' => 'Bimbingan berhasil dicatat.',
            'data' => [
                'id' => $row->id,
                'tanggal_bimbingan' => $row->tanggal_bimbingan?->format('Y-m-d'),
                'catatan_dosen' => $row->catatan_dosen,
                'catatan_mahasiswa' => $row->catatan_mahasiswa,
                'file' => $file,
                'file_url' => $file ? asset('storage/'.ltrim($file, '/')) : null,
                'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $row->updated_at?->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }

    /**
     * Daftar ujian sidang di mana dosen login tercatat sebagai penguji.
     * Filter semester mengacu ke semester ujian sidang (sama pola: semua / id_semester / default aktif).
     */
    public function listUjianSidangPengujiDosen(Request $request): JsonResponse
    {
        $dosen = Dosen::where('id_user', $request->user()->id)->first();
        if (! $dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan.'], 404);
        }

        $query = UjianSidangPenguji::query()
            ->where('id_dosen', $dosen->id)
            ->with([
                'ujianSidang.semester',
                'ujianSidang.tugasAkhir.mahasiswa.prodi',
            ]);

        if ($request->boolean('semua')) {
            // tanpa filter semester ujian sidang
        } elseif ($request->filled('id_semester')) {
            $semId = (int) $request->get('id_semester');
            $query->whereHas('ujianSidang', function ($q) use ($semId) {
                $q->where('id_semester', $semId);
            });
        } else {
            $aktif = Semester::query()->where('is_active', true)->orderByDesc('id')->first();
            if ($aktif) {
                $query->whereHas('ujianSidang', function ($q) use ($aktif) {
                    $q->where('id_semester', $aktif->id);
                });
            }
        }

        $rows = $query
            ->orderByDesc('id')
            ->get()
            ->map(fn (UjianSidangPenguji $p) => $this->serializeUjianSidangPengujiForDosen($p))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Detail penugasan penguji ujian sidang untuk dosen login.
     */
    public function showUjianSidangPengujiDosen(Request $request, UjianSidangPenguji $pengujiSidang): JsonResponse
    {
        $dosen = Dosen::where('id_user', $request->user()->id)->first();
        if (! $dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan.'], 404);
        }
        if ((int) $pengujiSidang->id_dosen !== (int) $dosen->id) {
            abort(403, 'Anda tidak memiliki akses ke data ini.');
        }

        $pengujiSidang->load([
            'ujianSidang.semester',
            'ujianSidang.tugasAkhir.mahasiswa.prodi',
            'ujianSidang.penguji.dosen',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeUjianSidangPengujiDetailForDosen($pengujiSidang),
        ]);
    }

    /**
     * Perbarui nilai / catatan / status penilaian penguji (hanya baris milik dosen login).
     */
    public function updateUjianSidangPengujiDosen(Request $request, UjianSidangPenguji $pengujiSidang): JsonResponse
    {
        $dosen = Dosen::where('id_user', $request->user()->id)->first();
        if (! $dosen) {
            return response()->json(['message' => 'Data dosen tidak ditemukan.'], 404);
        }
        if ((int) $pengujiSidang->id_dosen !== (int) $dosen->id) {
            abort(403, 'Anda tidak memiliki akses ke data ini.');
        }

        $validated = $request->validate([
            'nilai' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'catatan' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
        ]);

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $pengujiSidang->fill(array_merge(
            array_intersect_key($validated, array_flip(['nilai', 'catatan', 'status'])),
            ['updated_by' => $actor]
        ));
        $pengujiSidang->save();

        $pengujiSidang->load([
            'ujianSidang.semester',
            'ujianSidang.tugasAkhir.mahasiswa.prodi',
            'ujianSidang.penguji.dosen',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data penilaian diperbarui.',
            'data' => $this->serializeUjianSidangPengujiDetailForDosen($pengujiSidang),
        ]);
    }

    /**
     * Detail satu tugas akhir milik mahasiswa login (untuk halaman mahasiswa).
     */
    public function showTugasAkhirMahasiswa(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }
        if ((int) $tugasAkhir->id_mahasiswa !== (int) $mahasiswa->id) {
            abort(403, 'Anda tidak memiliki akses ke data tugas akhir ini.');
        }

        $tugasAkhir->load(['semester', 'pembimbing.dosen', 'statusLogs.user']);

        $payload = $this->serializeTugasAkhirMahasiswa($tugasAkhir);
        $payload['can_edit'] = in_array($tugasAkhir->status, ['draft', 'rejected', 'returned'], true);
        $payload['status_logs'] = $tugasAkhir->statusLogs->map(function (TugasAkhirStatusLog $log) {
            return [
                'id' => $log->id,
                'status' => $log->status,
                'keterangan' => $log->keterangan,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'user' => $log->relationLoaded('user') && $log->user
                    ? [
                        'name' => $log->user->name,
                        'email' => $log->user->email,
                    ]
                    : null,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * Konteks halaman pengajuan Tugas Akhir (mahasiswa login): KRS jenis TA di semester aktif, data pengajuan jika ada.
     */
    public function pengajuanContextMahasiswa(Request $request): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $semesterAktif = Semester::query()->where('is_active', true)->orderByDesc('id')->first();

        if (! $semesterAktif) {
            return response()->json([
                'success' => true,
                'data' => [
                    'eligible' => false,
                    'semester_aktif' => null,
                    'pesan_tidak_eligible' => 'Tidak ada semester aktif saat ini.',
                    'krs_ta' => null,
                    'tugas_akhir' => null,
                    'can_edit' => false,
                ],
            ]);
        }

        $idJenisTa = JenisMatkul::where('kode', self::JENIS_MATKUL_TA)->value('id');
        if (! $idJenisTa) {
            return response()->json([
                'success' => true,
                'data' => [
                    'eligible' => false,
                    'semester_aktif' => $this->serializeSemesterRingkas($semesterAktif),
                    'pesan_tidak_eligible' => 'Jenis mata kuliah Tugas Akhir (kode TA) belum dikonfigurasi di sistem.',
                    'krs_ta' => null,
                    'tugas_akhir' => null,
                    'can_edit' => false,
                ],
            ]);
        }

        $krsTa = $this->findKrsTugasAkhirDisetujui($mahasiswa->id, $semesterAktif->id, $idJenisTa);
        $eligible = $krsTa !== null;

        $pesanTidakEligible = $eligible
            ? null
            : 'Untuk mengajukan tugas akhir, Anda harus mengontrak mata kuliah dengan jenis Tugas Akhir (kode TA) pada KRS semester yang sedang aktif dan sudah disetujui.';

        $tugasAkhir = TugasAkhir::query()
            ->with(['semester', 'pembimbing.dosen'])
            ->where('id_mahasiswa', $mahasiswa->id)
            ->where('id_semester', $semesterAktif->id)
            ->first();

        $canEdit = $tugasAkhir && in_array($tugasAkhir->status, ['draft', 'rejected', 'returned'], true);

        return response()->json([
            'success' => true,
            'data' => [
                'eligible' => $eligible,
                'semester_aktif' => $this->serializeSemesterRingkas($semesterAktif),
                'pesan_tidak_eligible' => $pesanTidakEligible,
                'krs_ta' => $krsTa ? $this->serializeKrsTaRingkas($krsTa) : null,
                'tugas_akhir' => $tugasAkhir ? $this->serializeTugasAkhirMahasiswa($tugasAkhir) : null,
                'can_edit' => $canEdit,
            ],
        ]);
    }

    /**
     * Simpan pengajuan Tugas Akhir (semester mengikuti semester aktif).
     */
    public function storePengajuanMahasiswa(Request $request): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $semesterAktif = Semester::query()->where('is_active', true)->orderByDesc('id')->first();
        if (! $semesterAktif) {
            return response()->json(['message' => 'Tidak ada semester aktif.'], 422);
        }

        $idJenisTa = JenisMatkul::where('kode', self::JENIS_MATKUL_TA)->value('id');
        if (! $idJenisTa) {
            return response()->json(['message' => 'Jenis mata kuliah TA belum dikonfigurasi.'], 422);
        }

        $krsTa = $this->findKrsTugasAkhirDisetujui($mahasiswa->id, $semesterAktif->id, $idJenisTa);
        if (! $krsTa) {
            return response()->json([
                'message' => 'Anda belum mengontrak mata kuliah Tugas Akhir (jenis TA) pada semester aktif yang disetujui.',
            ], 422);
        }

        $existing = TugasAkhir::query()
            ->where('id_mahasiswa', $mahasiswa->id)
            ->where('id_semester', $semesterAktif->id)
            ->first();

        if ($existing) {
            if (in_array($existing->status, ['rejected', 'returned'], true)) {
                return response()->json([
                    'message' => 'Pengajuan ditolak atau dikembalikan sebelumnya. Gunakan perintah ubah (PUT) untuk mengajukan ulang.',
                ], 409);
            }

            return response()->json([
                'message' => 'Anda sudah memiliki pengajuan tugas akhir untuk semester ini.',
            ], 409);
        }

        $validated = $request->validate([
            'judul' => ['required', 'string', 'max:255'],
            'judul_en' => ['nullable', 'string', 'max:255'],
            'topik' => ['nullable', 'string', 'max:255'],
            'topik_en' => ['nullable', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'is_proposal' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:12288', 'mimes:pdf,doc,docx'],
        ]);

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $pathFile = null;
        if ($request->hasFile('file')) {
            $pathFile = $request->file('file')->store('tugas-akhir', 'public');
        }

        $row = TugasAkhir::create([
            'id_mahasiswa' => $mahasiswa->id,
            'id_semester' => $semesterAktif->id,
            'judul' => $validated['judul'],
            'judul_en' => $validated['judul_en'] ?? null,
            'topik' => $validated['topik'] ?? null,
            'topik_en' => $validated['topik_en'] ?? null,
            'deskripsi' => $validated['deskripsi'] ?? null,
            'is_proposal' => $request->boolean('is_proposal', true),
            'file' => $pathFile,
            'created_by' => $actor,
            'updated_by' => $actor,
            'status' => 'submitted',
        ]);

        $row->load(['semester', 'pembimbing.dosen']);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan tugas akhir berhasil dikirim.',
            'data' => $this->serializeTugasAkhirMahasiswa($row),
        ], 201);
    }

    /**
     * Perbarui pengajuan yang ditolak (mahasiswa).
     */
    public function updatePengajuanMahasiswa(Request $request): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $semesterAktif = Semester::query()->where('is_active', true)->orderByDesc('id')->first();
        if (! $semesterAktif) {
            return response()->json(['message' => 'Tidak ada semester aktif.'], 422);
        }

        $tugasAkhir = TugasAkhir::query()
            ->where('id_mahasiswa', $mahasiswa->id)
            ->where('id_semester', $semesterAktif->id)
            ->first();

        if (! $tugasAkhir) {
            return response()->json(['message' => 'Data pengajuan tidak ditemukan.'], 404);
        }

        if (! in_array($tugasAkhir->status, ['rejected', 'returned'], true)) {
            return response()->json([
                'message' => 'Pengajuan hanya dapat diubah jika status ditolak atau dikembalikan untuk perbaikan.',
            ], 422);
        }

        $validated = $request->validate([
            'judul' => ['required', 'string', 'max:255'],
            'judul_en' => ['nullable', 'string', 'max:255'],
            'topik' => ['nullable', 'string', 'max:255'],
            'topik_en' => ['nullable', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'is_proposal' => ['nullable', 'boolean'],
            'file' => ['nullable', 'file', 'max:12288', 'mimes:pdf,doc,docx'],
        ]);

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $pathFile = $tugasAkhir->file;
        if ($request->hasFile('file')) {
            if ($tugasAkhir->file) {
                Storage::disk('public')->delete($tugasAkhir->file);
            }
            $pathFile = $request->file('file')->store('tugas-akhir', 'public');
        }

        $tugasAkhir->update([
            'judul' => $validated['judul'],
            'judul_en' => $validated['judul_en'] ?? null,
            'topik' => $validated['topik'] ?? null,
            'topik_en' => $validated['topik_en'] ?? null,
            'deskripsi' => $validated['deskripsi'] ?? null,
            'is_proposal' => $request->has('is_proposal') ? $request->boolean('is_proposal') : $tugasAkhir->is_proposal,
            'file' => $pathFile,
            'updated_by' => $actor,
            'status' => 'submitted',
        ]);

        $tugasAkhir->load('semester');

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan telah diajukan ulang.',
            'data' => $this->serializeTugasAkhirMahasiswa($tugasAkhir->fresh(['semester', 'pembimbing.dosen'])),
        ]);
    }

    /**
     * Daftar tugas akhir berstatus disetujui milik mahasiswa (untuk memilih riwayat bimbingan per TA).
     */
    public function bimbinganIndexMahasiswa(Request $request): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $hasAnyTa = TugasAkhir::query()
            ->where('id_mahasiswa', $mahasiswa->id)
            ->exists();

        if (! $hasAnyTa) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_tugas_akhir' => false,
                    'tugas_akhir_disetujui' => [],
                    'pesan_belum_ajukan' => 'Anda belum memiliki data tugas akhir. Silakan mengajukan tugas akhir terlebih dahulu melalui menu Pengajuan Tugas Akhir.',
                    'pesan_tanpa_disetujui' => null,
                ],
            ]);
        }

        $tugasAkhirApproved = TugasAkhir::query()
            ->with('semester')
            ->where('id_mahasiswa', $mahasiswa->id)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'has_tugas_akhir' => true,
                'tugas_akhir_disetujui' => $tugasAkhirApproved->map(fn (TugasAkhir $t) => [
                    'id' => $t->id,
                    'judul' => $t->judul,
                    'status' => $t->status,
                    'topik' => $t->topik,
                    'semester' => $t->semester
                        ? $this->serializeSemesterRingkas($t->semester)
                        : null,
                    'bimbingan_count' => $t->bimbingan()->count(),
                ])->values()->all(),
                'pesan_belum_ajukan' => null,
                'pesan_tanpa_disetujui' => $tugasAkhirApproved->isEmpty()
                    ? 'Belum ada pengajuan tugas akhir yang berstatus disetujui. Setelah judul disetujui, tugas akhir akan tampil di sini dan Anda dapat melihat riwayat bimbingan.'
                    : null,
            ],
        ]);
    }

    /**
     * Detail tugas akhir (disetujui) + riwayat bimbingan untuk satu TA milik mahasiswa login.
     */
    public function bimbinganRiwayatByTugasAkhirMahasiswa(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        if ((int) $tugasAkhir->id_mahasiswa !== (int) $mahasiswa->id) {
            return response()->json(['message' => 'Tugas akhir tidak ditemukan.'], 404);
        }

        if ($tugasAkhir->status !== 'approved') {
            return response()->json([
                'message' => 'Riwayat bimbingan hanya tersedia untuk tugas akhir yang sudah disetujui.',
            ], 403);
        }

        $tugasAkhir->loadMissing(['semester', 'pembimbing.dosen']);

        $pembimbing = $tugasAkhir->pembimbing->map(function (TugasAkhirPembimbing $p) {
            $d = $p->dosen;

            return [
                'id' => $p->id,
                'id_dosen' => $p->id_dosen,
                'peran' => $p->peran,
                'dosen' => $d ? [
                    'id' => $d->id,
                    'nama' => $d->nama,
                    'kode_dosen' => $d->kode_dosen,
                    'nidn' => $d->nidn,
                ] : null,
            ];
        })->values()->all();

        $bimbingan = TugasAkhirBimbingan::query()
            ->with(['dosen', 'tugasAkhir.semester'])
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->orderByDesc('tanggal_bimbingan')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'tugas_akhir' => [
                    'id' => $tugasAkhir->id,
                    'judul' => $tugasAkhir->judul,
                    'status' => $tugasAkhir->status,
                    'semester' => $tugasAkhir->semester
                        ? $this->serializeSemesterRingkas($tugasAkhir->semester)
                        : null,
                ],
                'pembimbing' => $pembimbing,
                'bimbingan' => $bimbingan->map(fn (TugasAkhirBimbingan $b) => $this->serializeBimbinganMahasiswa($b))->values()->all(),
            ],
        ]);
    }

    /**
     * Mahasiswa menambah entri bimbingan (log pertemuan / catatan ke pembimbing).
     * created_by diisi nama mahasiswa.
     */
    public function storeBimbinganMahasiswa(Request $request, TugasAkhir $tugasAkhir): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        if ((int) $tugasAkhir->id_mahasiswa !== (int) $mahasiswa->id) {
            return response()->json(['message' => 'Tugas akhir tidak ditemukan.'], 404);
        }

        if ($tugasAkhir->status !== 'approved') {
            return response()->json([
                'message' => 'Entri bimbingan hanya dapat ditambahkan untuk tugas akhir yang sudah disetujui.',
            ], 403);
        }

        $validated = $request->validate([
            'tanggal_bimbingan' => ['required', 'date'],
            'id_dosen' => ['required', 'integer', Rule::exists('dosen', 'id')],
            'catatan_mahasiswa' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);

        $idDosen = (int) $validated['id_dosen'];
        $isPembimbingTa = $tugasAkhir->pembimbing()
            ->where('id_dosen', $idDosen)
            ->exists();

        if (! $isPembimbingTa) {
            return response()->json([
                'message' => 'Dosen yang dipilih bukan pembimbing pada tugas akhir ini.',
            ], 422);
        }

        $tanggal = Carbon::parse($validated['tanggal_bimbingan'])->toDateString();

        $dup = TugasAkhirBimbingan::query()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->where('id_dosen', $idDosen)
            ->whereDate('tanggal_bimbingan', $tanggal)
            ->exists();

        if ($dup) {
            return response()->json([
                'message' => 'Untuk tanggal dan pembimbing ini sudah ada entri bimbingan.',
            ], 422);
        }

        $pathFile = null;
        if ($request->hasFile('file')) {
            $pathFile = $request->file('file')->store('tugas-akhir-bimbingan', 'public');
        }

        $rawCatatan = $validated['catatan_mahasiswa'] ?? null;
        $catatanMhs = is_string($rawCatatan) && trim($rawCatatan) !== '' ? $rawCatatan : null;

        $createdBy = trim((string) ($mahasiswa->nama ?? '')) !== ''
            ? trim((string) $mahasiswa->nama)
            : (trim((string) ($request->user()->name ?? '')) !== ''
                ? trim((string) $request->user()->name)
                : (string) $request->user()->id);

        $row = TugasAkhirBimbingan::create([
            'id_tugas_akhir' => $tugasAkhir->id,
            'id_dosen' => $idDosen,
            'tanggal_bimbingan' => $tanggal,
            'catatan_dosen' => null,
            'catatan_mahasiswa' => $catatanMhs,
            'file' => $pathFile,
            'created_by' => $createdBy,
        ]);

        $row->load(['dosen', 'tugasAkhir.semester']);

        return response()->json([
            'success' => true,
            'message' => 'Entri bimbingan berhasil ditambahkan.',
            'data' => $this->serializeBimbinganMahasiswa($row),
        ], 201);
    }

    /**
     * Mahasiswa memperbarui catatan pada entri bimbingan tugas akhir miliknya.
     */
    public function updateBimbinganCatatanMahasiswa(Request $request, TugasAkhirBimbingan $bimbingan): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $bimbingan->loadMissing('tugasAkhir');
        if (! $bimbingan->tugasAkhir || (int) $bimbingan->tugasAkhir->id_mahasiswa !== (int) $mahasiswa->id) {
            return response()->json(['message' => 'Entri bimbingan tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'catatan_mahasiswa' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'max:10240'],
        ]);

        $raw = $validated['catatan_mahasiswa'] ?? null;
        $catatan = is_string($raw) ? (trim($raw) === '' ? null : $raw) : null;

        $pathFile = $bimbingan->file;
        if ($request->hasFile('file')) {
            if ($bimbingan->file) {
                Storage::disk('public')->delete($bimbingan->file);
            }
            $pathFile = $request->file('file')->store('tugas-akhir-bimbingan', 'public');
        }

        $bimbingan->update([
            'catatan_mahasiswa' => $catatan,
            'file' => $pathFile,
        ]);

        $bimbingan->load(['dosen', 'tugasAkhir.semester']);

        return response()->json([
            'success' => true,
            'message' => 'Catatan dan lampiran disimpan.',
            'data' => $this->serializeBimbinganMahasiswa($bimbingan->fresh(['dosen', 'tugasAkhir.semester'])),
        ]);
    }

    /**
     * Konteks halaman ujian sidang mahasiswa: data TA, daftar ujian sidang + penguji, opsi semester untuk pengajuan baru.
     */
    public function ujianSidangContextMahasiswa(Request $request): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $tugasAkhirTerbaru = TugasAkhir::query()
            ->with('semester')
            ->where('id_mahasiswa', $mahasiswa->id)
            ->orderByDesc('id')
            ->first();

        if (! $tugasAkhirTerbaru) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_tugas_akhir' => false,
                    'tugas_akhir' => null,
                    'tugas_akhir_approved' => [],
                    'eligible_pengajuan' => false,
                    'pesan_tidak_eligible' => 'Anda belum memiliki data tugas akhir. Ajukan judul tugas akhir terlebih dahulu.',
                    'ujian_sidang' => [],
                    'semester_untuk_pengajuan' => [],
                    'semester_untuk_pengajuan_per_ta' => [],
                ],
            ]);
        }

        $tugasAkhirApproved = TugasAkhir::query()
            ->with('semester')
            ->where('id_mahasiswa', $mahasiswa->id)
            ->where('status', 'approved')
            ->orderByDesc('id')
            ->get();

        $ujianSidang = UjianSidang::query()
            ->whereHas('tugasAkhir', fn ($q) => $q->where('id_mahasiswa', $mahasiswa->id))
            ->with(['semester', 'penguji.dosen', 'tugasAkhir'])
            ->orderByDesc('id')
            ->get();

        $semesterIdsUsedPerTa = $ujianSidang->groupBy('id_tugas_akhir')->map(
            fn ($rows) => $rows->pluck('id_semester')->unique()->all()
        );

        $allSemesters = Semester::query()
            ->orderByDesc('kode')
            ->select('id', 'kode', 'nama', 'is_active')
            ->get();

        $semesterPerTa = [];
        $unionSemesterIds = [];

        foreach ($tugasAkhirApproved as $ta) {
            $used = $semesterIdsUsedPerTa->get($ta->id, []);
            $available = $allSemesters
                ->filter(fn (Semester $s) => ! in_array($s->id, $used, true))
                ->values()
                ->map(fn (Semester $s) => $this->serializeSemesterRingkas($s))
                ->all();
            $semesterPerTa[(string) $ta->id] = $available;
            foreach ($available as $sem) {
                $unionSemesterIds[$sem['id']] = true;
            }
        }

        $eligible = $tugasAkhirApproved->isNotEmpty() && count($unionSemesterIds) > 0;

        $pesanTidakEligible = null;
        if ($tugasAkhirApproved->isEmpty()) {
            $pesanTidakEligible = 'Pengajuan ujian sidang hanya dapat dilakukan setelah judul tugas akhir Anda disetujui (status: disetujui).';
        } elseif (! $eligible) {
            $pesanTidakEligible = 'Semua semester yang tersedia sudah digunakan untuk pengajuan ujian sidang pada tugas akhir yang disetujui.';
        }

        $semesterUnion = $allSemesters
            ->filter(fn (Semester $s) => isset($unionSemesterIds[$s->id]))
            ->values()
            ->map(fn (Semester $s) => $this->serializeSemesterRingkas($s))
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'has_tugas_akhir' => true,
                'tugas_akhir' => [
                    'id' => $tugasAkhirTerbaru->id,
                    'judul' => $tugasAkhirTerbaru->judul,
                    'status' => $tugasAkhirTerbaru->status,
                    'semester' => $tugasAkhirTerbaru->semester
                        ? $this->serializeSemesterRingkas($tugasAkhirTerbaru->semester)
                        : null,
                ],
                'tugas_akhir_approved' => $tugasAkhirApproved->map(fn (TugasAkhir $t) => [
                    'id' => $t->id,
                    'judul' => $t->judul,
                    'semester' => $t->semester
                        ? $this->serializeSemesterRingkas($t->semester)
                        : null,
                ])->values()->all(),
                'eligible_pengajuan' => $eligible,
                'pesan_tidak_eligible' => $pesanTidakEligible,
                'ujian_sidang' => $ujianSidang
                    ->map(fn (UjianSidang $u) => $this->serializeUjianSidangMahasiswa($u))
                    ->values()
                    ->all(),
                'semester_untuk_pengajuan' => $semesterUnion,
                'semester_untuk_pengajuan_per_ta' => $semesterPerTa,
            ],
        ]);
    }

    /**
     * Detail satu ujian sidang milik mahasiswa login (untuk halaman mahasiswa).
     */
    public function showUjianSidangMahasiswa(Request $request, UjianSidang $ujianSidang): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $ujianSidang->load(['semester', 'penguji.dosen', 'tugasAkhir']);
        $ta = $ujianSidang->tugasAkhir;
        if (! $ta || (int) $ta->id_mahasiswa !== (int) $mahasiswa->id) {
            abort(403, 'Anda tidak memiliki akses ke data ujian sidang ini.');
        }

        $payload = $this->serializeUjianSidangMahasiswa($ujianSidang);
        $payload['tugas_akhir'] = [
            'id' => $ta->id,
            'judul' => $ta->judul,
            'status' => $ta->status,
        ];

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * Pengajuan ujian sidang baru oleh mahasiswa (satu entri per semester tugas akhir).
     */
    public function storeUjianSidangMahasiswa(Request $request): JsonResponse
    {
        $mahasiswa = Mahasiswa::where('id_user', $request->user()->id)->first();
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'id_tugas_akhir' => ['required', 'integer', 'exists:tugas_akhir,id'],
            'id_semester' => ['required', 'integer', 'exists:semester,id'],
            'file_laporan' => ['required', 'file', 'max:12288', 'mimes:pdf,doc,docx'],
        ]);

        $tugasAkhir = TugasAkhir::query()
            ->where('id_mahasiswa', $mahasiswa->id)
            ->where('id', $validated['id_tugas_akhir'])
            ->first();

        if (! $tugasAkhir) {
            return response()->json(['message' => 'Data tugas akhir tidak ditemukan atau bukan milik Anda.'], 422);
        }

        if ($tugasAkhir->status !== 'approved') {
            return response()->json([
                'message' => 'Judul tugas akhir harus disetujui terlebih dahulu sebelum mengajukan ujian sidang.',
            ], 422);
        }

        $exists = UjianSidang::query()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->where('id_semester', $validated['id_semester'])
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => 'Anda sudah memiliki pengajuan ujian sidang untuk semester ini pada tugas akhir tersebut.',
            ], 422);
        }

        $usedSemesterIds = UjianSidang::query()
            ->where('id_tugas_akhir', $tugasAkhir->id)
            ->pluck('id_semester')
            ->unique()
            ->all();

        $semesterAllowed = Semester::query()
            ->orderByDesc('kode')
            ->select('id', 'kode', 'nama', 'is_active')
            ->get()
            ->filter(fn (Semester $s) => ! in_array($s->id, $usedSemesterIds, true))
            ->pluck('id')
            ->all();

        if (! in_array((int) $validated['id_semester'], $semesterAllowed, true)) {
            return response()->json([
                'message' => 'Semester yang dipilih tidak tersedia untuk pengajuan pada tugas akhir ini.',
            ], 422);
        }

        $user = $request->user();
        $actor = trim((string) ($user->name ?? '')) !== '' ? trim((string) $user->name) : (string) ($user->email ?? '');

        $pathFile = $request->file('file_laporan')->store('ujian-sidang', 'public');

        $row = UjianSidang::create([
            'id_tugas_akhir' => $tugasAkhir->id,
            'id_semester' => $validated['id_semester'],
            'tanggal_daftar' => now(),
            'status' => 'submitted',
            'file_proposal' => $pathFile,
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);
        $row->load(['semester', 'penguji.dosen', 'tugasAkhir']);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan ujian sidang berhasil dikirim.',
            'data' => $this->serializeUjianSidangMahasiswa($row),
        ], 201);
    }

    private function serializeUjianSidangMahasiswa(UjianSidang $u): array
    {
        $penguji = [];
        if ($u->relationLoaded('penguji')) {
            $penguji = $u->penguji
                ->map(fn (UjianSidangPenguji $p) => $this->serializePengujiSidangMahasiswa($p))
                ->values()
                ->all();
        }

        $fileProposal = $u->file_proposal;
        $fileProposalUrl = $fileProposal ? asset('storage/'.ltrim($fileProposal, '/')) : null;

        $taJudul = null;
        if ($u->relationLoaded('tugasAkhir') && $u->tugasAkhir) {
            $taJudul = $u->tugasAkhir->judul;
        }

        return [
            'id' => $u->id,
            'id_tugas_akhir' => $u->id_tugas_akhir,
            'judul_tugas_akhir' => $taJudul,
            'status' => $u->status,
            'tanggal_daftar' => $u->tanggal_daftar?->format('Y-m-d H:i:s'),
            'tanggal_ujian_mulai' => $u->tanggal_ujian_mulai?->format('Y-m-d H:i:s'),
            'tanggal_ujian_selesai' => $u->tanggal_ujian_selesai?->format('Y-m-d H:i:s'),
            'semester' => $u->relationLoaded('semester') && $u->semester
                ? $this->serializeSemesterRingkas($u->semester)
                : null,
            'file_laporan' => $fileProposal,
            'file_laporan_url' => $fileProposalUrl,
            'penguji' => $penguji,
        ];
    }

    private function serializePengujiSidangMahasiswa(UjianSidangPenguji $p): array
    {
        $d = $p->relationLoaded('dosen') ? $p->dosen : null;

        return [
            'id' => $p->id,
            'is_ketua' => (bool) $p->is_ketua,
            'nilai' => $p->nilai !== null ? (string) $p->nilai : null,
            'status' => $p->status,
            'catatan' => $p->catatan,
            'dosen' => $d ? [
                'id' => $d->id,
                'nama' => $d->nama,
                'kode_dosen' => $d->kode_dosen,
                'nidn' => $d->nidn,
            ] : null,
        ];
    }

    private function serializeBimbinganMahasiswa(TugasAkhirBimbingan $b): array
    {
        $file = $b->file;
        $fileUrl = $file ? asset('storage/'.ltrim($file, '/')) : null;
        $dosen = $b->dosen;
        $ta = $b->tugasAkhir;

        return [
            'id' => $b->id,
            'id_tugas_akhir' => $b->id_tugas_akhir,
            'tanggal_bimbingan' => $b->tanggal_bimbingan?->format('Y-m-d'),
            'catatan_dosen' => $b->catatan_dosen,
            'catatan_mahasiswa' => $b->catatan_mahasiswa,
            'file' => $file,
            'file_url' => $fileUrl,
            'created_at' => $b->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $b->updated_at?->format('Y-m-d H:i:s'),
            'created_by' => $b->created_by,
            'dosen' => $dosen ? [
                'id' => $dosen->id,
                'nama' => $dosen->nama,
                'kode_dosen' => $dosen->kode_dosen,
                'nidn' => $dosen->nidn,
            ] : null,
            'tugas_akhir' => $ta ? [
                'id' => $ta->id,
                'judul' => $ta->judul,
                'semester' => $ta->relationLoaded('semester') && $ta->semester
                    ? $this->serializeSemesterRingkas($ta->semester)
                    : null,
            ] : null,
        ];
    }

    private function findKrsTugasAkhirDisetujui(int $idMahasiswa, int $idSemester, int $idJenisTa): ?Krs
    {
        return Krs::query()
            ->with([
                'kelas.kurikulumMatkul.matkul',
                'kelas.semester',
                'kelas.dosenPic',
            ])
            ->where('id_mahasiswa', $idMahasiswa)
            ->whereNull('deleted_at')
            ->whereNotNull('approved_at')
            ->whereHas('kelas', function ($q) use ($idSemester) {
                $q->where('id_semester', $idSemester);
            })
            ->whereHas('kelas.kurikulumMatkul.matkul', function ($q) use ($idJenisTa) {
                $q->where('id_jenis_matkul', $idJenisTa);
            })
            ->orderByDesc('approved_at')
            ->first();
    }

    private function serializeSemesterRingkas(Semester $s): array
    {
        return [
            'id' => $s->id,
            'kode' => $s->kode,
            'nama' => $s->nama,
            'is_active' => (bool) $s->is_active,
        ];
    }

    private function serializeKrsTaRingkas(Krs $krs): array
    {
        $matkul = $krs->kelas->kurikulumMatkul->matkul ?? null;
        $kelas = $krs->kelas;
        $dosen = $kelas->dosenPic ?? null;

        return [
            'id' => $krs->id,
            'matkul' => $matkul ? [
                'id' => $matkul->id,
                'kode' => $matkul->kode,
                'nama' => $matkul->nama,
                'sks' => $matkul->sks,
            ] : null,
            'kelas' => $kelas ? [
                'id' => $kelas->id,
                'nama' => $kelas->nama ?? $matkul?->nama,
            ] : null,
            'dosen' => $dosen ? [
                'id' => $dosen->id,
                'nama' => $dosen->nama,
            ] : null,
        ];
    }

    private function serializeTugasAkhirMahasiswa(TugasAkhir $t): array
    {
        $file = $t->file;
        $fileUrl = $file ? asset('storage/'.ltrim($file, '/')) : null;

        $pembimbing = [];
        if ($t->relationLoaded('pembimbing')) {
            $pembimbing = $t->pembimbing
                ->filter(fn (TugasAkhirPembimbing $p) => $p->peran === 'pembimbing')
                ->map(function (TugasAkhirPembimbing $p) {
                    $d = $p->relationLoaded('dosen') ? $p->dosen : null;

                    return [
                        'id' => $p->id,
                        'peran' => $p->peran,
                        'tanggal_penugasan' => $p->tanggal_penugasan?->format('Y-m-d'),
                        'dosen' => $d ? [
                            'id' => $d->id,
                            'nama' => $d->nama,
                            'kode_dosen' => $d->kode_dosen,
                            'nidn' => $d->nidn,
                        ] : null,
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'id' => $t->id,
            'id_mahasiswa' => $t->id_mahasiswa,
            'id_semester' => $t->id_semester,
            'judul' => $t->judul,
            'judul_en' => $t->judul_en,
            'topik' => $t->topik,
            'topik_en' => $t->topik_en,
            'deskripsi' => $t->deskripsi,
            'is_proposal' => (bool) $t->is_proposal,
            'file' => $file,
            'file_url' => $fileUrl,
            'status' => $t->status,
            'created_by' => $t->created_by,
            'updated_by' => $t->updated_by,
            'created_at' => $t->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $t->updated_at?->format('Y-m-d H:i:s'),
            'semester' => $t->relationLoaded('semester') && $t->semester
                ? $this->serializeSemesterRingkas($t->semester)
                : null,
            'pembimbing' => $pembimbing,
        ];
    }

    private function serializeTugasAkhirForDosenPembimbing(TugasAkhir $t): array
    {
        $m = $t->relationLoaded('mahasiswa') ? $t->mahasiswa : null;

        return [
            'id' => $t->id,
            'judul' => $t->judul,
            'status' => $t->status,
            'semester' => $t->relationLoaded('semester') && $t->semester
                ? $this->serializeSemesterRingkas($t->semester)
                : null,
            'mahasiswa' => $m ? [
                'id' => $m->id,
                'nim' => $m->nim,
                'nama' => $m->nama,
                'prodi' => $m->relationLoaded('prodi') && $m->prodi
                    ? [
                        'id' => $m->prodi->id,
                        'nama' => $m->prodi->nama,
                        'kode' => $m->prodi->kode ?? null,
                    ]
                    : null,
            ] : null,
        ];
    }

    private function serializeUjianSidangPengujiForDosen(UjianSidangPenguji $p): array
    {
        $u = $p->relationLoaded('ujianSidang') ? $p->ujianSidang : null;
        $ta = $u && $u->relationLoaded('tugasAkhir') ? $u->tugasAkhir : null;
        $m = $ta && $ta->relationLoaded('mahasiswa') ? $ta->mahasiswa : null;

        return [
            'id' => $p->id,
            'is_ketua' => (bool) $p->is_ketua,
            'status' => $p->status,
            'nilai' => $p->nilai !== null ? (string) $p->nilai : null,
            'catatan' => $p->catatan,
            'ujian_sidang' => $u ? [
                'id' => $u->id,
                'status' => $u->status,
                'tanggal_daftar' => $u->tanggal_daftar?->format('Y-m-d H:i:s'),
                'tanggal_ujian_mulai' => $u->tanggal_ujian_mulai?->format('Y-m-d H:i:s'),
                'tanggal_ujian_selesai' => $u->tanggal_ujian_selesai?->format('Y-m-d H:i:s'),
                'semester' => $u->relationLoaded('semester') && $u->semester
                    ? $this->serializeSemesterRingkas($u->semester)
                    : null,
            ] : null,
            'tugas_akhir' => $ta ? [
                'id' => $ta->id,
                'judul' => $ta->judul,
            ] : null,
            'mahasiswa' => $m ? [
                'id' => $m->id,
                'nim' => $m->nim,
                'nama' => $m->nama,
                'prodi' => $m->relationLoaded('prodi') && $m->prodi
                    ? [
                        'id' => $m->prodi->id,
                        'nama' => $m->prodi->nama,
                        'kode' => $m->prodi->kode ?? null,
                    ]
                    : null,
            ] : null,
        ];
    }

    private function serializeUjianSidangPengujiDetailForDosen(UjianSidangPenguji $p): array
    {
        $base = $this->serializeUjianSidangPengujiForDosen($p);
        $u = $p->relationLoaded('ujianSidang') ? $p->ujianSidang : null;
        $ta = $u && $u->relationLoaded('tugasAkhir') ? $u->tugasAkhir : null;

        if ($u !== null) {
            $file = $u->file_proposal;
            $base['ujian_sidang']['file_laporan'] = $file;
            $base['ujian_sidang']['file_laporan_url'] = $file ? asset('storage/'.ltrim($file, '/')) : null;
        }

        if ($ta !== null && isset($base['tugas_akhir'])) {
            $base['tugas_akhir']['deskripsi'] = $ta->deskripsi;
        }

        $pengujiLain = [];
        if ($u !== null && $u->relationLoaded('penguji')) {
            foreach ($u->penguji as $row) {
                if ((int) $row->id === (int) $p->id) {
                    continue;
                }
                $d = $row->relationLoaded('dosen') ? $row->dosen : null;
                $pengujiLain[] = [
                    'id' => $row->id,
                    'nama' => $d?->nama,
                    'kode_dosen' => $d?->kode_dosen,
                    'is_ketua' => (bool) $row->is_ketua,
                    'nilai' => $row->nilai !== null ? (string) $row->nilai : null,
                    'status' => $row->status,
                    'catatan' => $row->catatan,
                ];
            }
        }
        $base['penguji_lain'] = $pengujiLain;

        return $base;
    }
}
