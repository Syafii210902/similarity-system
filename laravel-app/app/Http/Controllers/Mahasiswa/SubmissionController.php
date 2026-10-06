<?php

namespace App\Http\Controllers\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mahasiswa\SubmissionFileRequest;
use App\Models\Assignment;
use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function __construct(
        protected SubmissionService $submissionService
    ) {}

    /**
     * Halaman detail tugas + status pengumpulan milik mahasiswa yang login.
     */
    public function show(Request $request, Assignment $assignment): View
    {
        Gate::authorize('view', $assignment);

        $assignment->load('course.dosen:id,name');
        $submission = $this->submissionService->findFor($assignment, $request->user());

        return view('mahasiswa.assignments.show', [
            'assignment' => $assignment,
            'submission' => $submission,
            'fileSize' => $submission ? $this->submissionService->fileSize($submission) : null,
            'canSubmit' => $request->user()->can('submit', $assignment),
        ]);
    }

    public function store(SubmissionFileRequest $request, Assignment $assignment): RedirectResponse
    {
        if ($this->submissionService->findFor($assignment, $request->user())) {
            return back()->withErrors(['file' => 'Anda sudah mengumpulkan tugas ini. Gunakan "Ganti File" untuk memperbarui.']);
        }

        $this->submissionService->store($assignment, $request->user(), $request->file('file'));

        return redirect()
            ->route('mahasiswa.assignments.show', $assignment)
            ->with('success', 'Tugas berhasil dikumpulkan.');
    }

    public function update(SubmissionFileRequest $request, Assignment $assignment): RedirectResponse
    {
        $submission = $this->findOwnSubmissionOrFail($request, $assignment);

        $this->submissionService->replace($submission, $request->file('file'));

        return redirect()
            ->route('mahasiswa.assignments.show', $assignment)
            ->with('success', 'File pengumpulan berhasil diperbarui.');
    }

    public function destroy(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('submit', $assignment);

        $submission = $this->findOwnSubmissionOrFail($request, $assignment);
        $this->submissionService->delete($submission);

        return redirect()
            ->route('mahasiswa.assignments.show', $assignment)
            ->with('success', 'Pengumpulan berhasil dihapus.');
    }

    public function download(Request $request, Assignment $assignment): StreamedResponse
    {
        Gate::authorize('view', $assignment);

        $submission = $this->findOwnSubmissionOrFail($request, $assignment);
        abort_unless($this->submissionService->fileExists($submission), 404, 'File tidak ditemukan di penyimpanan.');

        return $this->submissionService->download($submission);
    }

    private function findOwnSubmissionOrFail(Request $request, Assignment $assignment): Submission
    {
        $submission = $this->submissionService->findFor($assignment, $request->user());
        abort_if($submission === null, 404, 'Anda belum mengumpulkan tugas ini.');

        return $submission;
    }
}
