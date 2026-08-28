<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot keringanan bertipe persentase.
     *
     * `nominal` tetap berarti rupiah — itu satu-satunya arti yang dipahami
     * KeringananBiayaKreditService. Dua kolom ini merekam dari mana rupiah itu berasal, supaya
     * keringanan yang sudah disetujui tidak berubah arti ketika master jenis_keringanan_biaya
     * diedit belakangan.
     */
    public function up(): void
    {
        Schema::table('keringanan_biaya', function (Blueprint $table) {
            $table->decimal('persentase', 5, 2)->nullable()->after('nominal');
            $table->decimal('dasar_perhitungan', 15, 2)->nullable()->after('persentase');
            $table->timestamp('dasar_dihitung_pada')->nullable()->after('dasar_perhitungan');
        });
    }

    public function down(): void
    {
        Schema::table('keringanan_biaya', function (Blueprint $table) {
            $table->dropColumn(['persentase', 'dasar_perhitungan', 'dasar_dihitung_pada']);
        });
    }
};
