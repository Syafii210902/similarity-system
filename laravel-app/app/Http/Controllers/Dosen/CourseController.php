<?php

namespace App\Http\Controllers\Dosen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dosen\CourseRequest;
use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use App\Services\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CourseController extends Controller
{
    public function __construct(
        protected SubmissionService $submissionService
    ) {}

    public function index(Request $request): View
    {
        $courses = $request->user()->coursesTaught()
            ->withCount(['assignments', 'mahasiswa'])
            ->orderBy('code')
            ->get();

        return view('dosen.courses.index', compact('courses'));
    }

    public function create(): View
    {
        Gate::authorize('create', Course::class);

        return view('dosen.courses.form', ['course' => new Course()]);
    }

    public function store(CourseRequest $request): RedirectResponse
    {
        $course = $request->user()->coursesTaught()->create($request->validated());

        return redirect()->route('dosen.courses.show', $course)->with('success', 'Mata kuliah ditambahkan.');
    }

    public function show(Course $course): View
    {
        Gate::authorize('manage', $course);

        $assignments = $course->assignments()
            ->withCount('submissions')
            ->orderByRaw('due_date IS NULL, due_date DESC')
            ->get();

        $students = $course->mahasiswa()->orderBy('name')->get(['users.id', 'users.name', 'users.email']);

        $availableStudents = User::where('role', User::ROLE_MAHASISWA)
            ->whereNotIn('id', $students->pluck('id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('dosen.courses.show', compact('course', 'assignments', 'students', 'availableStudents'));
    }

    public function edit(Course $course): View
    {
        Gate::authorize('manage', $course);

        return view('dosen.courses.form', compact('course'));
    }

    public function update(CourseRequest $request, Course $course): RedirectResponse
    {
        $course->update($request->validated());

        return redirect()->route('dosen.courses.show', $course)->with('success', 'Data mata kuliah disimpan.');
    }

    public function destroy(Course $course): RedirectResponse
    {
        Gate::authorize('manage', $course);

        $submissions = Submission::whereIn('assignment_id', $course->assignments()->select('id'))->get(['file_path']);

        $course->delete();
        $this->submissionService->deleteFilesOf($submissions);

        return redirect()->route('dosen.courses.index')->with('success', "Mata kuliah {$course->code} dihapus.");
    }
}
