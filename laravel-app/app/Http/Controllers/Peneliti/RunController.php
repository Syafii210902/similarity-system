<?php

namespace App\Http\Controllers\Peneliti;

use App\Http\Controllers\Controller;
use App\Models\ExperimentDataset;
use App\Models\ExperimentRun;
use App\Services\ExperimentEvaluator;
use App\Services\ExperimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunController extends Controller
{
    public function __construct(
        protected ExperimentService $experiments,
        protected ExperimentEvaluator $evaluator
    ) {}

    public function store(Request $request, ExperimentDataset $dataset): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'threshold' => ['required', 'numeric', 'between:0.3,0.95'],
            'weight_document' => ['required', 'numeric', 'between:0,1'],
            'weight_passage' => ['required', 'numeric', 'between:0,1'],
            'weight_sentence' => ['required', 'numeric', 'between:0,1'],
            'passage_max_words' => ['required', 'integer', 'between:20,100'],
            'sentence_match_threshold' => ['required', 'numeric', 'between:0.5,1'],
        ], [
            'name.required' => 'Beri nama eksperimen agar mudah dibandingkan.',
            'threshold.between' => 'Ambang harus antara 0,30 dan 0,95.',
        ]);

        $weights = [(float) $data['weight_document'], (float) $data['weight_passage'], (float) $data['weight_sentence']];
        if (array_sum($weights) <= 0) {
            return back()->withInput()->withErrors(['weight_document' => 'Jumlah bobot harus lebih dari 0.']);
        }

        try {
            $run = $this->experiments->startRun($dataset, $request->user(), [
                'name' => $data['name'],
                'threshold' => (float) $data['threshold'],
                'weights' => $weights,
                'passage_max_words' => (int) $data['passage_max_words'],
                'sentence_match_threshold' => (float) $data['sentence_match_threshold'],
                'run_tier2' => $request->boolean('run_tier2'),
                'run_ai_detection' => $request->boolean('run_ai_detection'),
            ]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $run->status === 'failed'
            ? back()->withInput()->with('error', $run->error_message)
            : redirect()->route('peneliti.runs.show', $run)->with('success', 'Eksperimen dimulai. Halaman diperbarui otomatis setelah selesai.');
    }

    /**
     * Hasil eksperimen. Parameter ?w=0.2,0.4,0.4&t=0.7 menghitung ulang keputusan Tahap 1 secara instan.
     */
    public function show(Request $request, ExperimentRun $run): View
    {
        $run->load('dataset');
        $this->experiments->expireStaleRuns($run->dataset);
        $run->refresh();

        $data = ['run' => $run, 'dataset' => $run->dataset];

        if ($run->status === 'completed') {
            $rows = $this->evaluator->rows($run);
            $runWeights = $run->config['weights'];
            $runThreshold = (float) $run->config['threshold'];
            $tier2 = (bool) ($run->config['run_tier2'] ?? false);

            [$tryWeights, $tryThreshold] = $this->parseTryParams($request, $runWeights, $runThreshold);

            $data += [
                'rows' => $rows,
                'runWeights' => $runWeights,
                'runThreshold' => $runThreshold,
                'tier2' => $tier2,
                'tiers' => $this->evaluator->tierComparison($rows, $runThreshold, $tier2),
                'perCategory' => $this->evaluator->perCategory($rows, $runThreshold, $tier2),
                'sweep' => $this->evaluator->sweep($rows, $runWeights),
                'ablation' => $this->evaluator->ablation($rows, $runWeights),
                'tryWeights' => $tryWeights,
                'tryThreshold' => $tryThreshold,
                'tryResult' => $this->evaluator->evaluate($rows, $tryWeights, $tryThreshold),
                'ai' => ($run->config['run_ai_detection'] ?? false) ? $this->evaluator->aiEvaluation($run) : null,
            ];
        }

        return view('peneliti.runs.show', $data);
    }

    public function status(ExperimentRun $run): JsonResponse
    {
        $this->experiments->expireStaleRuns($run->dataset);

        return response()->json(['status' => $run->fresh()->status]);
    }

    public function export(ExperimentRun $run): StreamedResponse
    {
        abort_unless($run->status === 'completed', 404);
        $rows = $this->evaluator->rows($run->load('dataset'));

        $filename = 'eksperimen-' . $run->id . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows, $run) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['# run', $run->id, $run->name, json_encode($run->config), $run->metrics['embedding_model'] ?? '', $run->metrics['llm']['model'] ?? '']);
            fputcsv($out, ['dokumen_a', 'kategori_a', 'dokumen_b', 'kategori_b', 'kategori_pasangan', 'label_plagiat',
                'skor_dokumen', 'skor_passage', 'skor_kalimat', 'skor_gabungan_run', 'diperiksa_llm', 'rekomendasi_llm']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['a']->title, $r['a']->category, $r['b']->title, $r['b']->category, $r['category'], (int) $r['actual'],
                    $r['scores']['document'], $r['scores']['passage'], $r['scores']['sentence'], $r['combined'],
                    (int) $r['llm_checked'], $r['pair']->llm_action,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroy(ExperimentRun $run): RedirectResponse
    {
        $dataset = $run->dataset;
        $run->delete();

        return redirect()->route('peneliti.datasets.show', $dataset)->with('success', 'Eksperimen dihapus.');
    }

    /** @return array{0: array<float>, 1: float} */
    private function parseTryParams(Request $request, array $runWeights, float $runThreshold): array
    {
        $weights = $runWeights;
        if ($request->filled(['wd', 'wp', 'ws'])) {
            $candidate = [(float) $request->input('wd'), (float) $request->input('wp'), (float) $request->input('ws')];
            if (min($candidate) >= 0 && array_sum($candidate) > 0) {
                $weights = $candidate;
            }
        }
        $threshold = $request->filled('t') ? max(0.0, min(1.0, (float) $request->input('t'))) : $runThreshold;

        return [$weights, $threshold];
    }
}
