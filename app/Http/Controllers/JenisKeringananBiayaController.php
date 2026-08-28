<?php

namespace App\Http\Controllers;

use App\Models\JenisKeringananBiaya;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JenisKeringananBiayaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->get('per_page', 10);
        $search = $request->get('search');

        $query = JenisKeringananBiaya::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('keterangan', 'like', "%{$search}%");
            });
        }

        $data = $query->orderBy('nama')->paginate($perPage);

        return response()->json($data);
    }

    public function indexAktifForMahasiswa(): JsonResponse
    {
        // Tidak lagi disaring `nominal = 0`: persentase kini diselesaikan jadi rupiah saat
        // approve (KeringananBiayaPersentaseService), bukan disalin mentah saat pengajuan.
        $data = JenisKeringananBiaya::query()
            ->where('is_active', true)
            ->orderBy('nama')
            ->get(['id', 'nama', 'is_persentase', 'nominal', 'keterangan']);

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $isPersen = $request->boolean('is_persentase');
        $nominalRules = ['required', 'numeric', 'min:0'];
        if ($isPersen) {
            $nominalRules[] = 'max:100';
        }

        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'is_persentase' => ['nullable', 'boolean'],
            'nominal' => $nominalRules,
            'is_active' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string'],
        ]);

        if (! isset($validated['is_persentase'])) {
            $validated['is_persentase'] = false;
        }
        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        if ($request->user()) {
            $validated['created_by'] = $request->user()->name ?? (string) $request->user()->id;
        }

        $row = JenisKeringananBiaya::create($validated);

        return response()->json($row, 201);
    }

    public function show(JenisKeringananBiaya $jenis_keringanan_biaya): JsonResponse
    {
        return response()->json($jenis_keringanan_biaya);
    }

    public function update(Request $request, JenisKeringananBiaya $jenis_keringanan_biaya): JsonResponse
    {
        $effectivePersen = $request->has('is_persentase')
            ? $request->boolean('is_persentase')
            : $jenis_keringanan_biaya->is_persentase;
        $nominalRules = ['sometimes', 'required', 'numeric', 'min:0'];
        if ($effectivePersen) {
            $nominalRules[] = 'max:100';
        }

        $validated = $request->validate([
            'nama' => ['sometimes', 'required', 'string', 'max:255'],
            'is_persentase' => ['nullable', 'boolean'],
            'nominal' => $nominalRules,
            'is_active' => ['nullable', 'boolean'],
            'keterangan' => ['nullable', 'string'],
        ]);

        if ($request->user()) {
            $validated['updated_by'] = $request->user()->name ?? (string) $request->user()->id;
        }

        $jenis_keringanan_biaya->update($validated);

        return response()->json($jenis_keringanan_biaya);
    }

    public function destroy(Request $request, JenisKeringananBiaya $jenis_keringanan_biaya): JsonResponse
    {
        if ($request->user()) {
            $jenis_keringanan_biaya->deleted_by = $request->user()->name ?? (string) $request->user()->id;
            $jenis_keringanan_biaya->save();
        }

        $jenis_keringanan_biaya->delete();

        return response()->json(['message' => 'Jenis keringanan biaya dihapus']);
    }
}
