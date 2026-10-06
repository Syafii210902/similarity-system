<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deteksi AI yang gagal dicatat sebagai ERROR (probabilitas kosong), bukan HUMAN_WRITTEN 0.0,
     * agar tidak tercampur dengan hasil valid dalam data penelitian.
     */
    public function up(): void
    {
        Schema::table('ai_detection_results', function (Blueprint $table) {
            $table->enum('verdict', ['HUMAN_WRITTEN', 'MIXED_AI', 'LIKELY_AI', 'ERROR'])->change();
            $table->decimal('ai_probability', 5, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_detection_results', function (Blueprint $table) {
            $table->enum('verdict', ['HUMAN_WRITTEN', 'LIKELY_AI', 'MIXED_AI'])->change();
            $table->decimal('ai_probability', 5, 4)->nullable(false)->change();
        });
    }
};
