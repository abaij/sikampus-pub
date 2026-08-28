<?php

namespace App\Http\Controllers;

use App\Models\JenisKeringananBiaya;
use App\Models\KeringananBiaya;
use App\Models\Mahasiswa;
use App\Services\KeringananBiayaPersentaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class KeringananBiayaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');
        $status = $request->get('status');

        $query = KeringananBiaya::query()->with([
            'jenisKeringananBiaya:id,nama,is_persentase,nominal',
            'mahasiswa:id,nama,nim,id_prodi',
            'mahasiswa.prodi:id,nama',
            'semester:id,kode,nama',
            'aturanAksesKeuangan:id,kode_akses,nama',
        ]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('mahasiswa', fn ($q) => $q->whereIn('id_prodi', $allowedProdiIds));
            }
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('keterangan', 'like', "%{$search}%")
                    ->orWhereHas('mahasiswa', function ($mq) use ($search) {
                        $mq->where('nama', 'like', "%{$search}%")
                            ->orWhere('nim', 'like', "%{$search}%");
                    });
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $data = $query->orderByDesc('tanggal_pengajuan')->orderByDesc('id')->paginate($perPage);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->has('id_aturan_akses_keuangan') && $request->input('id_aturan_akses_keuangan') === '') {
            $request->merge(['id_aturan_akses_keuangan' => null]);
        }

        $validated = $this->validateStore($request);
        $data = Arr::except($validated, ['file_lampiran']);

        if ($this->duplicateTripleExists(
            (int) $data['id_jenis_keringanan_biaya'],
            (int) $data['id_mahasiswa'],
            (int) $data['id_semester'],
            null
        )) {
            return response()->json([
                'message' => 'Sudah ada keringanan untuk kombinasi jenis, mahasiswa, dan semester ini.',
            ], 422);
        }

        $path = $this->storeUploadedFile($request);

        $row = new KeringananBiaya($data);
        $row->tanggal_pengajuan = $data['tanggal_pengajuan'] ?? now();
        if ($path) {
            $row->file_lampiran = $path;
        }
        $gagalStatus = $this->applyStatusFields($request, $row, $data['status'] ?? 'pending');
        if ($gagalStatus !== null) {
            return response()->json([
                'message' => $gagalStatus,
                'errors' => ['status' => [$gagalStatus]],
            ], 422);
        }
        if ($request->user()) {
            $row->created_by = $request->user()->name ?? (string) $request->user()->id;
        }
        $row->save();

        $row->load([
            'jenisKeringananBiaya',
            'mahasiswa.prodi',
            'semester',
            'aturanAksesKeuangan',
        ]);

        return response()->json($row, 201);
    }

    public function show(KeringananBiaya $keringanan_biaya): JsonResponse
    {
        $keringanan_biaya->load([
            'jenisKeringananBiaya',
            'mahasiswa.prodi',
            'semester',
            'aturanAksesKeuangan',
        ]);

        return response()->json($keringanan_biaya);
    }

    public function update(Request $request, KeringananBiaya $keringanan_biaya): JsonResponse
    {
        if ($request->has('id_aturan_akses_keuangan') && $request->input('id_aturan_akses_keuangan') === '') {
            $request->merge(['id_aturan_akses_keuangan' => null]);
        }

        $validated = $this->validateUpdate($request);
        $data = Arr::except($validated, ['file_lampiran']);

        if ($this->duplicateTripleExists(
            (int) ($data['id_jenis_keringanan_biaya'] ?? $keringanan_biaya->id_jenis_keringanan_biaya),
            (int) ($data['id_mahasiswa'] ?? $keringanan_biaya->id_mahasiswa),
            (int) ($data['id_semester'] ?? $keringanan_biaya->id_semester),
            $keringanan_biaya->id
        )) {
            return response()->json([
                'message' => 'Sudah ada keringanan untuk kombinasi jenis, mahasiswa, dan semester ini.',
            ], 422);
        }

        $path = $this->storeUploadedFile($request);
        if ($path) {
            if ($keringanan_biaya->file_lampiran && Storage::disk('public')->exists($keringanan_biaya->file_lampiran)) {
                Storage::disk('public')->delete($keringanan_biaya->file_lampiran);
            }
            $data['file_lampiran'] = $path;
        }

        if ($request->user()) {
            $data['updated_by'] = $request->user()->name ?? (string) $request->user()->id;
        }

        $keringanan_biaya->fill($data);
        $status = $data['status'] ?? $keringanan_biaya->status;
        $gagalStatus = $this->applyStatusFields($request, $keringanan_biaya, $status);
        if ($gagalStatus !== null) {
            return response()->json([
                'message' => $gagalStatus,
                'errors' => ['status' => [$gagalStatus]],
            ], 422);
        }
        $keringanan_biaya->save();

        $keringanan_biaya->load([
            'jenisKeringananBiaya',
            'mahasiswa.prodi',
            'semester',
            'aturanAksesKeuangan',
        ]);

        return response()->json($keringanan_biaya);
    }

    public function destroy(Request $request, KeringananBiaya $keringanan_biaya): JsonResponse
    {
        if ($keringanan_biaya->file_lampiran && Storage::disk('public')->exists($keringanan_biaya->file_lampiran)) {
            Storage::disk('public')->delete($keringanan_biaya->file_lampiran);
        }

        if ($request->user()) {
            $keringanan_biaya->deleted_by = $request->user()->name ?? (string) $request->user()->id;
            $keringanan_biaya->save();
        }

        $keringanan_biaya->delete();

        return response()->json(['message' => 'Keringanan biaya dihapus']);
    }

    public function indexSaya(Request $request): JsonResponse
    {
        $mahasiswa = $this->mahasiswaForUser($request);
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $perPage = (int) $request->get('per_page', 10);

        $query = KeringananBiaya::query()
            ->where('id_mahasiswa', $mahasiswa->id)
            ->with([
                'jenisKeringananBiaya:id,nama,is_persentase,nominal',
                'semester:id,kode,nama',
                'aturanAksesKeuangan:id,kode_akses,nama',
            ]);

        $data = $query->orderByDesc('tanggal_pengajuan')->orderByDesc('id')->paginate($perPage);

        return response()->json($data);
    }

    public function showSaya(Request $request, int $id): JsonResponse
    {
        $mahasiswa = $this->mahasiswaForUser($request);
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        $row = KeringananBiaya::query()
            ->where('id', $id)
            ->where('id_mahasiswa', $mahasiswa->id)
            ->with([
                'jenisKeringananBiaya',
                'semester',
                'aturanAksesKeuangan',
            ])
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        return response()->json($row);
    }

    public function storeSaya(Request $request): JsonResponse
    {
        $mahasiswa = $this->mahasiswaForUser($request);
        if (! $mahasiswa) {
            return response()->json(['message' => 'Data mahasiswa tidak ditemukan'], 404);
        }

        if ($request->has('id_aturan_akses_keuangan') && $request->input('id_aturan_akses_keuangan') === '') {
            $request->merge(['id_aturan_akses_keuangan' => null]);
        }

        $validated = $request->validate([
            'id_jenis_keringanan_biaya' => ['required', 'integer', 'exists:jenis_keringanan_biaya,id'],
            'id_semester' => ['required', 'integer', 'exists:semester,id'],
            'id_aturan_akses_keuangan' => ['nullable', 'integer', 'exists:aturan_akses_keuangan,id'],
            'keterangan' => ['nullable', 'string'],
            'file_lampiran' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $data = Arr::except($validated, ['file_lampiran']);
        $data['id_mahasiswa'] = $mahasiswa->id;

        // Nominal TIDAK diambil dari request: mahasiswa tidak boleh menentukan besaran
        // keringanannya sendiri. Jenis persentase disimpan nominal 0 + snapshot persennya dan
        // baru dihitung saat approve; jenis rupiah memakai nominal master apa adanya.
        $jenis = JenisKeringananBiaya::where('is_active', true)
            ->find((int) $data['id_jenis_keringanan_biaya']);
        if (! $jenis) {
            return response()->json([
                'message' => 'Jenis keringanan tidak tersedia untuk pengajuan mandiri.',
                'errors' => ['id_jenis_keringanan_biaya' => ['Jenis keringanan tidak tersedia.']],
            ], 422);
        }

        $data['persentase'] = $jenis->is_persentase ? (float) $jenis->nominal : null;
        $data['nominal'] = $jenis->is_persentase ? 0 : (float) $jenis->nominal;

        if ($this->duplicateTripleExists(
            (int) $data['id_jenis_keringanan_biaya'],
            (int) $data['id_mahasiswa'],
            (int) $data['id_semester'],
            null
        )) {
            return response()->json([
                'message' => 'Sudah ada pengajuan keringanan untuk jenis dan semester ini.',
            ], 422);
        }

        $path = $this->storeUploadedFile($request);

        $row = new KeringananBiaya($data);
        $row->tanggal_pengajuan = now();
        if ($path) {
            $row->file_lampiran = $path;
        }
        $this->applyStatusFields($request, $row, 'pending'); // pending: tidak pernah gagal
        if ($request->user()) {
            $row->created_by = $request->user()->name ?? (string) $request->user()->id;
        }
        $row->save();

        $row->load([
            'jenisKeringananBiaya',
            'semester',
            'aturanAksesKeuangan',
        ]);

        return response()->json($row, 201);
    }

    private function mahasiswaForUser(Request $request): ?Mahasiswa
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        return Mahasiswa::query()->where('id_user', $user->id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateStore(Request $request): array
    {
        return $request->validate([
            'id_jenis_keringanan_biaya' => ['required', 'integer', 'exists:jenis_keringanan_biaya,id'],
            'id_mahasiswa' => ['required', 'integer', 'exists:mahasiswa,id'],
            'id_semester' => ['required', 'integer', 'exists:semester,id'],
            'id_aturan_akses_keuangan' => ['nullable', 'integer', 'exists:aturan_akses_keuangan,id'],
            'nominal' => ['required', 'numeric', 'min:0'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'tanggal_pengajuan' => ['nullable', 'date'],
            'file_lampiran' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUpdate(Request $request): array
    {
        return $request->validate([
            'id_jenis_keringanan_biaya' => ['sometimes', 'required', 'integer', 'exists:jenis_keringanan_biaya,id'],
            'id_mahasiswa' => ['sometimes', 'required', 'integer', 'exists:mahasiswa,id'],
            'id_semester' => ['sometimes', 'required', 'integer', 'exists:semester,id'],
            'id_aturan_akses_keuangan' => ['nullable', 'integer', 'exists:aturan_akses_keuangan,id'],
            'nominal' => ['sometimes', 'required', 'numeric', 'min:0'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'rejected'])],
            'tanggal_pengajuan' => ['nullable', 'date'],
            'file_lampiran' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
    }

    private function storeUploadedFile(Request $request): ?string
    {
        if (! $request->hasFile('file_lampiran')) {
            return null;
        }

        $file = $request->file('file_lampiran');

        return $file->store('keringanan-biaya', 'public');
    }

    private function duplicateTripleExists(int $jenisId, int $mhsId, int $semId, ?int $ignoreId): bool
    {
        $q = KeringananBiaya::query()
            ->where('id_jenis_keringanan_biaya', $jenisId)
            ->where('id_mahasiswa', $mhsId)
            ->where('id_semester', $semId);
        if ($ignoreId !== null) {
            $q->where('id', '!=', $ignoreId);
        }

        return $q->exists();
    }

    /**
     * Sama persis dengan App\Livewire\Admin\KeringananBiaya\Form::applyStatusFields.
     *
     * Approve adalah titik di mana keringanan persentase berubah jadi rupiah — nominal-nya
     * dihitung dari total tagihan semester lalu di-snapshot, karena hanya rupiah yang dimengerti
     * KeringananBiayaKreditService.
     *
     * @return string|null pesan kegagalan, null kalau berhasil
     */
    private function applyStatusFields(Request $request, KeringananBiaya $model, string $status): ?string
    {
        $model->status = $status;
        if ($status === 'approved') {
            $gagal = KeringananBiayaPersentaseService::terapkanSaatApprove($model);
            if ($gagal !== null) {
                return $gagal;
            }
            $model->tanggal_approved = now();
            $model->approved_by = $request->user()->name ?? (string) ($request->user()->id ?? '');
        } else {
            $model->tanggal_approved = null;
            $model->approved_by = null;
        }

        return null;
    }
}
