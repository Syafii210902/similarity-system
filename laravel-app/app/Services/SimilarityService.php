<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Repositories\Contracts\SimilarityRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SimilarityService
{
    public function __construct(
        protected SimilarityRepositoryInterface $similarityRepo
    ) {}

    /**
     * Callback dari FastAPI (status "completed" atau "failed").
     */
    public function handleWebhookCallback(array $payload): void
    {
        $assignmentId = $payload['assignment_id'] ?? null;
        $run = $this->resolveRun($payload);

        if ($run && $run->assignment_id !== (int) $assignmentId) {
            throw new \InvalidArgumentException("Run #{$run->id} bukan milik assignment {$assignmentId}.");
        }
        $status = $payload['status'] ?? null;

        if ($status !== 'completed') {
            $message = $payload['error'] ?? 'Layanan analisis melaporkan kegagalan tanpa keterangan.';
            Log::error("Analisis gagal untuk assignment {$assignmentId}: {$message}");

            $run?->update([
                'status' => AnalysisRun::STATUS_FAILED,
                'error_message' => $message,
                'metrics' => $payload['metrics'] ?? null,
                'finished_at' => now(),
            ]);

            return;
        }

        Log::info("Memproses hasil webhook untuk Assignment ID: {$assignmentId}");

        DB::transaction(function () use ($payload, $assignmentId, $run) {
            // Hasil analisis sebelumnya untuk tugas ini diganti hasil terbaru.
            $this->similarityRepo->deleteOldResults($assignmentId);

            if (isset($payload['results']) && is_array($payload['results'])) {
                $this->similarityRepo->saveResults($payload);
            }

            if (isset($payload['ai_detections']) && is_array($payload['ai_detections'])) {
                $this->similarityRepo->saveAiDetectionResults($assignmentId, $payload['ai_detections']);
            }

            $run?->update([
                'status' => AnalysisRun::STATUS_COMPLETED,
                'embedding_model' => $payload['embedding_model'] ?? null,
                'documents_processed' => $payload['total_documents'] ?? null,
                'pairs_evaluated' => $payload['total_pairs_evaluated'] ?? null,
                'pairs_flagged' => $payload['flagged_count'] ?? null,
                'metrics' => $payload['metrics'] ?? null,
                'error_message' => null,
                'finished_at' => now(),
            ]);
        });
    }

    /**
     * Cocokkan callback dengan run: utamakan analysis_run_id, fallback ke run aktif terakhir.
     */
    private function resolveRun(array $payload): ?AnalysisRun
    {
        if (!empty($payload['analysis_run_id'])) {
            return AnalysisRun::find($payload['analysis_run_id']);
        }

        return AnalysisRun::where('assignment_id', $payload['assignment_id'] ?? 0)
            ->whereIn('status', AnalysisRun::ACTIVE_STATUSES)
            ->latest()
            ->first();
    }
}
