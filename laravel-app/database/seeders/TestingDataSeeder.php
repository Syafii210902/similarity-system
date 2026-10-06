<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestingDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Buat User Dosen
        $dosen = User::create([
            'name' => 'Dr. Budi Santoso, M.Kom',
            'email' => 'dosen@kampus.ac.id',
            'password' => Hash::make('password'),
            'role' => 'dosen',
        ]);

        // 2. Buat User Mahasiswa
        $mhs1 = User::create([
            'name' => 'Andi Pratama',
            'email' => 'andi@student.kampus.ac.id',
            'password' => Hash::make('password'),
            'role' => 'mahasiswa',
        ]);

        $mhs2 = User::create([
            'name' => 'Siti Rahma',
            'email' => 'siti@student.kampus.ac.id',
            'password' => Hash::make('password'),
            'role' => 'mahasiswa',
        ]);

        // 3. Buat Mata Kuliah
        $course = Course::create([
            'dosen_id' => $dosen->id,
            'code' => 'IF2026',
            'name' => 'Pemrograman Web Lanjut',
            'description' => 'Mata kuliah pengembangan aplikasi web enterprise.',
        ]);

        // Daftarkan mahasiswa ke mata kuliah
        $course->mahasiswa()->attach([$mhs1->id, $mhs2->id]);

        // Tugas yang masih dibuka (untuk mencoba fitur upload mahasiswa)
        Assignment::create([
            'course_id' => $course->id,
            'title' => 'Tugas 2 - Desain REST API',
            'description' => 'Rancang endpoint REST API untuk sistem akademik. Kumpulkan dalam format PDF atau DOCX.',
            'due_date' => now()->addDays(14),
        ]);

        // 4. Buat Assignment (ID = 1)
        $assignment = Assignment::create([
            'course_id' => $course->id,
            'title' => 'Tugas 1 - Arsitektur Microservices',
            'description' => 'Buatlah makalah ringkas tentang implementasi REST API.',
            'due_date' => now()->addDays(7),
        ]);

        // 5. Buat Submission Dummy
        Submission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $mhs1->id,
            'file_name' => 'tugas_andi.pdf',
            'file_path' => 'submissions/tugas_andi.pdf',
        ]);

        Submission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $mhs2->id,
            'file_name' => 'tugas_siti.pdf',
            'file_path' => 'submissions/tugas_siti.pdf',
        ]);
    }
}