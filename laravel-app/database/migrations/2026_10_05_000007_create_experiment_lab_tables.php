<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lab Pengujian (role peneliti): korpus uji berlabel & eksperimen, terpisah dari data akademik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['dosen', 'mahasiswa', 'admin', 'peneliti'])->default('mahasiswa')->change();
        });

        Schema::create('experiment_datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_sample')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        /*
         * Kategori menentukan label otomatis:
         *  - ORIGINAL, INDEPENDENT, AI_GENERATED : dokumen berdiri sendiri (tanpa sumber)
         *  - VERBATIM_COPY, PARAPHRASE_MANUAL, PARAPHRASE_AI : turunan dari source_document_id
         * Dua dokumen = plagiat jika berasal dari "keluarga" (sumber akar) yang sama.
         */
        Schema::create('experiment_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('experiment_datasets')->cascadeOnDelete();
            $table->string('title');
            $table->enum('category', ['ORIGINAL', 'VERBATIM_COPY', 'PARAPHRASE_MANUAL', 'PARAPHRASE_AI', 'INDEPENDENT', 'AI_GENERATED']);
            $table->foreignId('source_document_id')->nullable()->constrained('experiment_documents')->nullOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('word_count')->nullable();
            $table->timestamps();
        });

        Schema::create('experiment_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained('experiment_datasets')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->json('config');
            $table->json('metrics')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Skor per lapis disimpan mentah agar bobot & ambang dapat dihitung ulang tanpa memanggil FastAPI/LLM lagi.
        Schema::create('experiment_pair_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('experiment_runs')->cascadeOnDelete();
            $table->foreignId('document_low_id')->constrained('experiment_documents')->cascadeOnDelete();
            $table->foreignId('document_high_id')->constrained('experiment_documents')->cascadeOnDelete();
            $table->decimal('document_score', 6, 4);
            $table->decimal('passage_score', 6, 4);
            $table->decimal('sentence_score', 6, 4);
            $table->decimal('combined_score', 6, 4);
            $table->boolean('llm_checked')->default(false);
            $table->string('llm_action')->nullable();
            $table->boolean('llm_error')->default(false);
            $table->json('llm_result')->nullable();

            $table->unique(['run_id', 'document_low_id', 'document_high_id'], 'exp_pair_unique');
        });

        Schema::create('experiment_ai_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('experiment_runs')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('experiment_documents')->cascadeOnDelete();
            $table->decimal('ai_probability', 5, 4)->nullable();
            $table->string('verdict');
            $table->text('analysis_summary')->nullable();

            $table->unique(['run_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_ai_scores');
        Schema::dropIfExists('experiment_pair_scores');
        Schema::dropIfExists('experiment_runs');
        Schema::dropIfExists('experiment_documents');
        Schema::dropIfExists('experiment_datasets');

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['dosen', 'mahasiswa', 'admin'])->default('mahasiswa')->change();
        });
    }
};
