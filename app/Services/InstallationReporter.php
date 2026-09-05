<?php

namespace App\Services;

use App\Models\Dosen;
use App\Models\Mahasiswa;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mendaftarkan/memperbarui data instalasi AIS ini ke Sikampus Server (sikampus-web) tiap kali
 * license key disimpan lewat Pengaturan > Sistem > License Key. Gagal kirim (server tidak
 * dikonfigurasi, tidak bisa dihubungi, dsb) sengaja tidak melempar exception — penyimpanan
 * license key secara lokal tidak boleh gagal hanya karena Sikampus Server sedang tidak bisa
 * dihubungi.
 */
class InstallationReporter
{
    public function report(string $licenseKey): void
    {
        $url = trim((string) config('sikampus_server.url'));

        if ($url === '' || $licenseKey === '') {
            return;
        }

        try {
            Http::timeout(5)->post(rtrim($url, '/').'/api/installations', $this->buildPayload($licenseKey));
        } catch (\Throwable $e) {
            Log::warning('Gagal mendaftarkan instalasi ke Sikampus Server', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function buildPayload(string $licenseKey): array
    {
        $univSettings = Setting::whereIn('key', [
            'app_univ_name', 'app_univ_email', 'app_univ_phone', 'app_univ_address', 'app_univ_website',
        ])->pluck('value', 'key');

        return [
            'license_key' => $licenseKey,
            'app_url' => config('app.url'),
            'app_name' => config('app.name'),
            // Dikirim supaya Sikampus Server tahu instalasi ini jalan di versi berapa, tanpa
            // perlu menghubungi instance-nya balik. Endpoint /api/installations di sana sudah
            // menerima & menyimpan field ini sejak awal (installations.metadata.app_version),
            // jadi tidak ada perubahan yang dibutuhkan di sisi server untuk mulai memakainya.
            'app_version' => config('sikampus.version'),
            'institution' => [
                'name' => $univSettings->get('app_univ_name'),
                'email' => $univSettings->get('app_univ_email'),
                'phone' => $univSettings->get('app_univ_phone'),
                'address' => $univSettings->get('app_univ_address'),
                'website' => $univSettings->get('app_univ_website'),
            ],
            'stats' => [
                'mahasiswa_count' => Mahasiswa::count(),
                'dosen_count' => Dosen::count(),
            ],
        ];
    }
}
