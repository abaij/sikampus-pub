<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_runs', function (Blueprint $table) {
            $table->id();

            $table->string('version_from');
            $table->string('version_to');

            // 'archive' (ganti berkas dari zip rilis) atau 'git' (pull + composer + npm).
            $table->string('path');

            // pending|downloading|verifying|extracting|applying|success|failed|rolled_back
            $table->string('status')->default('pending');

            // Langkah terakhir yang SEDANG atau TERAKHIR dikerjakan. Dipisah dari status supaya
            // baris yang gagal tetap menyimpan di mana persisnya kegagalan terjadi.
            $table->string('step')->nullable();

            $table->text('error_message')->nullable();

            // Catatan langkah demi langkah yang ditampilkan ke superadmin. Sengaja disimpan di
            // DATABASE, bukan hanya di log file: instalasi yang paling butuh halaman ini adalah
            // yang tidak punya akses shell, jadi storage/logs tidak terjangkau penggunanya.
            $table->longText('log')->nullable();

            // Jejak lokasi kerja & backup, supaya pembersihan/rollback tidak perlu menebak.
            $table->string('workspace_path')->nullable();
            $table->string('backup_path')->nullable();

            $table->foreignId('id_user')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_runs');
    }
};
