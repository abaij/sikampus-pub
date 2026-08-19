<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Siapkan baris `settings` untuk identitas pejabat penandatangan transkrip.
 *
 * Fitur transkrip ini sebelumnya hidup sebagai plugin (slug `transkrip-pdf`) yang menyimpan
 * nilainya dengan awalan `plugin_transkrip_pdf_`. Karena sekarang jadi bagian inti aplikasi,
 * nilai yang sudah terlanjur diisi superadmin di halaman pengaturan plugin dipindahkan ke key
 * `app_transkrip_*` — tanpa ini, instalasi yang sudah memakai plugin akan mendadak mencetak
 * transkrip tanpa nama penandatangan dan harus mengisi ulang.
 *
 * Baris lama sengaja TIDAK dihapus: plugin-nya mungkin masih terpasang dan halaman pengaturannya
 * masih membaca key itu, jadi menghapusnya akan membuat halaman plugin tampak kosong tanpa sebab.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> key baru => key lama milik plugin
     */
    private const PEMETAAN = [
        'app_transkrip_jabatan' => 'plugin_transkrip_pdf_jabatan',
        'app_transkrip_jabatan_en' => 'plugin_transkrip_pdf_jabatan_en',
        'app_transkrip_nama_pejabat' => 'plugin_transkrip_pdf_nama_pejabat',
        'app_transkrip_nip' => 'plugin_transkrip_pdf_nip',
        'app_transkrip_kota_terbit' => 'plugin_transkrip_pdf_kota_terbit',
    ];

    public function up(): void
    {
        foreach (self::PEMETAAN as $keyBaru => $keyLama) {
            // Jangan timpa kalau key baru sudah ada isinya — migrasi bisa saja dijalankan ulang
            // setelah superadmin mengisi halaman pengaturan yang baru.
            $sudahAda = DB::table('settings')
                ->where('key', $keyBaru)
                ->whereNotNull('value')
                ->where('value', '!=', '')
                ->exists();

            if ($sudahAda) {
                continue;
            }

            $nilaiLama = DB::table('settings')
                ->where('key', $keyLama)
                ->whereNull('deleted_at')
                ->value('value');

            // Kolom `key` unik DAN tabelnya soft delete, jadi baris yang sudah di-soft-delete
            // tetap memakai slot unique-nya — cari tanpa memfilter deleted_at, lalu hidupkan lagi.
            $baris = DB::table('settings')->where('key', $keyBaru)->first();

            if ($baris !== null) {
                DB::table('settings')->where('key', $keyBaru)->update([
                    'value' => (string) ($nilaiLama ?? ''),
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('settings')->insert([
                'key' => $keyBaru,
                'value' => (string) ($nilaiLama ?? ''),
                'description' => 'Penandatangan transkrip nilai',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(self::PEMETAAN))->delete();
    }
};
