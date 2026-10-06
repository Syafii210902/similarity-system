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
        Schema::create('ai_detection_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->onDelete('cascade');
            $table->foreignId('submission_id')->constrained('submissions')->onDelete('cascade');
            $table->decimal('ai_probability', 5, 4); // Skor 0.0000 - 1.0000 (0% - 100%)
            $table->enum('verdict', ['HUMAN_WRITTEN', 'LIKELY_AI', 'MIXED_AI']);
            $table->text('analysis_summary')->nullable();
            $table->json('flagged_patterns')->nullable(); // Menampung frasa/kalimat khas AI
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_detection_results');
    }
};
