<?php

namespace App\Repositories\Eloquent;

use App\Models\AiDetectionResult;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Repositories\Contracts\SimilarityRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Menyimpan payload callback FastAPI. Semua nilai dinormalisasi agar output LLM
 * yang tidak sesuai format tidak menggagalkan penyimpanan (mis. enum MySQL).
 */
class SimilarityRepository implements SimilarityRepositoryInterface
{
    public const ACTIONS = ['PERIKSA_MANUAL', 'SKIP_KOREKSI', 'AMAN'];
    public const MATCH_TYPES = ['VERBATIM_COPY', 'PARAPHRASED'];
    public const AI_VERDICTS = ['HUMAN_WRITTEN', 'MIXED_AI', 'LIKELY_AI', 'ERROR'];

    public function saveResults(array $data): void
    {
        $assignmentId = (int) $data['assignment_id'];
        $validIds = $this->submissionIdsOf($assignmentId);

        foreach ($data['results'] ?? [] as $item) {
            $a = (int) ($item['doc_a']['submission_id'] ?? 0);
            $b = (int) ($item['doc_b']['submission_id'] ?? 0);

            if (!isset($validIds[$a], $validIds[$b])) {
                Log::warning("Abaikan pasangan {$a}-{$b}: submission bukan milik assignment {$assignmentId}.");
                continue;
            }

            $llm = is_array($item['llm_analysis'] ?? null) ? $item['llm_analysis'] : [];
            $isFlagged = (bool) ($item['is_flagged'] ?? false);

            SimilarityResult::create([
                'assignment_id' => $assignmentId,
                'submission_a_id' => $a,
                'submission_b_id' => $b,
                'similarity_score' => $this->clamp((float) ($item['similarity_score'] ?? 0), -1, 1),
                'layer_scores' => $this->normalizeLayerScores($item['layer_scores'] ?? null),
                'is_flagged' => $isFlagged,
                'verdict' => mb_substr((string) ($llm['verdict'] ?? ($isFlagged ? 'Tidak ada keterangan' : 'Di bawah ambang batas')), 0, 255),
                'action_recommendation' => $this->normalizeAction($llm['action_recommendation'] ?? null, $isFlagged),
                'summary' => $llm['summary'] ?? null,
                'matched_segments' => $this->normalizeSegments($llm['matched_segments'] ?? []),
            ]);
        }
    }

    public function getResultsByAssignment(int $assignmentId): Collection
    {
        return SimilarityResult::where('assignment_id', $assignmentId)
            ->orderBy('similarity_score', 'desc')
            ->get();
    }

    public function deleteOldResults(int $assignmentId): void
    {
        SimilarityResult::where('assignment_id', $assignmentId)->delete();
        AiDetectionResult::where('assignment_id', $assignmentId)->delete();
    }

    public function saveAiDetectionResults(int $assignmentId, array $aiDetections): void
    {
        $validIds = $this->submissionIdsOf($assignmentId);

        foreach ($aiDetections as $aiData) {
            $subId = (int) ($aiData['submission_id'] ?? 0);

            if (!isset($validIds[$subId])) {
                Log::warning("Abaikan AI Detection: submission {$subId} bukan milik assignment {$assignmentId}.");
                continue;
            }

            $verdict = strtoupper((string) ($aiData['verdict'] ?? ''));
            $probability = is_numeric($aiData['ai_probability'] ?? null)
                ? $this->clamp((float) $aiData['ai_probability'], 0, 1)
                : null;

            // Verdict tidak dikenal atau probabilitas hilang → ERROR, bukan diasumsikan HUMAN_WRITTEN.
            if (!in_array($verdict, self::AI_VERDICTS, true) || ($probability === null && $verdict !== 'ERROR')) {
                $verdict = 'ERROR';
            }

            AiDetectionResult::create([
                'assignment_id' => $assignmentId,
                'submission_id' => $subId,
                'ai_probability' => $verdict === 'ERROR' ? null : $probability,
                'verdict' => $verdict,
                'analysis_summary' => $aiData['analysis_summary'] ?? null,
                'flagged_patterns' => array_values(array_filter((array) ($aiData['flagged_patterns'] ?? []), 'is_string')),
            ]);
        }
    }

    /** @return array<int, true> */
    private function submissionIdsOf(int $assignmentId): array
    {
        return Submission::where('assignment_id', $assignmentId)->pluck('id')->flip()->map(fn () => true)->all();
    }

    /**
     * Skor per lapis embedding; null untuk hasil lama (sebelum multi-layer).
     */
    private function normalizeLayerScores(mixed $scores): ?array
    {
        if (!is_array($scores)) {
            return null;
        }

        $normalized = [];
        foreach (['document', 'passage', 'sentence'] as $layer) {
            if (is_numeric($scores[$layer] ?? null)) {
                $normalized[$layer] = round($this->clamp((float) $scores[$layer], -1, 1), 4);
            }
        }

        return $normalized ?: null;
    }

    private function normalizeAction(mixed $value, bool $isFlagged): string
    {
        $action = strtoupper(trim((string) $value));

        if (in_array($action, self::ACTIONS, true)) {
            return $action;
        }

        return $isFlagged ? 'PERIKSA_MANUAL' : 'AMAN';
    }

    private function normalizeSegments(mixed $segments): array
    {
        if (!is_array($segments)) {
            return [];
        }

        return collect($segments)
            ->filter(fn ($seg) => is_array($seg))
            ->values()
            ->map(function (array $seg, int $i) {
                $type = strtoupper(str_replace([' ', '-'], '_', trim((string) ($seg['match_type'] ?? ''))));
                if (!in_array($type, self::MATCH_TYPES, true)) {
                    $type = preg_match('/VERBATIM|COPY|EXACT/', $type) ? 'VERBATIM_COPY' : 'PARAPHRASED';
                }

                return [
                    'segment_id' => $i + 1,
                    'text_doc_a' => (string) ($seg['text_doc_a'] ?? ''),
                    'text_doc_b' => (string) ($seg['text_doc_b'] ?? ''),
                    'match_type' => $type,
                ];
            })
            ->all();
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
