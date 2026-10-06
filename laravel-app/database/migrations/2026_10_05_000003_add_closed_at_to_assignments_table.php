<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penutupan manual oleh dosen (terpisah dari tenggat). Pengumpulan dianggap
     * ditutup jika closed_at terisi ATAU due_date sudah lewat.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
