<?php

namespace App\Http\Controllers\Dosen;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AnalysisRun;
use App\Services\AnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class AnalysisController extends Controller
{
    public function __construct(
        protected AnalysisService $analysisService
    ) {}

    public function store(Request $request, Assignment $assignment): RedirectResponse
    {
        Gate::authorize('manage', $assignment);

        $min = config('services.analysis.min_threshold');
        $max = config('services.analysis.max_threshold');
        $validated = $request->validate(
            ['threshold' => ['required', 'numeric', "between:{$min},{$max}"]],
            ['threshold.between' => "Ambang batas harus antara {$min} dan {$max}."]
        );

        try {
            $run = $this->analysisService->start($assignment, $request->user(), (float) $validated['threshold']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $run->status === AnalysisRun::STATUS_FAILED
            ? back()->with('error', $run->error_message)
            : back()->with('success', "Analisis dimulai untuk {$run->documents_sent} berkas. Halaman ini akan diperbarui otomatis saat selesai.");
    }

    /**
     * Dipanggil berkala oleh halaman tugas selama analisis berjalan.
     */
    public function status(Assignment $assignment): JsonResponse
    {
        Gate::authorize('manage', $assignment);

        $this->analysisService->expireStaleRuns($assignment);
        $run = $assignment->latestAnalysisRun()->first();

        return response()->json([
            'run_id' => $run?->id,
            'status' => $run?->status,
        ]);
    }
}
