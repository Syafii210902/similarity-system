<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SimilarityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected SimilarityService $similarityService
    ) {}

    public function handleSimilarityResult(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Callback analisis diterima', [
            'assignment_id' => $payload['assignment_id'] ?? null,
            'analysis_run_id' => $payload['analysis_run_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'pairs' => count($payload['results'] ?? []),
            'ai_detections' => count($payload['ai_detections'] ?? []),
        ]);

        try {
            $this->similarityService->handleWebhookCallback($payload);

            return response()->json(['status' => 'acknowledged'], 200);
        } catch (\Throwable $e) {
            Log::error("Webhook Storage Error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}