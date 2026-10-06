<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu mahasiswa hanya boleh punya satu pengumpulan per tugas.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->unique(['assignment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // FK assignment_id butuh index; buat index biasa sebelum unique di-drop (MySQL).
            $table->index('assignment_id');
            $table->dropUnique(['assignment_id', 'user_id']);
        });
    }
};
