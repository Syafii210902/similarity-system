<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Skor per lapis embedding (dokumen, passage, kalimat) untuk evaluasi tiap lapis.
        Schema::table('similarity_results', function (Blueprint $table) {
            $table->json('layer_scores')->nullable()->after('similarity_score');
        });

        /*
         * Label manual dosen (ground truth) disimpan terpisah dari tabel hasil, karena hasil
         * analisis dihapus & dibuat ulang setiap kali analisis dijalankan ulang.
         * Pasangan disimpan dengan urutan id kecil-besar agar unik tanpa memandang urutan A/B.
         */
        Schema::create('pair_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_low_id')->constrained('submissions')->cascadeOnDelete();
            $table->foreignId('submission_high_id')->constrained('submissions')->cascadeOnDelete();
            $table->boolean('is_plagiarism');
            $table->text('note')->nullable();
            $table->foreignId('labeled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['submission_low_id', 'submission_high_id']);
        });

        Schema::create('ai_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_ai');
            $table->foreignId('labeled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_labels');
        Schema::dropIfExists('pair_labels');

        Schema::table('similarity_results', function (Blueprint $table) {
            $table->dropColumn('layer_scores');
        });
    }
};
