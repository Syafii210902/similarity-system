<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_can_be_rendered(): void
    {
        $this->get('/login')->assertOk()->assertSee('Gunakan akun yang diberikan oleh admin');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/admin/dashboard')->assertRedirect('/login');
    }

    public function test_admin_logs_in_and_lands_on_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($admin);
        $this->get('/dashboard')->assertRedirect('/admin/dashboard');
        $this->get('/admin/dashboard')->assertOk()->assertSee('Beranda Admin');
    }

    public function test_dosen_logs_in_and_lands_on_dosen_dashboard(): void
    {
        $dosen = User::factory()->dosen()->create();

        $this->post('/login', ['email' => $dosen->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($dosen);
        $this->get('/dashboard')->assertRedirect('/dosen/dashboard');
        $this->get('/dosen/dashboard')->assertOk()->assertSee('Beranda Dosen');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'salah'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_corrupted_password_hash_fails_gracefully(): void
    {
        $user = User::factory()->create();
        // Simulasi hash terpotong (mis. "$2y$12$" akibat ekspansi variabel shell).
        \DB::table('users')->where('id', $user->id)->update(['password' => '$2y$12$broken']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_mahasiswa_logs_in_and_lands_on_mahasiswa_dashboard(): void
    {
        $mahasiswa = User::factory()->create();

        $this->post('/login', ['email' => $mahasiswa->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($mahasiswa);
        $this->get('/dashboard')->assertRedirect('/mahasiswa/dashboard');
        $this->get('/mahasiswa/dashboard')->assertOk()->assertSee('Mata kuliah yang diikuti');
    }

    public function test_roles_cannot_open_each_others_dashboard(): void
    {
        $this->actingAs(User::factory()->dosen()->create())
            ->get('/admin/dashboard')
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/dosen/dashboard')
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get('/admin/dashboard')
            ->assertForbidden();
    }

    public function test_user_can_logout(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
