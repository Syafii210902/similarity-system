<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Akun admin default. Idempoten: aman dijalankan berulang kali.
     * Ganti password setelah login pertama di environment non-lokal.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@kampus.ac.id'],
            [
                'name' => 'Administrator',
                'password' => 'password',
                'role' => User::ROLE_ADMIN,
            ]
        );

        // Akun Lab Pengujian (eksperimen metode).
        User::updateOrCreate(
            ['email' => 'peneliti@kampus.ac.id'],
            [
                'name' => 'Peneliti',
                'password' => 'password',
                'role' => User::ROLE_PENELITI,
            ]
        );
    }
}
