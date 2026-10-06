<?php

namespace App\Repositories\Eloquent;

use App\Models\AiDetectionResult;
use App\Repositories\Contracts\AiDetectionRepositoryInterface;

class AiDetectionRepository implements AiDetectionRepositoryInterface
{
    public function saveAiDetectionResults(int $assignmentId, array $aiDetections): void
    {
        // Hapus hasil lama untuk assignment_id tersebut jika pemicuan ulang
        AiDetectionResult::where('assignment_id', $assignmentId)->delete();

        $allowedVerdicts = ['HUMAN_WRITTEN', 'LIKELY_AI', 'MIXED_AI'];

        foreach ($aiDetections as $aiData) {
            $rawVerdict = strtoupper($aiData['verdict'] ?? 'HUMAN_WRITTEN');
            $verdict = in_array($rawVerdict, $allowedVerdicts) ? $rawVerdict : 'HUMAN_WRITTEN';

            AiDetectionResult::create([
                'assignment_id'    => $assignmentId,
                'submission_id'    => (int) $aiData['submission_id'],
                'ai_probability'   => (float) $aiData['ai_probability'],
                'verdict'          => $verdict,
                'analysis_summary' => $aiData['analysis_summary'] ?? null,
                'flagged_patterns' => $aiData['flagged_patterns'] ?? [],
            ]);
        }
    }
}