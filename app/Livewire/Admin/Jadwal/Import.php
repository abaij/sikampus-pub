<?php

namespace App\Livewire\Admin\Jadwal;

use App\Models\Dosen;
use App\Models\Jadwal;
use App\Models\JadwalDosen;
use App\Models\JenisKuliah;
use App\Models\Kelas;
use App\Models\KelompokKelas;
use App\Models\KurikulumMatkul;
use App\Models\Ruangan;
use App\Models\Semester;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Import extends Component
{
    use WithFileUploads;

    public $file = null;

    public bool $processing = false;

    public ?array $result = null;

    protected function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ];
    }

    /**
     * Sama persis dengan JadwalController::import — hasil ditaruh di $result, bukan JsonResponse.
     * Kolom (A-L): kode semester, kode matkul, nama kelas mahasiswa, pertemuan ke-, tgl kuliah,
     * nama jenis kuliah, aktif, hari, jam mulai, jam selesai, nama ruangan, kode/NIDN dosen.
     *
     * Kode Semester Kelas dan Kode Mata Kuliah tetap wajib (dipakai mencari baris kelas), tapi
     * Pertemuan ke-, Tgl Kuliah, Jenis Kuliah, Hari, Jam, Ruangan, dan Dosen semuanya opsional:
     * kalau kosong (atau Pertemuan ke- di luar 1-99), kolomnya disimpan null, barisnya TETAP
     * diimport — tidak pernah di-skip hanya karena kosong.
     */
    public function import(): void
    {
        $this->result = null;
        $this->processing = true;
        $this->validate();

        $user = Auth::user();
        $allowedProdiIds = ($user && $user->hasScopeRestriction()) ? $user->getAllowedProdiIds() : null;

        try {
            $spreadsheet = IOFactory::load($this->file->getRealPath());
        } catch (\Throwable $e) {
            $this->processing = false;
            $this->addError('file', 'Gagal membaca file Excel. Pastikan format .xlsx/.xls valid. Detail: '.$e->getMessage());

            return;
        }

        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            $this->processing = false;
            $this->addError('file', 'File Excel kosong atau tidak valid.');
            $this->reset('file');

            return;
        }

        array_shift($rows);

        $errors = [];
        $successCount = 0;
        $skipCount = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

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
                    $dosenLookupFailed = false;
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
                            $dosenLookupFailed = true;

                            break;
                        }
                        if (! in_array((int) $dosen->id, $dosenIds, true)) {
                            $dosenIds[] = (int) $dosen->id;
                        }
                    }
                    if ($dosenLookupFailed) {
                        continue;
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

                $successCount++;
            }

            if (! empty($errors) && $successCount === 0) {
                DB::rollBack();

                $this->result = [
                    'success_count' => 0,
                    'skip_count' => $skipCount,
                    'errors' => $errors,
                ];
                $this->processing = false;

                return;
            }

            DB::commit();

            $this->result = [
                'success_count' => $successCount,
                'skip_count' => $skipCount,
                'errors' => $errors,
            ];
            $this->reset('file');
        } catch (\Exception $e) {
            DB::rollBack();

            $this->addError('file', 'Terjadi kesalahan saat mengimport data: '.$e->getMessage());
        }

        $this->processing = false;
    }

    public function render()
    {
        return view('livewire.admin.jadwal.import')->extends('layouts.web');
    }
}
