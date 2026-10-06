<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AssignmentPolicy
{
    public function view(User $user, Assignment $assignment): bool
    {
        return (new CoursePolicy())->view($user, $assignment->course);
    }

    /**
     * Ubah, hapus, tutup/buka pengumpulan: hanya dosen pengampu mata kuliah.
     */
    public function manage(User $user, Assignment $assignment): bool
    {
        return (new CoursePolicy())->manage($user, $assignment->course);
    }

    /**
     * Upload, ganti, maupun hapus pengumpulan: hanya mahasiswa terdaftar & selama dibuka.
     */
    public function submit(User $user, Assignment $assignment): Response
    {
        if (!$user->isMahasiswa() || !$user->isEnrolledIn($assignment->course)) {
            return Response::deny('Anda tidak terdaftar pada mata kuliah ini.');
        }

        if (!$assignment->isOpen()) {
            return Response::deny('Pengumpulan sudah ditutup. Pengumpulan tidak dapat diubah.');
        }

        return Response::allow();
    }
}
