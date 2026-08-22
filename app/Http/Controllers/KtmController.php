<?php

namespace App\Http\Controllers;

use App\Models\Ktm;
use App\Models\Mahasiswa;
use App\Models\Setting;
use App\Services\KtmImageGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

class KtmController extends Controller
{
    public const SETTING_KEY_KTM_TEMPLATE = 'ktm_template';

    public function getSettingTemplate(): JsonResponse
    {
        $s = Setting::query()->where('key', self::SETTING_KEY_KTM_TEMPLATE)->first();
        $path = $s?->value;
        $fileUrl = $path ? Storage::disk('public')->url($path) : null;

        $tw = (int) config('ktm.template_width', 800);
        $th = (int) config('ktm.template_height', 457);

        return response()->json([
            'key' => self::SETTING_KEY_KTM_TEMPLATE,
            'value' => $path,
            'file_url' => $fileUrl,
            'template_width' => $tw,
            'template_height' => $th,
        ]);
    }

    public function storeSettingTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'image', 'max:5120', 'mimes:jpeg,jpg,png,gif,webp'],
        ], [
            'file.required' => 'Pilih file gambar terlebih dahulu.',
            'file.image' => 'File harus berupa gambar.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ]);

        $row = Setting::withTrashed()->where('key', self::SETTING_KEY_KTM_TEMPLATE)->first();
        if ($row?->trashed()) {
            $row->restore();
        }

        if ($row && $row->value) {
            Storage::disk('public')->delete($row->value);
        }

        $tw = (int) config('ktm.template_width', 800);
        $th = (int) config('ktm.template_height', 457);
        if ($tw < 1 || $th < 1) {
            return response()->json(['message' => 'Konfigurasi ukuran template KTM tidak valid.'], 500);
        }

        $uploaded = $request->file('file');
        try {
            $image = ImageManager::gd()->read($uploaded->getRealPath() ?: $uploaded->getPathname());
            $image->cover($tw, $th, 'center');
        } catch (Throwable) {
            return response()->json([
                'message' => 'Gagal memproses gambar. Pastikan berkas gambar valid.',
            ], 422);
        }

        $path = 'ktm/templates/tpl_'.Str::uuid()->toString().'.png';
        $absolute = Storage::disk('public')->path($path);
        $dir = dirname($absolute);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return response()->json(['message' => 'Tidak dapat menyimpan template.'], 500);
        }

        try {
            $image->save($absolute);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Gagal menyimpan gambar template.',
            ], 500);
        }

        if ($row) {
            $row->update([
                'value' => $path,
            ]);
        } else {
            $row = Setting::create([
                'key' => self::SETTING_KEY_KTM_TEMPLATE,
                'value' => $path,
                'description' => 'Template gambar KTM (admin)',
                'order' => 0,
            ]);
        }

        $row->refresh();

        return response()->json([
            'key' => self::SETTING_KEY_KTM_TEMPLATE,
            'value' => $row->value,
            'file_url' => Storage::disk('public')->url($row->value),
            'template_width' => $tw,
            'template_height' => $th,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 15);
        $search = $request->get('search');
        $status = $request->get('status');

        $query = Ktm::query()
            ->with(['mahasiswa' => function ($q) {
                $q->select('id', 'nim', 'nama', 'id_prodi')
                    ->with(['prodi:id,nama,kode']);
            }]);

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereHas('mahasiswa', function ($q) use ($allowedProdiIds) {
                    $q->whereIn('id_prodi', $allowedProdiIds);
                });
            }
        }

        if ($search && trim((string) $search) !== '') {
            $s = trim((string) $search);
            $query->whereHas('mahasiswa', function ($q) use ($s) {
                $q->where('nama', 'like', "%{$s}%")
                    ->orWhere('nim', 'like', "%{$s}%");
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('ktm.status', $status);
        }

        $data = $query->orderByDesc('ktm.id')->paginate($perPage);

        $data->getCollection()->transform(function (Ktm $k) {
            return $this->toApiRow($k);
        });

        return response()->json($data);
    }

    public function show(Request $request, Ktm $ktm): JsonResponse
    {
        $this->assertKtmMhsVisible($request, $ktm);

        $ktm->load(['mahasiswa' => function ($q) {
            $q->select('id', 'nim', 'nama', 'id_prodi')
                ->with(['prodi:id,nama,kode']);
        }]);

        return response()->json($this->toApiRow($ktm));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_mahasiswa' => [
                'required',
                'integer',
                Rule::exists('mahasiswa', 'id')->whereNull('deleted_at'),
                Rule::unique('ktm', 'id_mahasiswa')->whereNull('deleted_at'),
            ],
            'nomor_ktm' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ], [
            'id_mahasiswa.unique' => 'Mahasiswa ini sudah memiliki data KTM.',
        ]);

        $this->assertMahasiswaInScope($request, (int) $validated['id_mahasiswa']);

        $templateSetting = Setting::query()->where('key', self::SETTING_KEY_KTM_TEMPLATE)->first();
        $templatePath = $templateSetting?->value;
        if (! $templatePath || ! Storage::disk('public')->exists($templatePath)) {
            return response()->json([
                'message' => 'Template KTM belum diatur. Unggah template gambar di admin KTM (tab Template) terlebih dahulu.',
            ], 422);
        }

        $mhs = Mahasiswa::query()
            ->whereKey((int) $validated['id_mahasiswa'])
            ->with(['prodi.jenjang'])
            ->firstOrFail();

        try {
            $filePath = KtmImageGenerator::makeDefault()->generateToStorage($mhs, $templatePath);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $actor = $request->user() ? ((string) ($request->user()->name ?? $request->user()->id)) : 'system';

        $row = Ktm::create([
            'id_mahasiswa' => (int) $validated['id_mahasiswa'],
            'nomor_ktm' => $validated['nomor_ktm'] ?? null,
            'file' => $filePath,
            'status' => $validated['status'] ?? 'active',
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        $row->load(['mahasiswa' => function ($q) {
            $q->select('id', 'nim', 'nama', 'id_prodi')
                ->with(['prodi:id,nama,kode']);
        }]);

        return response()->json($this->toApiRow($row), 201);
    }

    public function update(Request $request, Ktm $ktm): JsonResponse
    {
        $this->assertKtmMhsVisible($request, $ktm);

        $validated = $request->validate([
            'nomor_ktm' => ['nullable', 'string', 'max:100'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpeg,jpg,png,webp'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $actor = $request->user() ? ((string) ($request->user()->name ?? $request->user()->id)) : 'system';
        $update = [
            'updated_by' => $actor,
        ];
        if (array_key_exists('nomor_ktm', $validated)) {
            $n = $validated['nomor_ktm'];
            $update['nomor_ktm'] = ($n === null || $n === '') ? null : $n;
        }
        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $update['status'] = $validated['status'];
        }

        if ($request->hasFile('file')) {
            if ($ktm->file) {
                Storage::disk('public')->delete($ktm->file);
            }
            $update['file'] = $request->file('file')->store('ktm', 'public');
        }

        $ktm->update($update);

        $ktm->load(['mahasiswa' => function ($q) {
            $q->select('id', 'nim', 'nama', 'id_prodi')
                ->with(['prodi:id,nama,kode']);
        }]);

        return response()->json($this->toApiRow($ktm->fresh()));
    }

    /**
     * Buat ulang file gambar KTM dari template & data mahasiswa saat ini.
     */
    public function regenerate(Request $request, Ktm $ktm): JsonResponse
    {
        $this->assertKtmMhsVisible($request, $ktm);

        $templateSetting = Setting::query()->where('key', self::SETTING_KEY_KTM_TEMPLATE)->first();
        $templatePath = $templateSetting?->value;
        if (! $templatePath || ! Storage::disk('public')->exists($templatePath)) {
            return response()->json([
                'message' => 'Template KTM belum diatur. Unggah template gambar di admin KTM (tab Template) terlebih dahulu.',
            ], 422);
        }

        $mhs = Mahasiswa::query()
            ->whereKey((int) $ktm->id_mahasiswa)
            ->with(['prodi.jenjang'])
            ->firstOrFail();

        $previousFile = $ktm->file;

        try {
            $filePath = KtmImageGenerator::makeDefault()->generateToStorage($mhs, $templatePath);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($previousFile && $previousFile !== $filePath) {
            Storage::disk('public')->delete($previousFile);
        }

        $actor = $request->user() ? ((string) ($request->user()->name ?? $request->user()->id)) : 'system';
        $ktm->update([
            'file' => $filePath,
            'updated_by' => $actor,
        ]);

        $ktm->load(['mahasiswa' => function ($q) {
            $q->select('id', 'nim', 'nama', 'id_prodi')
                ->with(['prodi:id,nama,kode']);
        }]);

        return response()->json($this->toApiRow($ktm->fresh()));
    }

    public function destroy(Request $request, Ktm $ktm): JsonResponse
    {
        $this->assertKtmMhsVisible($request, $ktm);
        $actor = $request->user() ? ((string) ($request->user()->name ?? $request->user()->id)) : 'system';
        if ($ktm->file) {
            Storage::disk('public')->delete($ktm->file);
        }
        $ktm->update(['deleted_by' => $actor]);
        $ktm->delete();

        return response()->json(['message' => 'Data KTM dihapus.']);
    }

    /**
     * Pencarian mahasiswa — untuk form tambah: only_without_ktm=1 hanya yang belum punya KTM aktif.
     */
    /**
     * KTM untuk mahasiswa yang sedang login.
     */
    public function myShow(Request $request): JsonResponse
    {
        $mhs = $this->mahasiswaForAuthedUser($request);
        if (! $mhs) {
            return response()->json(['message' => 'Hanya akun mahasiswa yang dapat mengakses.'], 403);
        }

        $ktm = Ktm::query()
            ->where('id_mahasiswa', $mhs->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $ktm) {
            return response()->json(['ktm' => null]);
        }

        $ktm->load(['mahasiswa' => function ($q) {
            $q->select('id', 'nim', 'nama', 'id_prodi')
                ->with(['prodi:id,nama,kode']);
        }]);

        return response()->json(['ktm' => $this->toApiRow($ktm)]);
    }

    /**
     * Buat KTM untuk mahasiswa login (hanya jika belum punya KTM).
     */
    public function myStore(Request $request): JsonResponse
    {
        $mhs = $this->mahasiswaForAuthedUser($request);
        if (! $mhs) {
            return response()->json(['message' => 'Hanya akun mahasiswa yang dapat membuat KTM.'], 403);
        }

        $exists = Ktm::query()
            ->where('id_mahasiswa', $mhs->id)
            ->whereNull('deleted_at')
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'KTM sudah tersedia. Gunakan perbarui bila perlu.'], 422);
        }

        $mhs = Mahasiswa::query()
            ->whereKey($mhs->id)
            ->with(['prodi.jenjang'])
            ->firstOrFail();

        $templateSetting = Setting::query()->where('key', self::SETTING_KEY_KTM_TEMPLATE)->first();
        $templatePath = $templateSetting?->value;
        if (! $templatePath || ! Storage::disk('public')->exists($templatePath)) {
            return response()->json([
                'message' => 'Template KTM belum diatur oleh admin. Silakan hubungi pihak kampus.',
            ], 422);
        }

        try {
            $filePath = KtmImageGenerator::makeDefault()->generateToStorage($mhs, $templatePath);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $actor = $request->user() ? ((string) ($request->user()->name ?? $request->user()->id)) : 'system';

        $row = Ktm::create([
            'id_mahasiswa' => (int) $mhs->id,
            // Nomor KTM sengaja tidak diterima dari mahasiswa — petugas yang mengisinya lewat
            // endpoint admin (store/update) atau Admin > KTM di panel.
            'nomor_ktm' => null,
            'file' => $filePath,
            'status' => 'active',
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        $row->load(['mahasiswa' => function ($q) {
            $q->select('id', 'nim', 'nama', 'id_prodi')
                ->with(['prodi:id,nama,kode']);
        }]);

        return response()->json($this->toApiRow($row), 201);
    }

    /**
     * Perbarui file KTM (sama seperti regenerate admin) untuk mahasiswa login.
     */
    public function myRegenerate(Request $request): JsonResponse
    {
        $mhs = $this->mahasiswaForAuthedUser($request);
        if (! $mhs) {
            return response()->json(['message' => 'Hanya akun mahasiswa yang dapat memperbarui KTM.'], 403);
        }

        $ktm = Ktm::query()
            ->where('id_mahasiswa', $mhs->id)
            ->whereNull('deleted_at')
            ->first();
        if (! $ktm) {
            return response()->json(['message' => 'KTM belum ada. Buat KTM terlebih dahulu.'], 404);
        }

        return $this->regenerate($request, $ktm);
    }

    public function getMahasiswaOptions(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 50);
        $search = $request->get('search');
        $onlyWithoutKtm = $request->boolean('only_without_ktm');

        $idsWithKtm = Ktm::query()->whereNull('deleted_at')->pluck('id_mahasiswa');

        $query = Mahasiswa::query()
            ->select('id', 'nim', 'nama', 'id_prodi')
            ->whereNull('deleted_at')
            ->with(['prodi:id,nama,kode']);

        if ($onlyWithoutKtm && $idsWithKtm->isNotEmpty()) {
            $query->whereNotIn('id', $idsWithKtm);
        }

        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowedProdiIds = $user->getAllowedProdiIds();
            if ($allowedProdiIds !== null) {
                $query->whereIn('id_prodi', $allowedProdiIds);
            }
        }

        if ($search && trim((string) $search) !== '') {
            $s = trim((string) $search);
            $query->where(function ($q) use ($s) {
                $q->where('nama', 'like', "%{$s}%")
                    ->orWhere('nim', 'like', "%{$s}%");
            });
        }

        $data = $query->orderBy('nim')->paginate($perPage);

        return response()->json($data);
    }

    private function toApiRow(Ktm $k): array
    {
        $k->loadMissing(['mahasiswa.prodi']);
        $m = $k->mahasiswa;

        return [
            'id' => $k->id,
            'id_mahasiswa' => $k->id_mahasiswa,
            'nomor_ktm' => $k->nomor_ktm,
            'file' => $k->file,
            'file_url' => $k->file ? Storage::disk('public')->url($k->file) : null,
            'status' => $k->status,
            'mahasiswa' => $m ? [
                'id' => $m->id,
                'nim' => $m->nim,
                'nama' => $m->nama,
                'prodi' => $m->prodi ? [
                    'id' => $m->prodi->id,
                    'nama' => $m->prodi->nama,
                    'kode' => $m->prodi->kode,
                ] : null,
            ] : null,
        ];
    }

    private function assertKtmMhsVisible(Request $request, Ktm $ktm): void
    {
        $ktm->loadMissing('mahasiswa');
        $m = $ktm->mahasiswa;
        if (! $m) {
            abort(404, 'Data tidak ditemukan.');
        }
        $this->assertMahasiswaInScope($request, (int) $m->id);
    }

    private function assertMahasiswaInScope(Request $request, int $idMahasiswa): void
    {
        $m = Mahasiswa::query()->whereNull('deleted_at')->find($idMahasiswa);
        if (! $m) {
            abort(404, 'Mahasiswa tidak ditemukan.');
        }
        $user = $request->user();
        if ($user && $user->hasScopeRestriction()) {
            $allowed = $user->getAllowedProdiIds();
            if ($allowed !== null && ! in_array((int) $m->id_prodi, $allowed, true)) {
                abort(403, 'Anda tidak memiliki akses ke data ini.');
            }
        }
    }

    private function mahasiswaForAuthedUser(Request $request): ?Mahasiswa
    {
        $user = $request->user();
        if (! $user || (string) $user->getAttribute('role') !== 'mahasiswa') {
            return null;
        }

        return Mahasiswa::query()
            ->where('id_user', (int) $user->id)
            ->whereNull('deleted_at')
            ->first();
    }
}
