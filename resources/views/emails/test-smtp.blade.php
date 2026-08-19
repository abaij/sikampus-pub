<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Tes SMTP</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0;">Email Tes SMTP</h1>
    </div>

    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <p>Halo,</p>

        <p>Ini adalah email tes yang dikirim dari halaman <strong>Pengaturan &gt; Sistem &gt; SMTP</strong> pada aplikasi <strong>{{ config('app.name') }}</strong>.</p>

        <p>Kalau Anda menerima email ini, artinya pengaturan SMTP yang sedang diuji sudah benar dan berhasil mengirim email.</p>

        <p style="font-size: 12px; color: #666; margin-top: 30px;">
            Dikirim pada {{ $sentAt->translatedFormat('d F Y, H:i') }} WIB.
        </p>
    </div>
</body>
</html>
