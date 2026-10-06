<?php

namespace App\Services;

use App\Models\ExperimentDocument;
use App\Models\ExperimentPairScore;
use App\Models\ExperimentRun;
use Illuminate\Support\Collection;

/**
 * Evaluasi eksperimen terhadap label otomatis korpus uji.
 *
 * Label pasangan: plagiat jika kedua dokumen berasal dari "keluarga" yang sama
 * (sumber akar sama, mengikuti rantai source_document_id).
 *
 * Semua perhitungan memakai skor per lapis yang tersimpan, sehingga bobot & ambang
 * dapat diubah tanpa menjalankan ulang FastAPI/LLM.
 */
class ExperimentEvaluator
{
    public const LAYERS = ['document' => 'Dokumen', 'passage' => 'Passage', 'sentence' => 'Kalimat'];

    /** Ambang yang diuji pada sweep & optimasi. */
    public const THRESHOLDS = [0.30, 0.35, 0.40, 0.45, 0.50, 0.55, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90, 0.95];

    public const FOLDS = 5;
    public const SEED = 42;

    public const PAIR_CATEGORIES = [
        'VERBATIM_COPY' => 'Salinan langsung',
        'PARAPHRASE_MANUAL' => 'Parafrase manual',
        'PARAPHRASE_AI' => 'Parafrase oleh AI',
        'NEG_INDEPENDENT' => 'Bukan plagiat – melibatkan esai independen',
        'NEG_AI' => 'Bukan plagiat – melibatkan teks AI',
        'NEG_OTHER' => 'Bukan plagiat – lainnya',
    ];

    public function __construct(
        protected EvaluationService $metrics
    ) {}

    /**
     * Baris pasangan dengan label & kategori: ['pair', 'actual', 'category', 'scores' => [document, passage, sentence], 'combined', ...]
     *
     * @return Collection<int, array>
     */
    public function rows(ExperimentRun $run): Collection
    {
        $documents = $run->dataset->documents()->get()->keyBy('id');
        $root = fn (int $id) => $this->rootOf($id, $documents);

        return $run->pairScores()->get()->map(function (ExperimentPairScore $pair) use ($documents, $root) {
            $a = $documents->get($pair->document_low_id);
            $b = $documents->get($pair->document_high_id);
            $actual = $root($a->id) === $root($b->id);

            return [
                'pair' => $pair,
                'a' => $a,
                'b' => $b,
                'actual' => $actual,
                'category' => $this->pairCategory($a, $b, $actual),
                'scores' => [
                    'document' => $pair->document_score,
                    'passage' => $pair->passage_score,
                    'sentence' => $pair->sentence_score,
                ],
                'combined' => $pair->combined_score,
                'llm_checked' => $pair->llm_checked,
                'llm_positive' => $pair->llm_checked && $pair->llm_action !== 'AMAN',
            ];
        });
    }

    public function score(array $row, array $weights): float
    {
        $total = array_sum($weights) ?: 1;

        return ($weights[0] * $row['scores']['document'] + $weights[1] * $row['scores']['passage'] + $weights[2] * $row['scores']['sentence']) / $total;
    }

    public function evaluate(Collection $rows, array $weights, float $threshold): array
    {
        return $this->metrics->confusion($rows, fn ($row) => $this->score($row, $weights) >= $threshold - 1e-9);
    }

    /**
     * F1 terhadap ambang untuk tiap lapis + gabungan (bobot run).
     */
    public function sweep(Collection $rows, array $runWeights): array
    {
        $series = ['combined' => $runWeights, 'document' => [1, 0, 0], 'passage' => [0, 1, 0], 'sentence' => [0, 0, 1]];
        $out = [];
        foreach ($series as $key => $weights) {
            foreach (self::THRESHOLDS as $t) {
                $m = $this->evaluate($rows, $weights, $t);
                // Tidak ada pasangan yang ditandai padahal ada plagiat: recall 0 → F1 = 0 (bukan tak terdefinisi).
                if ($m['f1'] === null && $m['tp'] + $m['fn'] > 0) {
                    $m['f1'] = 0.0;
                }
                $out[$key][(string) $t] = $m;
            }
        }

        return $out;
    }

    /**
     * Ablation: tiap konfigurasi bobot dievaluasi (a) pada ambang terbaik in-sample, dan
     * (b) dengan 5-fold stratified cross-validation (ambang — dan untuk "Optimasi", juga bobot —
     * dipilih di data latih, diuji di data uji).
     */
    public function ablation(Collection $rows, array $runWeights): array
    {
        $configs = [
            ['label' => 'Lapis dokumen saja', 'weights' => [1, 0, 0]],
            ['label' => 'Lapis passage saja', 'weights' => [0, 1, 0]],
            ['label' => 'Lapis kalimat saja', 'weights' => [0, 0, 1]],
            ['label' => 'Bobot sama rata', 'weights' => [1 / 3, 1 / 3, 1 / 3]],
            ['label' => 'Bobot run (' . implode(' / ', array_map(fn ($w) => rtrim(rtrim(number_format($w, 2, ',', ''), '0'), ','), $runWeights)) . ')', 'weights' => $runWeights],
            ['label' => 'Bobot hasil optimasi', 'weights' => null],
        ];

        $folds = $this->folds($rows);

        return array_map(function (array $config) use ($rows, $folds) {
            $candidates = $config['weights'] ? [$config['weights']] : $this->weightGrid();
            $best = $this->bestParams($rows, $candidates);
            $cv = $this->crossValidate($rows, $folds, $candidates);

            return $config + [
                'best_weights' => $best['weights'],
                'best_threshold' => $best['threshold'],
                'in_sample' => $best['metrics'],
                'cv' => $cv,
            ];
        }, $configs);
    }

    /**
     * Tingkat deteksi per kategori pasangan (positif) dan tingkat salah tuduh (negatif),
     * memakai keputusan run: Tahap 1 = skor gabungan ≥ ambang run; Tahap 1+2 = juga dikonfirmasi LLM.
     */
    public function perCategory(Collection $rows, float $threshold, bool $tier2Enabled): array
    {
        $out = [];
        foreach (self::PAIR_CATEGORIES as $key => $label) {
            $group = $rows->where('category', $key);
            if ($group->isEmpty()) {
                continue;
            }
            $tier1 = $group->filter(fn ($r) => $r['combined'] >= $threshold - 1e-9)->count();
            $tier12 = $tier2Enabled ? $group->filter(fn ($r) => $r['combined'] >= $threshold - 1e-9 && $r['llm_positive'])->count() : null;

            $out[] = [
                'key' => $key,
                'label' => $label,
                'positive' => str_starts_with($key, 'NEG_') === false,
                'n' => $group->count(),
                'tier1' => $tier1,
                'tier12' => $tier12,
                'avg' => [
                    'document' => $group->avg(fn ($r) => $r['scores']['document']),
                    'passage' => $group->avg(fn ($r) => $r['scores']['passage']),
                    'sentence' => $group->avg(fn ($r) => $r['scores']['sentence']),
                    'combined' => $group->avg('combined'),
                ],
            ];
        }

        return $out;
    }

    public function tierComparison(Collection $rows, float $threshold, bool $tier2Enabled): array
    {
        return [
            'tier1' => $this->metrics->confusion($rows, fn ($r) => $r['combined'] >= $threshold - 1e-9),
            'tier12' => $tier2Enabled
                ? $this->metrics->confusion($rows, fn ($r) => $r['combined'] >= $threshold - 1e-9 && $r['llm_positive'])
                : null,
        ];
    }

    public function aiEvaluation(ExperimentRun $run): array
    {
        $scores = $run->aiScores()->with('document')->get();
        $valid = $scores->where('verdict', '!=', 'ERROR')->map(fn ($s) => [
            'score' => $s,
            'actual' => $s->document->isAiWritten(),
        ]);

        $perCategory = [];
        foreach (ExperimentDocument::CATEGORIES as $key => $label) {
            $group = $scores->filter(fn ($s) => $s->document->category === $key && $s->verdict !== 'ERROR');
            if ($group->isNotEmpty()) {
                $perCategory[] = [
                    'label' => $label,
                    'ai_written' => in_array($key, ExperimentDocument::AI_WRITTEN, true),
                    'n' => $group->count(),
                    'avg_probability' => $group->avg('ai_probability'),
                    'likely_ai' => $group->where('verdict', 'LIKELY_AI')->count(),
                ];
            }
        }

        return [
            'count' => $scores->count(),
            'errors' => $scores->where('verdict', 'ERROR')->count(),
            'strict' => $this->metrics->confusion($valid, fn ($r) => $r['score']->verdict === 'LIKELY_AI'),
            'lenient' => $this->metrics->confusion($valid, fn ($r) => in_array($r['score']->verdict, ['LIKELY_AI', 'MIXED_AI'], true)),
            'perCategory' => $perCategory,
        ];
    }

    // ----------------------------------------------------------------- internal

    private function rootOf(int $id, Collection $documents): int
    {
        $seen = [];
        while (($doc = $documents->get($id)) && $doc->source_document_id && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = $doc->source_document_id;
        }

        return $id;
    }

    private function pairCategory(ExperimentDocument $a, ExperimentDocument $b, bool $actual): string
    {
        if ($actual) {
            $order = ExperimentDocument::OBFUSCATION_ORDER;
            $derived = array_filter([$a->category, $b->category], fn ($c) => isset($order[$c]));
            usort($derived, fn ($x, $y) => $order[$y] <=> $order[$x]);

            return $derived[0] ?? 'VERBATIM_COPY';
        }

        return match (true) {
            in_array('INDEPENDENT', [$a->category, $b->category], true) => 'NEG_INDEPENDENT',
            $a->isAiWritten() || $b->isAiWritten() => 'NEG_AI',
            default => 'NEG_OTHER',
        };
    }

    /** Grid bobot dengan langkah 0,1 (66 kombinasi, jumlah = 1). */
    private function weightGrid(): array
    {
        $grid = [];
        for ($d = 0; $d <= 10; $d++) {
            for ($p = 0; $p <= 10 - $d; $p++) {
                $grid[] = [$d / 10, $p / 10, (10 - $d - $p) / 10];
            }
        }

        return $grid;
    }

    /** Kombinasi bobot & ambang dengan F1 tertinggi (seri: precision lebih tinggi). */
    private function bestParams(Collection $rows, array $weightCandidates): array
    {
        // Array polos (tanpa closure Collection) karena dipanggil ribuan kali saat optimasi + cross-validation.
        $data = $rows->map(fn ($r) => [$r['scores']['document'], $r['scores']['passage'], $r['scores']['sentence'], $r['actual']])->values()->all();
        $best = ['weights' => $weightCandidates[0], 'threshold' => self::THRESHOLDS[0], 'f1' => -1.0, 'precision' => -1.0];

        foreach ($weightCandidates as $w) {
            $total = ($w[0] + $w[1] + $w[2]) ?: 1;
            $scores = [];
            foreach ($data as $i => $d) {
                $scores[$i] = ($w[0] * $d[0] + $w[1] * $d[1] + $w[2] * $d[2]) / $total;
            }
            foreach (self::THRESHOLDS as $t) {
                $tp = $fp = $fn = 0;
                foreach ($data as $i => $d) {
                    $predicted = $scores[$i] >= $t - 1e-9;
                    if ($predicted && $d[3]) {
                        $tp++;
                    } elseif ($predicted) {
                        $fp++;
                    } elseif ($d[3]) {
                        $fn++;
                    }
                }
                $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : 0.0;
                $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : 0.0;
                $f1 = $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
                if ($f1 > $best['f1'] + 1e-12 || (abs($f1 - $best['f1']) < 1e-12 && $precision > $best['precision'])) {
                    $best = ['weights' => $w, 'threshold' => $t, 'f1' => $f1, 'precision' => $precision];
                }
            }
        }

        $best['metrics'] = $this->evaluate($rows, $best['weights'], $best['threshold']);

        return $best;
    }

    /** Fold stratified dengan pengacakan ber-seed (dapat direproduksi). */
    private function folds(Collection $rows): array
    {
        mt_srand(self::SEED);
        $folds = array_fill(0, self::FOLDS, []);
        foreach ([true, false] as $label) {
            $indices = $rows->keys()->filter(fn ($i) => $rows[$i]['actual'] === $label)->values()->all();
            for ($i = count($indices) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$indices[$i], $indices[$j]] = [$indices[$j], $indices[$i]];
            }
            foreach ($indices as $k => $index) {
                $folds[$k % self::FOLDS][] = $index;
            }
        }
        mt_srand();

        return $folds;
    }

    private function crossValidate(Collection $rows, array $folds, array $weightCandidates): array
    {
        $f1s = [];
        $pooled = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0];

        foreach ($folds as $testIdx) {
            $test = $rows->only($testIdx)->values();
            $train = $rows->except($testIdx)->values();
            if ($test->isEmpty() || $train->where('actual', true)->isEmpty()) {
                continue;
            }
            $best = $this->bestParams($train, $weightCandidates);
            $m = $this->evaluate($test, $best['weights'], $best['threshold']);
            $f1s[] = $m['f1'] ?? 0.0;
            foreach ($pooled as $key => $_) {
                $pooled[$key] += $m[$key];
            }
        }

        $mean = $f1s ? array_sum($f1s) / count($f1s) : null;
        $sd = count($f1s) > 1 ? sqrt(array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $f1s)) / (count($f1s) - 1)) : null;
        $precision = $pooled['tp'] + $pooled['fp'] > 0 ? $pooled['tp'] / ($pooled['tp'] + $pooled['fp']) : null;
        $recall = $pooled['tp'] + $pooled['fn'] > 0 ? $pooled['tp'] / ($pooled['tp'] + $pooled['fn']) : null;

        return [
            'folds' => count($f1s),
            'f1_mean' => $mean,
            'f1_sd' => $sd,
            'pooled' => $pooled + [
                'precision' => $precision,
                'recall' => $recall,
                'f1' => $precision !== null && $recall !== null && $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : null,
            ],
        ];
    }
}
