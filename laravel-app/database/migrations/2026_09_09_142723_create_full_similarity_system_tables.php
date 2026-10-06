<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Tabel Users (Dosen & Mahasiswa)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['dosen', 'mahasiswa', 'admin'])->default('mahasiswa');
            $table->rememberToken();
            $table->timestamps();
        });

        // 2. Tabel Courses (Mata Kuliah)
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dosen_id')->constrained('users')->onDelete('cascade');
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 3. Tabel Pivot Course_User (Mahasiswa yang mengambil Matkul)
        Schema::create('course_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // 4. Tabel Assignments (Tugas)
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('due_date')->nullable();
            $table->timestamps();
        });

        // 5. Tabel Submissions (Pengumpulan Berkas Mahasiswa)
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('file_path');
            $table->string('file_name');
            $table->timestamps();
        });

        // 6. Tabel Similarity Results (Hasil Analisis Similarity & Gemini LLM)
        Schema::create('similarity_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->onDelete('cascade');
            $table->foreignId('submission_a_id')->constrained('submissions')->onDelete('cascade');
            $table->foreignId('submission_b_id')->constrained('submissions')->onDelete('cascade');
            $table->decimal('similarity_score', 5, 4); // Rentang 0.0000 - 1.0000
            $table->boolean('is_flagged')->default(false);
            $table->string('verdict')->nullable(); // Misal: "Indikasi Plagiarisme Tinggi"
            $table->enum('action_recommendation', ['SKIP_KOREKSI', 'PERIKSA_MANUAL', 'AMAN'])->default('AMAN');
            $table->text('summary')->nullable(); // Ringkasan bukti dari Gemini LLM
            $table->json('matched_segments')->nullable(); // Array paragraf/kalimat yang mirip
            $table->timestamps();
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('similarity_results');
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('course_user');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};