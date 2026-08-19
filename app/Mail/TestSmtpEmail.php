<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Dikirim dari App\Livewire\Admin\Sistem\Pengaturan::sendTestEmail() saat admin klik "Kirim
 * Email Tes" di halaman Pengaturan > Sistem > SMTP. Sengaja TIDAK implements ShouldQueue —
 * tujuan fitur ini adalah tahu saat itu juga apakah pengaturan SMTP berhasil terhubung dan
 * mengirim, jadi harus dikirim sinkron supaya exception-nya (kalau ada) bisa langsung
 * ditangkap dan ditampilkan ke admin, bukan baru diketahui lewat log queue worker nanti.
 */
class TestSmtpEmail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $fromAddress,
        public string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: 'Email Tes SMTP — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test-smtp',
            with: [
                'sentAt' => now(),
            ],
        );
    }
}
