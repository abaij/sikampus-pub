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
        Schema::table('yudisium', function (Blueprint $table) {
            $table->string('no_transkrip')->after('no_ijazah')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('yudisium', function (Blueprint $table) {
            $table->dropColumn('no_transkrip');
        });
    }
};
