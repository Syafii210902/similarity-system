<?php

namespace App\Http\Controllers\Dosen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dosen\EnrollStudentsRequest;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class CourseStudentController extends Controller
{
    public function store(EnrollStudentsRequest $request, Course $course): RedirectResponse
    {
        $result = $course->mahasiswa()->syncWithoutDetaching($request->validated('student_ids'));
        $added = count($result['attached']);

        return redirect()
            ->to(route('dosen.courses.show', $course) . '#mahasiswa')
            ->with('success', "{$added} mahasiswa ditambahkan ke mata kuliah.");
    }

    /**
     * Mengeluarkan mahasiswa dari mata kuliah. Pengumpulan yang sudah ada tetap disimpan.
     */
    public function destroy(Course $course, User $student): RedirectResponse
    {
        Gate::authorize('manage', $course);

        $course->mahasiswa()->detach($student->id);

        return redirect()
            ->to(route('dosen.courses.show', $course) . '#mahasiswa')
            ->with('success', "{$student->name} dikeluarkan dari mata kuliah.");
    }
}
