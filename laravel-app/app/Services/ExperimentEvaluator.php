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

    /** Ambang probabilitas yang diuji untuk deteksi AI. */
    public const AI_THRESHOLDS = [0.10, 0.15, 0.20, 0.25, 0.30, 0.35, 0.40, 0.45, 0.50, 0.55, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90];

    /** Ambang verdict bawaan (nilai awal peneliti, bukan dari literatur). */
    public const AI_VERDICT_THRESHOLDS = ['LIKELY_AI' => 0.70, 'MIXED_AI' => 0.35];

    /** Ambang keputusan "teks AI" pada uji konsistensi (sesuai proposal: ai_probability ≥ 0,5). */
    public const AI_DECISION_THRESHOLD = 0.50;

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

    /**
     * @param  float|null  $tryThreshold  ambang probabilitas yang ingin dicoba (dokumen dianggap AI bila p >= ambang)
     */
    public function aiEvaluation(ExperimentRun $run, ?float $tryThreshold = null): array
    {
        return $this->aiEvaluationFor($run->aiScores()->with('document')->get(), $tryThreshold);
    }

    /**
     * @param  Collection<int, \App\Models\ExperimentAiScore>  $scores  hasil deteksi AI (boleh gabungan beberapa run)
     */
    public function aiEvaluationFor(Collection $scores, ?float $tryThreshold = null): array
    {
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

        // Sweep ambang probabilitas: dihitung dari probabilitas tersimpan, tanpa memanggil LLM.
        $byProbability = fn (float $t) => function ($r) use ($t) {
            return $r['score']->ai_probability !== null && $r['score']->ai_probability >= $t - 1e-9;
        };
        $sweep = [];
        foreach (self::AI_THRESHOLDS as $t) {
            $m = $this->metrics->confusion($valid, $byProbability($t));
            if ($m['f1'] === null && $m['tp'] + $m['fn'] > 0) {
                $m['f1'] = 0.0; // tidak ada yang dianggap AI padahal ada teks AI → recall 0 → F1 0
            }
            $sweep[(string) $t] = $m;
        }
        // F1 terbaik; bila seri, pilih ambang lebih tinggi (lebih jarang menuduh mahasiswa).
        $maxF1 = max(array_map(fn ($m) => $m['f1'] ?? -1.0, $sweep));
        $best = null;
        foreach ($sweep as $t => $m) {
            if ($maxF1 >= 0 && abs(($m['f1'] ?? -1.0) - $maxF1) < 1e-12) {
                $best = $t; // urutan ambang naik, jadi yang terakhir cocok = ambang tertinggi
            }
        }

        return [
            'sweep' => $sweep,
            'bestThreshold' => $best !== null ? (float) $best : null,
            'try' => $tryThreshold !== null ? $this->metrics->confusion($valid, $byProbability($tryThreshold)) : null,
            'tryThreshold' => $tryThreshold,
            'positives' => $valid->where('actual', true)->count(),
            'validCount' => $valid->count(),
            'count' => $scores->count(),
            'errors' => $scores->where('verdict', 'ERROR')->count(),
            'strict' => $this->metrics->confusion($valid, fn ($r) => $r['score']->verdict === 'LIKELY_AI'),
            'lenient' => $this->metrics->confusion($valid, fn ($r) => in_array($r['score']->verdict, ['LIKELY_AI', 'MIXED_AI'], true)),
            'perCategory' => $perCategory,
        ];
    }

    // ----------------------------------------------------------------- internal

    /**
     * Uji konsistensi deteksi AI: dokumen yang sama dinilai beberapa kali (ulangan ke-1 = run utama).
     * Mengukur sebaran skor per dokumen, kesamaan keputusan antar-ulangan, dan F1 tiap ulangan.
     */
    public function aiConsistency(ExperimentRun $run): array
    {
        return $this->aiConsistencyFor([$run]);
    }

    /**
     * Uji konsistensi gabungan beberapa run (misalnya satu run per tugas).
     *
     * @param  array<ExperimentRun>  $runs
     */
    public function aiConsistencyFor(array $runs): array
    {
        $target = 1;
        $documents = [];
        $repeatModels = [];
        $runModels = [];
        $modelMismatch = false;

        foreach ($runs as $run) {
            $target = max($target, (int) ($run->consistency['target'] ?? 1), (int) ($run->aiRepeats()->max('repeat') ?? 1));
            $models = $run->aiRepeats()->whereNotNull('model')->distinct()->pluck('model')->all();
            $runModel = $run->metrics['llm']['model'] ?? null;
            $modelMismatch = $modelMismatch || ($models !== [] && $models !== [$runModel]);
            $repeatModels = array_merge($repeatModels, $models);
            $runModels[] = $runModel;
            array_push($documents, ...$this->consistencyDocuments($run));
        }
        $repeatModels = array_values(array_unique($repeatModels));
        $runModels = array_values(array_unique(array_filter($runModels)));

        return $this->consistencySummary($documents, $target) + [
            'errors' => array_sum(array_map(fn ($run) => $run->aiRepeats()->where('verdict', 'ERROR')->count(), $runs)),
            'runModel' => $runModels ? implode(', ', $runModels) : null,
            'repeatModels' => $repeatModels,
            'modelMismatch' => $modelMismatch,
        ];
    }

    /** Skor tiap dokumen per ulangan (ulangan ke-1 = hasil run). */
    private function consistencyDocuments(ExperimentRun $run): array
    {
        $repeats = $run->aiRepeats()->get()->groupBy('document_id');
        $documents = [];
        foreach ($run->aiScores()->with('document')->get()->sortBy('document_id') as $score) {
            $doc = $score->document;
            if (!$doc) {
                continue;
            }
            $values = [1 => ['p' => $score->ai_probability, 'verdict' => $score->verdict]];
            foreach ($repeats->get($doc->id, collect()) as $r) {
                $values[$r->repeat] = ['p' => $r->ai_probability, 'verdict' => $r->verdict];
            }
            ksort($values);
            $valid = array_filter($values, fn ($v) => $v['verdict'] !== 'ERROR' && $v['p'] !== null);
            $probs = array_column($valid, 'p');

            $documents[] = [
                'document' => $doc,
                'ai_written' => $doc->isAiWritten(),
                'values' => $values,
                'n' => count($probs),
                'mean' => $probs ? array_sum($probs) / count($probs) : null,
                'sd' => count($probs) >= 2 ? $this->sampleSd($probs) : null,
                'range' => count($probs) >= 2 ? max($probs) - min($probs) : null,
                'verdict_same' => count($probs) >= 2 ? count(array_unique(array_column($valid, 'verdict'))) === 1 : null,
                'decision_same' => count($probs) >= 2
                    ? count(array_unique(array_map(fn ($p) => $p >= self::AI_DECISION_THRESHOLD - 1e-9, $probs))) === 1
                    : null,
            ];
        }

        return $documents;
    }

    private function consistencySummary(array $documents, int $target): array
    {
        $compared = array_values(array_filter($documents, fn ($d) => $d['n'] >= 2));
        $summarize = function (array $rows) {
            $n = count($rows);

            return [
                'n' => $n,
                'mean_sd' => $n ? array_sum(array_column($rows, 'sd')) / $n : null,
                'max_range' => $n ? max(array_column($rows, 'range')) : null,
                'verdict_same' => count(array_filter($rows, fn ($d) => $d['verdict_same'])),
                'decision_same' => count(array_filter($rows, fn ($d) => $d['decision_same'])),
            ];
        };

        $perCategory = [];
        foreach (ExperimentDocument::CATEGORIES as $key => $label) {
            $rows = array_values(array_filter($compared, fn ($d) => $d['document']->category === $key));
            if ($rows) {
                $perCategory[] = ['label' => $label, 'ai_written' => in_array($key, ExperimentDocument::AI_WRITTEN, true)] + $summarize($rows);
            }
        }

        // Metrik deteksi tiap ulangan pada ambang keputusan yang sama.
        $perRepeat = [];
        for ($repeat = 1; $repeat <= $target; $repeat++) {
            $rows = collect($documents)
                ->filter(fn ($d) => isset($d['values'][$repeat]) && $d['values'][$repeat]['verdict'] !== 'ERROR' && $d['values'][$repeat]['p'] !== null)
                ->map(fn ($d) => ['p' => $d['values'][$repeat]['p'], 'actual' => $d['ai_written']]);
            $perRepeat[$repeat] = $rows->isEmpty() ? null
                : $this->metrics->confusion($rows, fn ($r) => $r['p'] >= self::AI_DECISION_THRESHOLD - 1e-9);
        }
        $f1s = array_values(array_filter(array_map(fn ($m) => $m['f1'] ?? null, $perRepeat), fn ($v) => $v !== null));

        return [
            'target' => $target,
            'threshold' => self::AI_DECISION_THRESHOLD,
            'documents' => $documents,
            'summary' => $summarize($compared),
            'complete' => count(array_filter($documents, fn ($d) => $d['n'] >= $target)),
            'perCategory' => $perCategory,
            'perRepeat' => $perRepeat,
            'f1_mean' => $f1s ? array_sum($f1s) / count($f1s) : null,
            'f1_sd' => count($f1s) >= 2 ? $this->sampleSd($f1s) : null,
        ];
    }

    /** @param  array<float>  $values */
    private function sampleSd(array $values): float
    {
        $mean = array_sum($values) / count($values);

        return sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / (count($values) - 1));
    }

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
        $thresholds = [];
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
            $thresholds[] = $best['threshold'];
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
            // Ambang yang terpilih di data latih tiap fold — menunjukkan stabil/tidaknya pemilihan ambang.
            'thresholds' => $thresholds,
            'pooled' => $pooled + [
                'precision' => $precision,
                'recall' => $recall,
                'f1' => $precision !== null && $recall !== null && $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : null,
            ],
        ];
    }
}
