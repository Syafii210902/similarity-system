<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_DOSEN = 'dosen';
    public const ROLE_MAHASISWA = 'mahasiswa';
    public const ROLE_PENELITI = 'peneliti';

    /** Role yang diizinkan login ke dashboard web. */
    public const DASHBOARD_ROLES = [self::ROLE_ADMIN, self::ROLE_DOSEN, self::ROLE_MAHASISWA, self::ROLE_PENELITI];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDosen(): bool
    {
        return $this->role === self::ROLE_DOSEN;
    }

    public function isPeneliti(): bool
    {
        return $this->role === self::ROLE_PENELITI;
    }

    public function isMahasiswa(): bool
    {
        return $this->role === self::ROLE_MAHASISWA;
    }

    public function isEnrolledIn(Course $course): bool
    {
        return $this->coursesEnrolled()->whereKey($course->getKey())->exists();
    }

    public function canAccessDashboard(): bool
    {
        return in_array($this->role, self::DASHBOARD_ROLES, true);
    }

    public function dashboardRoute(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => 'admin.dashboard',
            self::ROLE_DOSEN => 'dosen.dashboard',
            self::ROLE_MAHASISWA => 'mahasiswa.dashboard',
            self::ROLE_PENELITI => 'peneliti.dashboard',
            default => 'login',
        };
    }

    // Relasi jika user adalah Dosen
    public function coursesTaught(): HasMany
    {
        return $this->hasMany(Course::class, 'dosen_id');
    }

    // Relasi jika user adalah Mahasiswa
    public function coursesEnrolled(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_user');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}