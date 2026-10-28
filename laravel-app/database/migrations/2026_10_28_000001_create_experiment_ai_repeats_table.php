<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uji konsistensi deteksi AI: ulangan ke-2, ke-3, dst. untuk dokumen sebuah run Lab.
 * Ulangan ke-1 adalah hasil run utama (experiment_ai_scores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experiment_ai_repeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('experiment_runs')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('experiment_documents')->cascadeOnDelete();
            $table->unsignedTinyInteger('repeat');
            $table->decimal('ai_probability', 5, 4)->nullable();
            $table->string('verdict');
            $table->text('analysis_summary')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['run_id', 'document_id', 'repeat']);
        });

        // Status proses uji konsistensi: {status, target, message, updated_at}.
        Schema::table('experiment_runs', function (Blueprint $table) {
            $table->json('consistency')->nullable()->after('metrics');
        });
    }

    public function down(): void
    {
        Schema::table('experiment_runs', function (Blueprint $table) {
            $table->dropColumn('consistency');
        });
        Schema::dropIfExists('experiment_ai_repeats');
    }
};
