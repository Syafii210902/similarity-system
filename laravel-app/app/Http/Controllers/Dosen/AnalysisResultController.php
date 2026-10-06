<?php

namespace App\Http\Controllers\Dosen;

use App\Http\Controllers\Controller;
use App\Models\AiDetectionResult;
use App\Models\AiLabel;
use App\Models\AnalysisRun;
use App\Models\Assignment;
use App\Models\PairLabel;
use App\Models\SimilarityResult;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AnalysisResultController extends Controller
{
    /**
     * Ringkasan hasil analisis terakhir: matriks & daftar pasangan + deteksi AI per dokumen.
     */
    public function index(Assignment $assignment): View
    {
        Gate::authorize('manage', $assignment);

        $assignment->load('course');

        $pairs = $assignment->similarityResults()
            ->with(['submissionA.user:id,name', 'submissionB.user:id,name'])
            ->orderByDesc('similarity_score')
            ->get();

        $aiResults = $assignment->aiDetectionResults()
            ->with('submission.user:id,name')
            ->get()
            // ERROR di akhir, sisanya dari probabilitas tertinggi.
            ->sortBy(fn (AiDetectionResult $r) => $r->verdict === 'ERROR' ? 2 : 1 - (float) $r->ai_probability)
            ->values();

        // Dokumen yang muncul di hasil (untuk sumbu matriks), urut nama mahasiswa.
        $documents = $pairs
            ->flatMap(fn (SimilarityResult $r) => [$r->submissionA, $r->submissionB])
            ->filter()
            ->unique('id')
            ->sortBy(fn ($s) => mb_strtolower($s->user?->name ?? ''))
            ->values();

        $scores = $pairs->mapWithKeys(fn (SimilarityResult $r) => [
            min($r->submission_a_id, $r->submission_b_id) . '-' . max($r->submission_a_id, $r->submission_b_id) => $r,
        ]);

        $run = $assignment->analysisRuns()
            ->where('status', AnalysisRun::STATUS_COMPLETED)
            ->latest('finished_at')
            ->first();

        return view('dosen.results.index', [
            'assignment' => $assignment,
            'pairs' => $pairs,
            'aiResults' => $aiResults,
            'documents' => $documents,
            'scores' => $scores,
            'run' => $run,
            'threshold' => $run?->threshold ?? config('services.analysis.default_threshold'),
            'pairLabels' => PairLabel::where('assignment_id', $assignment->id)->get()->keyBy(fn (PairLabel $l) => $l->key()),
            'aiLabels' => AiLabel::where('assignment_id', $assignment->id)->pluck('is_ai', 'submission_id'),
        ]);
    }

    public function pair(Assignment $assignment, SimilarityResult $result): View
    {
        Gate::authorize('manage', $assignment);
        abort_unless($result->assignment_id === $assignment->id, 404);

        $assignment->load('course');
        $result->load(['submissionA.user:id,name', 'submissionB.user:id,name']);

        $aiBySubmission = $assignment->aiDetectionResults()
            ->whereIn('submission_id', [$result->submission_a_id, $result->submission_b_id])
            ->get()
            ->keyBy('submission_id');

        $label = PairLabel::where([
            'submission_low_id' => min($result->submission_a_id, $result->submission_b_id),
            'submission_high_id' => max($result->submission_a_id, $result->submission_b_id),
        ])->with('labeledBy:id,name')->first();

        return view('dosen.results.pair', compact('assignment', 'result', 'aiBySubmission', 'label'));
    }
}
