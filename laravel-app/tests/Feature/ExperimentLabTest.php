<?php

namespace Tests\Feature;

use App\Models\ExperimentDataset;
use App\Models\ExperimentDocument;
use App\Models\ExperimentRun;
use App\Models\User;
use App\Services\ExperimentEvaluator;
use App\Services\ExperimentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExperimentLabTest extends TestCase
{
    use RefreshDatabase;

    private User $peneliti;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.fastapi.api_key' => 'engine-key',
            'services.fastapi.webhook_key' => 'webhook-key',
        ]);
        $this->peneliti = User::factory()->peneliti()->create();
    }

    private function sampleDataset(): ExperimentDataset
    {
        return app(ExperimentService::class)->createSampleDataset($this->peneliti);
    }

    /**
     * Run selesai dengan skor sintetis: pasangan sekeluarga mendapat skor tinggi, sisanya rendah,
     * kecuali satu pasangan "parafrase AI" yang sengaja rendah (lolos) dan satu pasangan independen yang tinggi (salah tuduh).
     */
    private function completedRun(ExperimentDataset $dataset, bool $tier2 = true): ExperimentRun
    {
        $run = $dataset->runs()->create([
            'name' => 'Uji', 'status' => 'processing', 'created_by' => $this->peneliti->id,
            'config' => ['threshold' => 0.7, 'weights' => [0.2, 0.4, 0.4], 'passage_max_words' => 60,
                'sentence_match_threshold' => 0.85, 'run_tier2' => $tier2, 'run_ai_detection' => true],
        ]);

        $docs = $dataset->documents()->get()->keyBy('id');
        $root = function ($doc) use ($docs) {
            while ($doc->source_document_id) {
                $doc = $docs[$doc->source_document_id];
            }

            return $doc->id;
        };

        $results = [];
        $list = $docs->values();
        foreach ($list as $i => $a) {
            foreach ($list->slice($i + 1) as $b) {
                $same = $root($a) === $root($b);
                $paraAi = in_array('PARAPHRASE_AI', [$a->category, $b->category], true);
                // Dua esai independen dari topik yang sama (judul berawalan sama).
                $independents = $a->category === 'INDEPENDENT' && $b->category === 'INDEPENDENT'
                    && strtok($a->title, ' ') === strtok($b->title, ' ');
                [$d, $p, $s] = match (true) {
                    $same && $paraAi => [0.80, 0.55, 0.10],
                    $same => [0.95, 0.90, 0.80],
                    $independents => [0.72, 0.85, 0.70],
                    default => [0.60, 0.40, 0.00],
                };
                $combined = round(0.2 * $d + 0.4 * $p + 0.4 * $s, 4);
                $results[] = [
                    'doc_a' => ['submission_id' => $a->id], 'doc_b' => ['submission_id' => $b->id],
                    'similarity_score' => $combined, 'layer_scores' => ['document' => $d, 'passage' => $p, 'sentence' => $s],
                    'is_flagged' => $combined >= 0.7,
                    'llm_analysis' => $tier2 && $combined >= 0.7
                        ? ['action_recommendation' => $independents ? 'AMAN' : 'PERIKSA_MANUAL', 'verdict' => 'x', 'matched_segments' => [['match_type' => 'VERBATIM_COPY']]]
                        : null,
                ];
            }
        }

        $ai = $docs->map(fn ($doc) => [
            'submission_id' => $doc->id,
            'ai_probability' => $doc->isAiWritten() ? 0.85 : 0.1,
            'verdict' => $doc->isAiWritten() ? 'LIKELY_AI' : 'HUMAN_WRITTEN',
        ])->values()->all();

        $this->withHeader('X-API-Key', 'webhook-key')->postJson('/api/webhooks/experiment-result', [
            'analysis_run_id' => $run->id,
            'status' => 'completed',
            'embedding_model' => 'paraphrase-multilingual-MiniLM-L12-v2',
            'results' => $results,
            'ai_detections' => $ai,
            'metrics' => ['llm_similarity_calls' => 10, 'llm_similarity_calls_avoided' => 81],
        ])->assertOk();

        return $run->fresh();
    }

    public function test_only_peneliti_can_open_the_lab(): void
    {
        $this->actingAs($this->peneliti)->get(route('peneliti.dashboard'))->assertOk()->assertSee('Lab Pengujian');

        foreach ([User::factory()->admin()->create(), User::factory()->dosen()->create(), User::factory()->create()] as $other) {
            $this->actingAs($other)->get(route('peneliti.dashboard'))->assertForbidden();
        }
    }

    public function test_peneliti_login_lands_on_lab(): void
    {
        $this->post('/login', ['email' => $this->peneliti->email, 'password' => 'password']);
        $this->get('/dashboard')->assertRedirect(route('peneliti.dashboard'));
    }

    public function test_sample_dataset_builds_families_and_labels(): void
    {
        $this->actingAs($this->peneliti)->post(route('peneliti.datasets.sample'))->assertRedirect();

        $dataset = ExperimentDataset::sole();
        $this->assertTrue($dataset->is_sample);
        $this->assertSame(14, $dataset->documents()->count());
        $this->assertSame(6, $dataset->documents()->whereNotNull('source_document_id')->count());

        $this->get(route('peneliti.datasets.show', $dataset))
            ->assertOk()
            ->assertSee('korpus contoh', false)
            ->assertSeeText('Pasangan plagiat (label)');
    }

    public function test_document_validation_requires_source_for_derived_documents(): void
    {
        $dataset = ExperimentDataset::create(['name' => 'D', 'created_by' => $this->peneliti->id]);
        $other = ExperimentDataset::create(['name' => 'Lain']);
        $foreign = app(ExperimentService::class)->addDocument($other, ['title' => 'x', 'category' => 'ORIGINAL'], null, str_repeat('teks lain ', 10));
        $text = str_repeat('Kalimat contoh untuk dokumen uji. ', 5);

        $this->actingAs($this->peneliti);
        $this->post(route('peneliti.documents.store', $dataset), ['title' => 'Salin', 'category' => 'VERBATIM_COPY', 'text' => $text])
            ->assertSessionHasErrors('source_document_id');
        $this->post(route('peneliti.documents.store', $dataset), ['title' => 'Salin', 'category' => 'VERBATIM_COPY', 'source_document_id' => $foreign->id, 'text' => $text])
            ->assertSessionHasErrors('source_document_id');
        $this->post(route('peneliti.documents.store', $dataset), ['title' => 'Asli', 'category' => 'ORIGINAL'])
            ->assertSessionHasErrors(['text', 'file']);

        $this->post(route('peneliti.documents.store', $dataset), ['title' => 'Asli', 'category' => 'ORIGINAL', 'text' => $text])->assertSessionHasNoErrors();
        $doc = $dataset->documents()->sole();
        $this->assertStringEndsWith('.txt', $doc->file_path);
        $this->assertSame(25, $doc->word_count);
        Storage::disk('public')->assertExists($doc->file_path);
        $this->get(route('peneliti.documents.show', $doc))->assertOk()->assertSee('Kalimat contoh untuk dokumen uji.');
    }

    public function test_starting_a_run_sends_config_to_engine(): void
    {
        Http::fake(['*/api/v1/analyze-similarity' => Http::response(['status' => 'accepted'], 202)]);
        $dataset = $this->sampleDataset();

        $this->actingAs($this->peneliti)->post(route('peneliti.runs.store', $dataset), [
            'name' => 'Tanpa LLM', 'threshold' => 0.65, 'weight_document' => 0, 'weight_passage' => 0.5, 'weight_sentence' => 0.5,
            'passage_max_words' => 50, 'sentence_match_threshold' => 0.9,
        ])->assertRedirect();

        $run = ExperimentRun::sole();
        $this->assertSame('processing', $run->status);
        $this->assertFalse($run->config['run_tier2']);

        Http::assertSent(fn (Request $r) => $r->header('X-API-Key') === ['engine-key']
            && $r['analysis_run_id'] === $run->id
            && str_ends_with($r['callback_url'], '/api/webhooks/experiment-result')
            && $r['config']['layer_weights'] === [0.0, 0.5, 0.5]
            && $r['config']['passage_max_words'] === 50
            && $r['config']['run_tier2'] === false
            && count($r['documents']) === 14);
    }

    public function test_webhook_requires_key_and_stores_scores(): void
    {
        $dataset = $this->sampleDataset();

        $this->postJson('/api/webhooks/experiment-result', ['analysis_run_id' => 1, 'status' => 'completed'])->assertUnauthorized();

        $run = $this->completedRun($dataset);
        $this->assertSame('completed', $run->status);
        $this->assertSame(91, $run->pairScores()->count());
        $this->assertSame(14, $run->aiScores()->count());
    }

    public function test_evaluator_labels_categories_and_tiers(): void
    {
        $run = $this->completedRun($this->sampleDataset());
        $evaluator = app(ExperimentEvaluator::class);
        $rows = $evaluator->rows($run->load('dataset'));

        $this->assertSame(12, $rows->where('actual', true)->count());
        $this->assertSame(6, $rows->where('category', 'PARAPHRASE_AI')->count());

        $tiers = $evaluator->tierComparison($rows, 0.7, true);
        // Parafrase AI (6 pasangan) lolos di Tahap 1; 2 pasangan independen-independen salah ditandai.
        $this->assertSame(['tp' => 6, 'fp' => 2, 'fn' => 6], array_intersect_key($tiers['tier1'], array_flip(['tp', 'fp', 'fn'])));
        // LLM menyatakan pasangan independen aman → false positive hilang.
        $this->assertSame(0, $tiers['tier12']['fp']);

        $categories = collect($evaluator->perCategory($rows, 0.7, true))->keyBy('key');
        $this->assertSame(0, $categories['PARAPHRASE_AI']['tier1']);
        $this->assertSame(2, $categories['NEG_INDEPENDENT']['tier1']);
    }

    public function test_ablation_and_cross_validation_find_better_weights(): void
    {
        $run = $this->completedRun($this->sampleDataset());
        $evaluator = app(ExperimentEvaluator::class);
        $ablation = collect($evaluator->ablation($evaluator->rows($run->load('dataset')), [0.2, 0.4, 0.4]))->keyBy('label');

        $this->assertCount(6, $ablation);
        $optimized = $ablation['Bobot hasil optimasi'];
        $this->assertSame(5, $optimized['cv']['folds']);
        // Hanya lapis dokumen yang memisahkan parafrase AI (0,80) dari esai independen (0,72) → optimasi mencapai F1 penuh.
        $this->assertEqualsWithDelta(1.0, $optimized['in_sample']['f1'], 1e-9);
        $this->assertGreaterThan($ablation['Lapis kalimat saja']['in_sample']['f1'], $optimized['in_sample']['f1']);
    }

    public function test_run_page_renders_results_chart_and_try_form(): void
    {
        $run = $this->completedRun($this->sampleDataset());

        $this->actingAs($this->peneliti)
            ->get(route('peneliti.runs.show', $run))
            ->assertOk()
            ->assertSee('Ablation')
            ->assertSee('Parafrase oleh AI')
            ->assertSee('<svg viewBox', false)
            ->assertSee('Tahap 1 + Tahap 2');

        $this->get(route('peneliti.runs.show', $run) . '?wd=1&wp=0&ws=0&t=0.75')
            ->assertOk()
            ->assertSee('Bobot 1,00 / 0,00 / 0,00 · ambang 0,75');

        $csv = $this->get(route('peneliti.runs.export', $run))->assertOk()->streamedContent();
        $this->assertStringContainsString('kategori_pasangan,label_plagiat', $csv);
        $this->assertSame(93, substr_count(trim($csv), "\n") + 1); // metadata + header + 91 pasangan
    }

    public function test_quick_compare_renders_engine_result(): void
    {
        Http::fake(['*/api/v1/compare-texts' => Http::response([
            'embedding_model' => 'm', 'llm' => null,
            'params' => ['weights' => [0.2, 0.4, 0.4]],
            'counts' => ['sentences_a' => 3, 'passages_a' => 1, 'sentences_b' => 3, 'passages_b' => 1],
            'layer_scores' => ['document' => 0.91, 'passage' => 0.88, 'sentence' => 0.67],
            'combined' => 0.8026,
            'evidence' => [['text_a' => 'Potongan A', 'text_b' => 'Potongan B', 'score' => 0.88]],
            'llm_analysis' => null, 'llm_usage' => [], 'timings' => ['tier1_seconds' => 0.12],
        ])]);

        $this->actingAs($this->peneliti)
            ->post(route('peneliti.compare.run'), ['text_a' => str_repeat('a b ', 20), 'text_b' => str_repeat('c d ', 20)])
            ->assertOk()
            ->assertSee('0,803')
            ->assertSee('Potongan B');

        Http::assertSent(fn (Request $r) => $r['config']['run_tier2'] === false);
    }
}
