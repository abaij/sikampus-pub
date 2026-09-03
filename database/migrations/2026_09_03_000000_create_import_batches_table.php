<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            // Pembeda importer mana yang punya baris ini (mis. "perkuliahan") — dipakai kalau
            // nanti modul import lain juga dipindah ke pola job antrian ini.
            $table->string('type');
            $table->foreignId('id_user')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending|processing|completed|failed
            $table->string('file_path')->nullable();
            // Konteks otorisasi & actor disalin ke sini SAAT request datang (bukan dibaca ulang
            // dari Auth::user() di job) — worker antrian jalan lepas dari sesi web request asal.
            $table->json('allowed_prodi_ids')->nullable();
            $table->string('actor')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
