<?php

namespace App\Livewire\Admin\Mahasiswa;

use App\Models\JalurMasuk;
use App\Models\JenisDaftar;
use App\Models\KelompokKelas;
use App\Models\Kota;
use App\Models\Mahasiswa;
use App\Models\Negara;
use App\Models\Pekerjaan;
use App\Models\Pendidikan;
use App\Models\Penghasilan;
use App\Models\Prodi;
use App\Models\Provinsi;
use App\Models\Semester;
use App\Models\StatusAkademik;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

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
     * Sama persis dengan MahasiswaController::import — kolom, urutan, dan aturan
     * "tidak ditemukan → simpan kosong + catat peringatan" disalin apa adanya.
     */
    public function import(): void
    {
        $this->result = null;
        $this->processing = true;
        $this->validate();

        $spreadsheet = IOFactory::load($this->file->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (count($rows) < 2) {
            $this->processing = false;
            $this->addError('file', 'File Excel kosong atau tidak valid. Minimal harus ada baris header dan satu baris data.');
            $this->reset('file');

            return;
        }

        array_shift($rows);

        $errors = [];
        $successCount = 0;
        $skipCount = 0;
        $processedRows = [];
        $processedNimIds = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $data = [
                    'nama' => $row[0] ?? null,
                    'nim' => $row[1] ?? null,
                    'email' => $row[2] ?? null,
                    'no_hp' => $row[3] ?? null,
                    'handphone' => $row[4] ?? null,
                    'jenis_kelamin' => $row[5] ?? null,
                    'id_tempat_lahir' => $row[6] ?? null,
                    'tanggal_lahir' => $row[7] ?? null,
                    'no_ktp' => $row[8] ?? null,
                    'status_akademik_nama' => $row[9] ?? null,
                    'alamat' => $row[10] ?? null,
                    'rt' => $row[11] ?? null,
                    'rw' => $row[12] ?? null,
                    'dusun' => $row[13] ?? null,
                    'kelurahan' => $row[14] ?? null,
                    'kode_pos' => $row[15] ?? null,
                    'id_kecamatan' => $row[16] ?? null,
                    'negara_nama' => $row[17] ?? null,
                    'provinsi_nama' => $row[18] ?? null,
                    'kota_nama' => $row[19] ?? null,
                    'prodi_kode' => $row[20] ?? null,
                    'kelompok_kelas_nama' => $row[21] ?? null,
                    'semester_masuk_kode' => $row[22] ?? null,
                    'jalur_masuk_nama' => $row[23] ?? null,
                    'jenis_daftar_nama' => $row[24] ?? null,
                    'mulai_semester' => $row[25] ?? null,
                    'sks_diakui' => $row[26] ?? null,
                    'sekolah_asal' => $row[27] ?? null,
                    'nis' => $row[28] ?? null,
                    'nisn' => $row[29] ?? null,
                    'npwp' => $row[30] ?? null,
                    'ayah' => $row[31] ?? null,
                    'nik_ayah' => $row[32] ?? null,
                    'tgl_lahir_ayah' => $row[33] ?? null,
                    'pddk_ayah_nama' => $row[34] ?? null,
                    'pekerjaan_ayah_nama' => $row[35] ?? null,
                    'penghasilan_ayah_nama' => $row[36] ?? null,
                    'ibu' => $row[37] ?? null,
                    'nik_ibu' => $row[38] ?? null,
                    'tgl_lahir_ibu' => $row[39] ?? null,
                    'pddk_ibu_nama' => $row[40] ?? null,
                    'pekerjaan_ibu_nama' => $row[41] ?? null,
                    'penghasilan_ibu_nama' => $row[42] ?? null,
                    'wali' => $row[43] ?? null,
                    'nik_wali' => $row[44] ?? null,
                    'tgl_lahir_wali' => $row[45] ?? null,
                    'pddk_wali_nama' => $row[46] ?? null,
                    'pekerjaan_wali_nama' => $row[47] ?? null,
                    'penghasilan_wali_nama' => $row[48] ?? null,
                    'jml_biaya_masuk' => $row[49] ?? null,
                    'penerima_kps' => $row[50] ?? null,
                    'no_kps' => $row[51] ?? null,
                    'foto_path' => $row[52] ?? null,
                ];

                if (empty(trim($data['nama'] ?? ''))) {
                    $errors[] = "Baris {$rowNumber}: Nama wajib diisi.";
                    $skipCount++;

                    continue;
                }

                $nimValue = ! empty($data['nim']) ? trim($data['nim']) : null;
                $mahasiswaToUpdate = null;
                if ($nimValue !== null) {
                    $existingByNim = Mahasiswa::where('nim', $nimValue)->first();
                    if ($existingByNim) {
                        $mahasiswaToUpdate = $existingByNim;
                        $errors[] = "Baris {$rowNumber}: NIM '{$nimValue}' sudah ada di sistem, data diperbarui.";
                    } elseif (isset($processedNimIds[$nimValue])) {
                        $mahasiswaToUpdate = Mahasiswa::find($processedNimIds[$nimValue]);
                        if ($mahasiswaToUpdate) {
                            $errors[] = "Baris {$rowNumber}: NIM '{$nimValue}' duplikat dalam file, data diperbarui.";
                        }
                    }
                }

                $emailValue = ! empty($data['email']) ? trim($data['email']) : null;
                if ($emailValue !== null) {
                    $emailExistsQuery = Mahasiswa::where('email', $emailValue);
                    if ($mahasiswaToUpdate !== null) {
                        $emailExistsQuery->where('id', '!=', $mahasiswaToUpdate->id);
                    }
                    if ($emailExistsQuery->exists()) {
                        $errors[] = "Baris {$rowNumber}: Email '{$emailValue}' sudah ada di sistem, disimpan dengan email kosong.";
                        $emailValue = null;
                    } else {
                        foreach ($processedRows as $processed) {
                            $sameEmail = isset($processed['email']) && $processed['email'] !== null && $emailValue === $processed['email'];
                            $isOtherRecord = ! $mahasiswaToUpdate || (isset($processed['id']) && $processed['id'] != $mahasiswaToUpdate->id);
                            if ($sameEmail && $isOtherRecord) {
                                $errors[] = "Baris {$rowNumber}: Email '{$emailValue}' duplikat dalam file, disimpan dengan email kosong.";
                                $emailValue = null;
                                break;
                            }
                        }
                    }
                }

                $id_prodi = null;
                if (! empty($data['prodi_kode'])) {
                    $prodi_kode = trim($data['prodi_kode']);
                    $prodi = Prodi::where('kode', $prodi_kode)->first();
                    if (! $prodi) {
                        $errors[] = "Baris {$rowNumber}: Kode Prodi '{$prodi_kode}' tidak ditemukan, disimpan dengan Prodi kosong.";
                    } else {
                        $id_prodi = $prodi->id;
                    }
                }

                $id_kelompok_kelas = null;
                if (! empty($data['kelompok_kelas_nama'])) {
                    $kelompok_kelas_nama = trim($data['kelompok_kelas_nama']);
                    $kelompok_kelas = KelompokKelas::where('nama', $kelompok_kelas_nama)->first();
                    if (! $kelompok_kelas) {
                        $errors[] = "Baris {$rowNumber}: Kelas mahasiswa '{$kelompok_kelas_nama}' tidak ditemukan, disimpan tanpa kelas mahasiswa.";
                    } else {
                        $id_kelompok_kelas = $kelompok_kelas->id;
                    }
                }

                $id_semester_masuk = null;
                if (! empty($data['semester_masuk_kode'])) {
                    $semester_kode = trim($data['semester_masuk_kode']);
                    $semester = Semester::where('kode', $semester_kode)->first();
                    if (! $semester) {
                        $errors[] = "Baris {$rowNumber}: Kode Semester Masuk '{$semester_kode}' tidak ditemukan, disimpan dengan Semester Masuk kosong.";
                    } else {
                        $id_semester_masuk = $semester->id;
                    }
                }

                $id_jalur_masuk = null;
                if (! empty($data['jalur_masuk_nama'])) {
                    $jalurMasuk = JalurMasuk::where('nama', 'like', '%'.trim($data['jalur_masuk_nama']).'%')->first();
                    if (! $jalurMasuk) {
                        $errors[] = "Baris {$rowNumber}: Jalur Masuk '{$data['jalur_masuk_nama']}' tidak ditemukan, disimpan dengan Jalur Masuk kosong.";
                    } else {
                        $id_jalur_masuk = $jalurMasuk->id;
                    }
                }

                $id_jenis_daftar = null;
                if (! empty($data['jenis_daftar_nama'])) {
                    $jenisDaftar = JenisDaftar::where('nama', 'like', '%'.trim($data['jenis_daftar_nama']).'%')->first();
                    if (! $jenisDaftar) {
                        $errors[] = "Baris {$rowNumber}: Jenis Daftar '{$data['jenis_daftar_nama']}' tidak ditemukan, disimpan dengan Jenis Daftar kosong.";
                    } else {
                        $id_jenis_daftar = $jenisDaftar->id;
                    }
                }

                $id_negara = null;
                if (! empty($data['negara_nama'])) {
                    $negara = Negara::where('nama', 'like', '%'.trim($data['negara_nama']).'%')->first();
                    if (! $negara) {
                        $errors[] = "Baris {$rowNumber}: Negara '{$data['negara_nama']}' tidak ditemukan, disimpan dengan Negara kosong.";
                    } else {
                        $id_negara = $negara->id;
                    }
                }

                $id_provinsi = null;
                if (! empty($data['provinsi_nama'])) {
                    $provinsiQuery = Provinsi::where('nama', 'like', '%'.trim($data['provinsi_nama']).'%');
                    if ($id_negara) {
                        $provinsiQuery->where('id_negara', $id_negara);
                    }
                    $provinsi = $provinsiQuery->first();
                    if (! $provinsi) {
                        $errors[] = "Baris {$rowNumber}: Provinsi '{$data['provinsi_nama']}' tidak ditemukan, disimpan dengan Provinsi kosong.";
                    } else {
                        $id_provinsi = $provinsi->id;
                    }
                }

                $id_kota = null;
                if (! empty($data['kota_nama'])) {
                    $kotaQuery = Kota::where('nama', 'like', '%'.trim($data['kota_nama']).'%');
                    if ($id_provinsi) {
                        $kotaQuery->where('id_provinsi', $id_provinsi);
                    }
                    $kota = $kotaQuery->first();
                    if (! $kota) {
                        $errors[] = "Baris {$rowNumber}: Kota '{$data['kota_nama']}' tidak ditemukan, disimpan dengan Kota kosong.";
                    } else {
                        $id_kota = $kota->id;
                    }
                }

                $jenis_kelamin = null;
                if (! empty($data['jenis_kelamin'])) {
                    $jk = strtoupper(trim($data['jenis_kelamin']));
                    if (! in_array($jk, ['L', 'P'])) {
                        $errors[] = "Baris {$rowNumber}: Jenis Kelamin '{$data['jenis_kelamin']}' tidak valid (harus L/P), disimpan kosong.";
                    } else {
                        $jenis_kelamin = $jk;
                    }
                }

                $id_status_akademik = null;
                if (! empty($data['status_akademik_nama'])) {
                    $status_nama = trim($data['status_akademik_nama']);
                    $statusAkademik = StatusAkademik::whereRaw('LOWER(nama) = ?', [strtolower($status_nama)])->first();
                    if (! $statusAkademik) {
                        $statusAkademik = StatusAkademik::whereRaw('LOWER(nama) LIKE ?', ['%'.strtolower($status_nama).'%'])->first();
                    }
                    if (! $statusAkademik) {
                        $errors[] = "Baris {$rowNumber}: Status Akademik '{$status_nama}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_status_akademik = $statusAkademik->id;
                    }
                }

                $id_pddk_ayah = null;
                if (! empty($data['pddk_ayah_nama'])) {
                    $pendidikan = Pendidikan::where('nama', 'like', '%'.trim($data['pddk_ayah_nama']).'%')->first();
                    if (! $pendidikan) {
                        $errors[] = "Baris {$rowNumber}: Pendidikan Ayah '{$data['pddk_ayah_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pddk_ayah = $pendidikan->id;
                    }
                }

                $id_pekerjaan_ayah = null;
                if (! empty($data['pekerjaan_ayah_nama'])) {
                    $pekerjaan = Pekerjaan::where('nama', 'like', '%'.trim($data['pekerjaan_ayah_nama']).'%')->first();
                    if (! $pekerjaan) {
                        $errors[] = "Baris {$rowNumber}: Pekerjaan Ayah '{$data['pekerjaan_ayah_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pekerjaan_ayah = $pekerjaan->id;
                    }
                }

                $id_penghasilan_ayah = null;
                if (! empty($data['penghasilan_ayah_nama'])) {
                    $penghasilan = Penghasilan::where('nama', 'like', '%'.trim($data['penghasilan_ayah_nama']).'%')->first();
                    if (! $penghasilan) {
                        $errors[] = "Baris {$rowNumber}: Penghasilan Ayah '{$data['penghasilan_ayah_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_penghasilan_ayah = $penghasilan->id;
                    }
                }

                $id_pddk_ibu = null;
                if (! empty($data['pddk_ibu_nama'])) {
                    $pendidikan = Pendidikan::where('nama', 'like', '%'.trim($data['pddk_ibu_nama']).'%')->first();
                    if (! $pendidikan) {
                        $errors[] = "Baris {$rowNumber}: Pendidikan Ibu '{$data['pddk_ibu_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pddk_ibu = $pendidikan->id;
                    }
                }

                $id_pekerjaan_ibu = null;
                if (! empty($data['pekerjaan_ibu_nama'])) {
                    $pekerjaan = Pekerjaan::where('nama', 'like', '%'.trim($data['pekerjaan_ibu_nama']).'%')->first();
                    if (! $pekerjaan) {
                        $errors[] = "Baris {$rowNumber}: Pekerjaan Ibu '{$data['pekerjaan_ibu_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pekerjaan_ibu = $pekerjaan->id;
                    }
                }

                $id_penghasilan_ibu = null;
                if (! empty($data['penghasilan_ibu_nama'])) {
                    $penghasilan = Penghasilan::where('nama', 'like', '%'.trim($data['penghasilan_ibu_nama']).'%')->first();
                    if (! $penghasilan) {
                        $errors[] = "Baris {$rowNumber}: Penghasilan Ibu '{$data['penghasilan_ibu_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_penghasilan_ibu = $penghasilan->id;
                    }
                }

                $id_pddk_wali = null;
                if (! empty($data['pddk_wali_nama'])) {
                    $pendidikan = Pendidikan::where('nama', 'like', '%'.trim($data['pddk_wali_nama']).'%')->first();
                    if (! $pendidikan) {
                        $errors[] = "Baris {$rowNumber}: Pendidikan Wali '{$data['pddk_wali_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pddk_wali = $pendidikan->id;
                    }
                }

                $id_pekerjaan_wali = null;
                if (! empty($data['pekerjaan_wali_nama'])) {
                    $pekerjaan = Pekerjaan::where('nama', 'like', '%'.trim($data['pekerjaan_wali_nama']).'%')->first();
                    if (! $pekerjaan) {
                        $errors[] = "Baris {$rowNumber}: Pekerjaan Wali '{$data['pekerjaan_wali_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_pekerjaan_wali = $pekerjaan->id;
                    }
                }

                $id_penghasilan_wali = null;
                if (! empty($data['penghasilan_wali_nama'])) {
                    $penghasilan = Penghasilan::where('nama', 'like', '%'.trim($data['penghasilan_wali_nama']).'%')->first();
                    if (! $penghasilan) {
                        $errors[] = "Baris {$rowNumber}: Penghasilan Wali '{$data['penghasilan_wali_nama']}' tidak ditemukan, disimpan kosong.";
                    } else {
                        $id_penghasilan_wali = $penghasilan->id;
                    }
                }

                // Foto: berupa path relatif yang HARUS sudah ada di storage disk public (bukan upload/URL) — jika tidak ditemukan: null, catat peringatan
                $fotoPath = null;
                if (! empty($data['foto_path'])) {
                    $foto_path_trimmed = ltrim(trim((string) $data['foto_path']), '/');
                    if (! Storage::disk('public')->exists($foto_path_trimmed)) {
                        $errors[] = "Baris {$rowNumber}: Foto '{$foto_path_trimmed}' tidak ditemukan di storage, disimpan dengan Foto kosong.";
                    } else {
                        $fotoPath = $foto_path_trimmed;
                    }
                }

                $mahasiswaData = [
                    'nama' => trim($data['nama']),
                    'nim' => $nimValue,
                    'email' => $emailValue,
                    'no_wa' => ! empty($data['no_hp']) ? trim((string) $data['no_hp']) : null,
                    'handphone' => ! empty($data['handphone']) ? trim((string) $data['handphone']) : null,
                    'jenis_kelamin' => $jenis_kelamin,
                    'id_tempat_lahir' => ! empty($data['id_tempat_lahir']) ? trim((string) $data['id_tempat_lahir']) : null,
                    'tanggal_lahir' => self::normalizeImportDate($data['tanggal_lahir'] ?? null),
                    'no_ktp' => ! empty($data['no_ktp']) ? trim($data['no_ktp']) : null,
                    'id_status_akademik' => $id_status_akademik,
                    'alamat' => ! empty($data['alamat']) ? trim($data['alamat']) : null,
                    'rt' => ! empty($data['rt']) ? trim($data['rt']) : null,
                    'rw' => ! empty($data['rw']) ? trim($data['rw']) : null,
                    'dusun' => ! empty($data['dusun']) ? trim($data['dusun']) : null,
                    'kelurahan' => ! empty($data['kelurahan']) ? trim($data['kelurahan']) : null,
                    'kode_pos' => ! empty($data['kode_pos']) ? trim($data['kode_pos']) : null,
                    'id_kecamatan' => ! empty($data['id_kecamatan']) ? trim((string) $data['id_kecamatan']) : null,
                    'id_negara' => $id_negara,
                    'id_provinsi' => $id_provinsi,
                    'id_kota' => $id_kota,
                    'id_prodi' => $id_prodi,
                    'id_kelompok_kelas' => $id_kelompok_kelas,
                    'id_semester_masuk' => $id_semester_masuk,
                    'id_jalur_masuk' => $id_jalur_masuk,
                    'id_jenis_daftar' => $id_jenis_daftar,
                    'mulai_semester' => ! empty($data['mulai_semester']) ? trim($data['mulai_semester']) : null,
                    'sks_diakui' => ! empty($data['sks_diakui']) ? (int) $data['sks_diakui'] : null,
                    'sekolah_asal' => ! empty($data['sekolah_asal']) ? trim($data['sekolah_asal']) : null,
                    'nis' => ! empty($data['nis']) ? trim($data['nis']) : null,
                    'nisn' => ! empty($data['nisn']) ? trim($data['nisn']) : null,
                    'npwp' => ! empty($data['npwp']) ? trim($data['npwp']) : null,
                    'ayah' => ! empty($data['ayah']) ? trim($data['ayah']) : null,
                    'nik_ayah' => ! empty($data['nik_ayah']) ? trim($data['nik_ayah']) : null,
                    'tgl_lahir_ayah' => self::normalizeImportDate($data['tgl_lahir_ayah'] ?? null),
                    'id_pddk_ayah' => $id_pddk_ayah,
                    'id_pekerjaan_ayah' => $id_pekerjaan_ayah,
                    'id_penghasilan_ayah' => $id_penghasilan_ayah,
                    'ibu' => ! empty($data['ibu']) ? trim($data['ibu']) : null,
                    'nik_ibu' => ! empty($data['nik_ibu']) ? trim($data['nik_ibu']) : null,
                    // Catatan: berbeda dari tgl_lahir_ayah/tgl_lahir_wali, controller API tidak
                    // memanggil normalizeImportDate untuk field ini — disalin apa adanya (lihat
                    // MahasiswaController::import) supaya perilaku panel & API tetap identik.
                    'tgl_lahir_ibu' => ! empty($data['tgl_lahir_ibu']) ? $data['tgl_lahir_ibu'] : null,
                    'id_pddk_ibu' => $id_pddk_ibu,
                    'id_pekerjaan_ibu' => $id_pekerjaan_ibu,
                    'id_penghasilan_ibu' => $id_penghasilan_ibu,
                    'wali' => ! empty($data['wali']) ? trim($data['wali']) : null,
                    'nik_wali' => ! empty($data['nik_wali']) ? trim($data['nik_wali']) : null,
                    'tgl_lahir_wali' => self::normalizeImportDate($data['tgl_lahir_wali'] ?? null),
                    'id_pddk_wali' => $id_pddk_wali,
                    'id_pekerjaan_wali' => $id_pekerjaan_wali,
                    'id_penghasilan_wali' => $id_penghasilan_wali,
                    'jml_biaya_masuk' => self::normalizeImportDecimal($data['jml_biaya_masuk'] ?? null),
                    'penerima_kps' => ! empty($data['penerima_kps']) ? trim($data['penerima_kps']) : null,
                    'no_kps' => ! empty($data['no_kps']) ? trim($data['no_kps']) : null,
                    'foto' => $fotoPath,
                ];

                if ($mahasiswaToUpdate !== null) {
                    $mahasiswaToUpdate->update($mahasiswaData);
                    $mahasiswa = $mahasiswaToUpdate;
                } else {
                    $mahasiswa = Mahasiswa::create($mahasiswaData);
                }

                $successCount++;
                if ($nimValue !== null) {
                    $processedNimIds[$nimValue] = $mahasiswa->id;
                }
                $processedRows[] = [
                    'nim' => $mahasiswaData['nim'],
                    'email' => $mahasiswaData['email'],
                    'id' => $mahasiswa->id,
                ];
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
            Log::error('Import mahasiswa gagal', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->addError('file', 'Terjadi kesalahan saat mengimport data! Harap periksa kembali data yang diimport.');
        }

        $this->processing = false;
    }

    /**
     * Sama dengan MahasiswaController::normalizeImportDate.
     */
    private static function normalizeImportDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n > 200 && $n < 120_000) {
                try {
                    return ExcelDate::excelToDateTimeObject($n)->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }
        if (is_string($value)) {
            $t = trim($value);
            if ($t === '') {
                return null;
            }
            try {
                return Carbon::parse($t)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Sama dengan MahasiswaController::normalizeImportDecimal.
     */
    private static function normalizeImportDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $s = preg_replace('/^Rp\s*/iu', '', $s);
        $s = str_replace([' ', "\u{00A0}"], '', $s);
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (str_contains($s, ',') && ! str_contains($s, '.')) {
            $s = str_replace(',', '.', $s);
        }
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    public function render()
    {
        return view('livewire.admin.mahasiswa.import')->extends('layouts.web');
    }
}
