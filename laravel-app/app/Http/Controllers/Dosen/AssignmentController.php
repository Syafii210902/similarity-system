<?php

namespace App\Http\Controllers\Dosen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dosen\AssignmentRequest;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Submission;
use App\Services\AnalysisService;
use App\Services\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentController extends Controller
{
    public function __construct(
        protected SubmissionService $submissionService,
        protected AnalysisService $analysisService
    ) {}

    public function create(Course $course): View
    {
        Gate::authorize('manage', $course);

        return view('dosen.assignments.form', ['course' => $course, 'assignment' => new Assignment()]);
    }

    public function store(AssignmentRequest $request, Course $course): RedirectResponse
    {
        $assignment = $course->assignments()->create($request->validated());

        return redirect()->route('dosen.assignments.show', $assignment)->with('success', 'Tugas dibuat.');
    }

    /**
     * Rekap pengumpulan: semua mahasiswa terdaftar + pengumpul yang sudah tidak terdaftar.
     */
    public function show(Assignment $assignment): View
    {
        Gate::authorize('manage', $assignment);

        $assignment->load('course');

        $submissions = $assignment->submissions()->with('user:id,name,email')->get()->keyBy('user_id');
        $students = $assignment->course->mahasiswa()->orderBy('name')->get(['users.id', 'users.name', 'users.email']);

        $rows = $students->map(fn ($student) => [
            'student' => $student,
            'submission' => $submissions->get($student->id),
            'enrolled' => true,
        ]);

        // Filter berdasarkan user_id (bukan except(): pada Eloquent Collection, except() memakai primary key model).
        $enrolledIds = $students->pluck('id')->all();
        $formerStudents = $submissions
            ->reject(fn (Submission $s) => in_array($s->user_id, $enrolledIds, true))
            ->map(fn (Submission $s) => ['student' => $s->user, 'submission' => $s, 'enrolled' => false]);

        ['ready' => $ready, 'missing' => $missing] = $this->analysisService->partitionSubmissions($assignment);

        return view('dosen.assignments.show', [
            'assignment' => $assignment,
            'rows' => $rows->concat($formerStudents->values()),
            'submittedCount' => $submissions->count(),
            'enrolledCount' => $students->count(),
            'readyCount' => $ready->count(),
            'missingFiles' => $missing,
            'blockingReason' => $this->analysisService->blockingReason($assignment, $ready->count()),
            'runs' => $assignment->analysisRuns()->with('triggeredBy:id,name')->latest()->limit(5)->get(),
            'defaultThreshold' => config('services.analysis.default_threshold'),
        ]);
    }

    public function edit(Assignment $assignment): View
    {
        Gate::authorize('manage', $assignment);

        return view('dosen.assignments.form', ['course' => $assignment->course, 'assignment' => $assignment]);
    }

    public function update(AssignmentRequest $request, Assignment $assignment): RedirectResponse
    {
        $assignment->update($request->validated());

        return redirect()->route('dosen.assignments.show', $assignment)->with('success', 'Perubahan tugas disimpan.');
    }

    public function destroy(Assignment $assignment): RedirectResponse
    {
        Gate::authorize('manage', $assignment);

        $course = $assignment->course;
        $submissions = $assignment->submissions()->get(['file_path']);

        $assignment->delete();
        $this->submissionService->deleteFilesOf($submissions);

        return redirect()->route('dosen.courses.show', $course)->with('success', 'Tugas beserta pengumpulannya dihapus.');
    }

    public function close(Assignment $assignment): RedirectResponse
    {
        Gate::authorize('manage', $assignment);

        if (!$assignment->isClosedManually()) {
            $assignment->update(['closed_at' => now()]);
        }

        return back()->with('success', 'Pengumpulan ditutup. Mahasiswa tidak dapat lagi mengunggah atau mengubah berkas.');
    }

    public function reopen(Assignment $assignment): RedirectResponse
    {
        Gate::authorize('manage', $assignment);

        $assignment->update(['closed_at' => null]);

        $message = $assignment->isPastDue()
            ? 'Penutupan manual dibatalkan, tetapi tenggat sudah lewat. Ubah tenggat agar mahasiswa dapat mengumpulkan lagi.'
            : 'Pengumpulan dibuka kembali.';

        return back()->with('success', $message);
    }

    public function download(Submission $submission): StreamedResponse
    {
        Gate::authorize('manage', $submission->assignment);
        abort_unless($this->submissionService->fileExists($submission), 404, 'Berkas tidak ditemukan di penyimpanan.');

        return $this->submissionService->download($submission);
    }
}
