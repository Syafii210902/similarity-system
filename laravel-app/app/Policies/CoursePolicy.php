<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function view(User $user, Course $course): bool
    {
        return match ($user->role) {
            User::ROLE_ADMIN => true,
            User::ROLE_DOSEN => $this->owns($user, $course),
            User::ROLE_MAHASISWA => $user->isEnrolledIn($course),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->isDosen();
    }

    /**
     * Ubah data, kelola mahasiswa & tugas: hanya dosen pengampu.
     */
    public function manage(User $user, Course $course): bool
    {
        return $this->owns($user, $course);
    }

    private function owns(User $user, Course $course): bool
    {
        return $user->isDosen() && $course->dosen_id === $user->id;
    }
}
