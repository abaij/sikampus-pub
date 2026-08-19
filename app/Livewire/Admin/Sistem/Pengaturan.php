<?php

namespace App\Livewire\Admin\Sistem;

use App\Mail\TestSmtpEmail;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class Pengaturan extends Component
{
    public string $host = '';

    public string $port = '';

    public string $username = '';

    public string $password = '';

    public string $encryption = '';

    public string $fromAddress = '';

    public string $fromName = '';

    /**
     * Password tidak pernah dikirim balik ke browser — properti ini cuma menandai apakah baris
     * app_mail_password sudah ada isinya, supaya form tahu field password boleh dibiarkan kosong
     * saat simpan (artinya "jangan diubah"), bukan berarti "kosongkan password".
     */
    public bool $hasStoredPassword = false;

    /**
     * Alamat tujuan untuk tombol "Kirim Email Tes" — terpisah dari form SMTP di atas, tidak
     * pernah disimpan ke database.
     */
    public string $testEmail = '';

    public function mount(): void
    {
        $settings = Setting::where('key', 'like', 'app_mail_%')->pluck('value', 'key');

        $this->host = (string) ($settings['app_mail_host'] ?? '');
        $this->port = (string) ($settings['app_mail_port'] ?? '');
        $this->username = (string) ($settings['app_mail_username'] ?? '');
        $this->encryption = (string) ($settings['app_mail_encryption'] ?? '');
        $this->fromAddress = (string) ($settings['app_mail_from_address'] ?? '');
        $this->fromName = (string) ($settings['app_mail_from_name'] ?? '');
        $this->hasStoredPassword = (string) ($settings['app_mail_password'] ?? '') !== '';
    }

    protected function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:,smtps'],
            'fromAddress' => ['required', 'email', 'max:255'],
            'fromName' => ['required', 'string', 'max:255'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        $values = [
            'app_mail_host' => $validated['host'],
            'app_mail_port' => (string) $validated['port'],
            'app_mail_username' => $validated['username'],
            'app_mail_encryption' => $validated['encryption'] ?? '',
            'app_mail_from_address' => $validated['fromAddress'],
            'app_mail_from_name' => $validated['fromName'],
        ];

        if (($validated['password'] ?? '') !== '') {
            $values['app_mail_password'] = $validated['password'];
        }

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        if (($validated['password'] ?? '') !== '') {
            $this->hasStoredPassword = true;
        }
        $this->password = '';

        session()->flash('status', 'Pengaturan SMTP berhasil disimpan.');
    }

    /**
     * Kirim email tes memakai nilai yang SEDANG DIKETIK di form ini — bukan nilai yang sudah
     * tersimpan di database — supaya admin bisa mengecek pengaturan sebelum menekan Simpan.
     * Dikirim lewat mailer 'smtp_test' yang dibuat dadakan di sini (bukan mailer 'smtp' bawaan
     * config/mail.php) supaya tidak menyentuh/menimpa config mailer default yang mungkin sedang
     * dipakai bagian lain aplikasi di request yang sama.
     *
     * Sengaja sinkron (bukan dispatch job) supaya sukses/gagalnya — termasuk pesan error asli
     * dari server SMTP tujuan, mis. "Authentication failed" — langsung diketahui saat itu juga.
     */
    public function sendTestEmail(): void
    {
        $validated = $this->validate(array_merge($this->rules(), [
            'testEmail' => ['required', 'email'],
        ]));

        $password = $validated['password'] !== ''
            ? $validated['password']
            : (string) Setting::where('key', 'app_mail_password')->value('value');

        config(['mail.mailers.smtp_test' => [
            'transport' => 'smtp',
            'scheme' => $validated['encryption'] ?: null,
            'host' => $validated['host'],
            'port' => (int) $validated['port'],
            'username' => $validated['username'] ?: null,
            'password' => $password ?: null,
        ]]);

        try {
            Mail::mailer('smtp_test')
                ->to($validated['testEmail'])
                ->send(new TestSmtpEmail($validated['fromAddress'], $validated['fromName']));

            session()->flash('status', "Email tes berhasil dikirim ke {$validated['testEmail']}.");
        } catch (\Throwable $e) {
            session()->flash('error', 'Gagal mengirim email tes: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.admin.sistem.pengaturan')->extends('layouts.web');
    }
}
