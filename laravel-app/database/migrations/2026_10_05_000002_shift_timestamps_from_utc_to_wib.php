<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sebelumnya app.timezone = UTC, sehingga semua kolom waktu tersimpan dalam UTC.
 * Setelah pindah ke Asia/Jakarta, nilai lama harus digeser +7 jam agar tetap
 * menunjuk ke momen yang sama (MySQL DATETIME/TIMESTAMP tidak menyimpan zona waktu).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'users' => ['created_at', 'updated_at'],
        'courses' => ['created_at', 'updated_at'],
        'course_user' => ['created_at', 'updated_at'],
        'assignments' => ['due_date', 'created_at', 'updated_at'],
        'submissions' => ['created_at', 'updated_at'],
        'similarity_results' => ['created_at', 'updated_at'],
        'ai_detection_results' => ['created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift('+');
    }

    public function down(): void
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        // Database baru (mis. SQLite in-memory untuk test) tidak punya data lama.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $fn = $sign === '+' ? 'DATE_ADD' : 'DATE_SUB';

        foreach (self::COLUMNS as $table => $columns) {
            $set = collect($columns)
                ->map(fn ($c) => "`{$c}` = {$fn}(`{$c}`, INTERVAL 7 HOUR)")
                ->implode(', ');

            DB::statement("UPDATE `{$table}` SET {$set}");
        }
    }
};
