<?php

namespace App\Services;

use App\Models\AiDetectionResult;
use App\Models\AiLabel;
use App\Models\Assignment;
use App\Models\PairLabel;
use App\Models\SimilarityResult;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Membandingkan prediksi sistem dengan label manual dosen (ground truth).
 *
 * Kemiripan (per pasangan):
 *   - Tahap 1 saja       : positif jika skor gabungan >= ambang run (is_flagged)
 *   - Tahap 1 + Tahap 2  : positif jika is_flagged DAN LLM tidak merekomendasikan "AMAN"
 *   - Sweep ambang       : skor gabungan & tiap lapis (dokumen/passage/kalimat) pada ambang 0,50–0,95
 * Deteksi AI (per dokumen):
 *   - Ketat   : positif jika LIKELY_AI
 *   - Longgar : positif jika LIKELY_AI atau MIXED_AI
 *   (hasil ERROR tidak dihitung)
 */
class EvaluationService
{
    public const LAYERS = ['combined' => 'Gabungan', 'document' => 'Dokumen', 'passage' => 'Passage', 'sentence' => 'Kalimat'];

    /** @return Collection<int, int> */
    public function assignmentIdsFor(User $dosen, ?Assignment $assignment = null): Collection
    {
        if ($assignment) {
            return collect([$assignment->id]);
        }

        return Assignment::whereIn('course_id', $dosen->coursesTaught()->select('id'))->pluck('id');
    }

    /**
     * Baris pasangan yang sudah divalidasi dosen, lengkap dengan skor & prediksi.
     *
     * @return Collection<int, array>
     */
    public function labeledPairs(Collection $assignmentIds): Collection
    {
        $labels = PairLabel::whereIn('assignment_id', $assignmentIds)->get()->keyBy(fn (PairLabel $l) => $l->key());

        return SimilarityResult::whereIn('assignment_id', $assignmentIds)
            ->with(['submissionA.user:id,name', 'submissionB.user:id,name', 'assignment:id,title'])
            ->get()
            ->map(function (SimilarityResult $r) use ($labels) {
                $label = $labels->get(PairLabel::keyFor($r->submission_a_id, $r->submission_b_id));
                if (!$label) {
                    return null;
                }

                $layers = $r->layer_scores ?? [];

                return [
                    'result' => $r,
                    'actual' => $label->is_plagiarism,
                    'scores' => [
                        'combined' => (float) $r->similarity_score,
                        'document' => $layers['document'] ?? null,
                        'passage' => $layers['passage'] ?? null,
                        'sentence' => $layers['sentence'] ?? null,
                    ],
                    'tier1' => $r->is_flagged,
                    'tier12' => $r->is_flagged && $r->action_recommendation !== 'AMAN',
                ];
            })
            ->filter()
            ->values();
    }

    /** @return Collection<int, array> */
    public function labeledAiResults(Collection $assignmentIds): Collection
    {
        $labels = AiLabel::whereIn('assignment_id', $assignmentIds)->get()->keyBy('submission_id');

        return AiDetectionResult::whereIn('assignment_id', $assignmentIds)
            ->with(['submission.user:id,name', 'submission.assignment:id,title'])
            ->get()
            ->map(function (AiDetectionResult $r) use ($labels) {
                $label = $labels->get($r->submission_id);

                return $label ? ['result' => $r, 'actual' => $label->is_ai] : null;
            })
            ->filter()
            ->values();
    }

    public function summarize(User $dosen, ?Assignment $assignment = null): array
    {
        $ids = $this->assignmentIdsFor($dosen, $assignment);
        $pairs = $this->labeledPairs($ids);
        $ai = $this->labeledAiResults($ids);
        $aiValid = $ai->reject(fn ($row) => $row['result']->verdict === 'ERROR');

        $thresholds = collect(range(50, 95, 5))->map(fn ($t) => $t / 100);
        $sweep = [];
        $best = [];
        foreach (array_keys(self::LAYERS) as $layer) {
            $rows = $pairs->filter(fn ($row) => $row['scores'][$layer] !== null);
            $sweep[$layer] = $thresholds->mapWithKeys(fn ($t) => [
                (string) $t => $this->confusion($rows, fn ($row) => $row['scores'][$layer] >= $t),
            ])->all();
            $best[$layer] = collect($sweep[$layer])
                ->filter(fn ($m) => $m['f1'] !== null)
                ->sortByDesc('f1')
                ->keys()
                ->first();
        }

        return [
            'pairCount' => $pairs->count(),
            'pairPositives' => $pairs->where('actual', true)->count(),
            'unlabeledPairs' => SimilarityResult::whereIn('assignment_id', $ids)->count() - $pairs->count(),
            'tier1' => $this->confusion($pairs, fn ($row) => $row['tier1']),
            'tier12' => $this->confusion($pairs, fn ($row) => $row['tier12']),
            'thresholds' => $thresholds->all(),
            'sweep' => $sweep,
            'best' => $best,
            'aiCount' => $ai->count(),
            'aiErrors' => $ai->count() - $aiValid->count(),
            'aiPositives' => $aiValid->where('actual', true)->count(),
            'unlabeledAi' => AiDetectionResult::whereIn('assignment_id', $ids)->count() - $ai->count(),
            'aiStrict' => $this->confusion($aiValid, fn ($row) => $row['result']->verdict === 'LIKELY_AI'),
            'aiLenient' => $this->confusion($aiValid, fn ($row) => in_array($row['result']->verdict, ['LIKELY_AI', 'MIXED_AI'], true)),
        ];
    }

    /**
     * Confusion matrix + precision, recall, F1, akurasi. Nilai null jika pembagi nol.
     */
    public function confusion(Collection $rows, callable $predict): array
    {
        $tp = $fp = $fn = $tn = 0;
        foreach ($rows as $row) {
            $predicted = (bool) $predict($row);
            match (true) {
                $predicted && $row['actual'] => $tp++,
                $predicted && !$row['actual'] => $fp++,
                !$predicted && $row['actual'] => $fn++,
                default => $tn++,
            };
        }

        $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : null;
        $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : null;
        $f1 = $precision !== null && $recall !== null && $precision + $recall > 0
            ? 2 * $precision * $recall / ($precision + $recall)
            : null;
        $n = $tp + $fp + $fn + $tn;

        return [
            'tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn, 'n' => $n,
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
            'accuracy' => $n > 0 ? ($tp + $tn) / $n : null,
        ];
    }
}
