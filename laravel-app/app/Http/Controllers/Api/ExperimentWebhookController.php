<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExperimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Callback FastAPI untuk eksperimen Lab Pengujian (analysis_run_id = id experiment_runs).
 */
class ExperimentWebhookController extends Controller
{
    public function __construct(
        protected ExperimentService $experiments
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Callback eksperimen diterima', [
            'run_id' => $payload['analysis_run_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'pairs' => count($payload['results'] ?? []),
        ]);

        try {
            $this->experiments->handleCallback($payload);

            return response()->json(['status' => 'acknowledged']);
        } catch (\Throwable $e) {
            Log::error('Experiment webhook error: ' . $e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /** Callback uji konsistensi deteksi AI: per dokumen ("item"), lalu "completed" atau "stopped". */
    public function handleAiRepeat(Request $request): JsonResponse
    {
        try {
            $this->experiments->handleAiRepeatCallback($request->all());

            return response()->json(['status' => 'acknowledged']);
        } catch (\Throwable $e) {
            Log::error('Experiment AI repeat webhook error: ' . $e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
