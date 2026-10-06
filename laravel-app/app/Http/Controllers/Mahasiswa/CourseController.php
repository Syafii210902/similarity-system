<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CourseController extends Controller
{
    public function show(Request $request, Course $course): View
    {
        Gate::authorize('view', $course);

        $course->load('dosen:id,name');

        $assignments = $course->assignments()
            ->with(['submissions' => fn ($q) => $q->where('user_id', $request->user()->id)])
            ->orderByRaw('due_date IS NULL, due_date ASC')
            ->get();

        return view('mahasiswa.courses.show', compact('course', 'assignments'));
    }
}
