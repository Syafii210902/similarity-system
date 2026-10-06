<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu baris per eksekusi analisis (Tier 1 + Tier 2) yang dipicu dosen.
     * Menyimpan status proses dan parameter/metrik untuk keperluan penelitian.
     */
    public function up(): void
    {
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');

            // Parameter
            $table->decimal('threshold', 4, 3);
            $table->string('embedding_model')->nullable();

            // Hasil ringkas & metrik (diisi dari callback FastAPI)
            $table->unsignedInteger('documents_sent')->default(0);
            $table->unsignedInteger('documents_processed')->nullable();
            $table->unsignedInteger('pairs_evaluated')->nullable();
            $table->unsignedInteger('pairs_flagged')->nullable();
            $table->json('metrics')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['assignment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_runs');
    }
};
