<?php

namespace Tests\Feature;

use App\Models\AiDetectionResult;
use App\Models\AiLabel;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\PairLabel;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\User;
use App\Services\EvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvaluationTest extends TestCase
{
    use RefreshDatabase;

    private User $dosen;
    private Assignment $assignment;
    /** @var array<int, Submission> */
    private array $subs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dosen = User::factory()->dosen()->create();
        $this->assignment = Assignment::factory()->for(Course::factory()->for($this->dosen, 'dosen'))->closed()->create();
        foreach (range(0, 3) as $i) {
            $this->subs[] = $this->assignment->submissions()->create([
                'user_id' => User::factory()->create(['name' => "Mhs {$i}"])->id,
                'file_path' => "f{$i}.pdf",
                'file_name' => "f{$i}.pdf",
            ]);
        }
    }

    private function makeResult(int $a, int $b, float $combined, bool $flagged, string $action = 'PERIKSA_MANUAL', ?array $layers = null): SimilarityResult
    {
        return SimilarityResult::create([
            'assignment_id' => $this->assignment->id,
            'submission_a_id' => $this->subs[$a]->id,
            'submission_b_id' => $this->subs[$b]->id,
            'similarity_score' => $combined,
            'layer_scores' => $layers,
            'is_flagged' => $flagged,
            'verdict' => 'x',
            'action_recommendation' => $flagged ? $action : 'AMAN',
            'matched_segments' => [],
        ]);
    }

    private function label(int $a, int $b, bool $plagiarism): void
    {
        PairLabel::create([
            'assignment_id' => $this->assignment->id,
            'submission_low_id' => min($this->subs[$a]->id, $this->subs[$b]->id),
            'submission_high_id' => max($this->subs[$a]->id, $this->subs[$b]->id),
            'is_plagiarism' => $plagiarism,
        ]);
    }

    public function test_pair_label_is_saved_updated_and_cleared_via_json(): void
    {
        $url = route('dosen.assignments.labels.pair', $this->assignment);
        // Urutan A/B terbalik tetap merujuk pasangan yang sama.
        $payload = ['submission_a_id' => $this->subs[1]->id, 'submission_b_id' => $this->subs[0]->id];

        $this->actingAs($this->dosen)->postJson($url, $payload + ['label' => 'plagiarism'])->assertOk()->assertJson(['label' => 'plagiarism']);
        $label = PairLabel::sole();
        $this->assertSame([$this->subs[0]->id, $this->subs[1]->id], [$label->submission_low_id, $label->submission_high_id]);
        $this->assertTrue($label->is_plagiarism);

        $this->postJson($url, $payload + ['label' => 'not_plagiarism'])->assertOk();
        $this->assertFalse(PairLabel::sole()->is_plagiarism);

        $this->postJson($url, $payload + ['label' => 'clear'])->assertOk()->assertJson(['label' => null]);
        $this->assertDatabaseCount('pair_labels', 0);
    }

    public function test_labels_reject_submissions_from_other_assignments_and_other_dosen(): void
    {
        $foreign = Assignment::factory()->create()->submissions()->create([
            'user_id' => User::factory()->create()->id, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf',
        ]);

        $this->actingAs($this->dosen)
            ->postJson(route('dosen.assignments.labels.pair', $this->assignment), [
                'submission_a_id' => $this->subs[0]->id, 'submission_b_id' => $foreign->id, 'label' => 'plagiarism',
            ])->assertUnprocessable();

        $this->actingAs(User::factory()->dosen()->create())
            ->postJson(route('dosen.assignments.labels.ai', $this->assignment), ['submission_id' => $this->subs[0]->id, 'label' => 'ai'])
            ->assertForbidden();
    }

    public function test_confusion_metrics_are_computed_correctly(): void
    {
        // 0-1 plagiat & terdeteksi (TP), 0-2 plagiat tapi lolos (FN), 1-2 bukan tapi ditandai (FP), 2-3 bukan & aman (TN)
        $this->makeResult(0, 1, 0.95, true);
        $this->makeResult(0, 2, 0.60, false);
        $this->makeResult(1, 2, 0.80, true, 'AMAN'); // LLM menyatakan aman → Tahap 1+2 menjadi negatif
        $this->makeResult(2, 3, 0.30, false);
        $this->makeResult(1, 3, 0.20, false);        // tidak divalidasi → diabaikan
        $this->label(0, 1, true);
        $this->label(0, 2, true);
        $this->label(1, 2, false);
        $this->label(2, 3, false);

        $s = app(EvaluationService::class)->summarize($this->dosen);

        $this->assertSame(4, $s['pairCount']);
        $this->assertSame(1, $s['unlabeledPairs']);
        $this->assertSame(['tp' => 1, 'fp' => 1, 'fn' => 1, 'tn' => 1], array_intersect_key($s['tier1'], array_flip(['tp', 'fp', 'fn', 'tn'])));
        $this->assertEqualsWithDelta(0.5, $s['tier1']['precision'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $s['tier1']['f1'], 1e-9);
        // Tahap 2 menghilangkan false positive
        $this->assertSame(0, $s['tier12']['fp']);
        $this->assertEqualsWithDelta(1.0, $s['tier12']['precision'], 1e-9);
        // Ambang 0,60 menangkap kedua pasangan plagiat → recall 1
        $this->assertEqualsWithDelta(1.0, $s['sweep']['combined']['0.6']['recall'], 1e-9);
    }

    public function test_layer_sweep_ignores_results_without_layer_scores(): void
    {
        $this->makeResult(0, 1, 0.9, true, 'PERIKSA_MANUAL', ['document' => 0.95, 'passage' => 0.9, 'sentence' => 0.8]);
        $this->makeResult(2, 3, 0.4, false); // hasil lama tanpa layer_scores
        $this->label(0, 1, true);
        $this->label(2, 3, false);

        $s = app(EvaluationService::class)->summarize($this->dosen);

        $this->assertSame(2, $s['sweep']['combined']['0.5']['n']);
        $this->assertSame(1, $s['sweep']['sentence']['0.5']['n']);
    }

    public function test_ai_metrics_exclude_errors(): void
    {
        foreach ([[0, 0.9, 'LIKELY_AI', true], [1, 0.5, 'MIXED_AI', true], [2, 0.1, 'HUMAN_WRITTEN', false], [3, null, 'ERROR', true]] as [$i, $p, $v, $isAi]) {
            AiDetectionResult::create(['assignment_id' => $this->assignment->id, 'submission_id' => $this->subs[$i]->id, 'ai_probability' => $p, 'verdict' => $v]);
            AiLabel::create(['assignment_id' => $this->assignment->id, 'submission_id' => $this->subs[$i]->id, 'is_ai' => $isAi]);
        }

        $s = app(EvaluationService::class)->summarize($this->dosen);

        $this->assertSame(1, $s['aiErrors']);
        $this->assertSame(['tp' => 1, 'fn' => 1], array_intersect_key($s['aiStrict'], array_flip(['tp', 'fn'])));
        $this->assertSame(2, $s['aiLenient']['tp']);
    }

    public function test_evaluation_page_and_csv_export(): void
    {
        $this->makeResult(0, 1, 0.91, true, 'PERIKSA_MANUAL', ['document' => 0.97, 'passage' => 0.93, 'sentence' => 0.8]);
        $this->label(0, 1, true);

        $this->actingAs($this->dosen)
            ->get(route('dosen.evaluation'))
            ->assertOk()
            ->assertSee('Tahap 1 + Tahap 2')
            ->assertSee('Pengaruh ambang batas');

        $csv = $this->get(route('dosen.evaluation.export', ['type' => 'pairs', 'assignment' => $this->assignment->id]))
            ->assertOk()
            ->assertDownload()
            ->streamedContent();

        $this->assertStringContainsString('skor_gabungan,skor_dokumen,skor_passage,skor_kalimat', $csv);
        $this->assertStringContainsString('"Mhs 0","Mhs 1",0.91,0.97,0.93,0.8,1,PERIKSA_MANUAL,0,0,1', str_replace('Mhs 0,', '"Mhs 0",', str_replace('Mhs 1,', '"Mhs 1",', $csv)));
    }

    public function test_results_page_shows_validation_controls(): void
    {
        $this->makeResult(0, 1, 0.91, true, 'PERIKSA_MANUAL', ['document' => 0.97, 'passage' => 0.93, 'sentence' => 0.8]);
        $this->label(0, 1, true);

        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.results', $this->assignment))
            ->assertOk()
            ->assertSee('data-label-form', false)
            ->assertSee('aria-pressed="true"', false)
            ->assertSeeTextInOrder(['D 0,97', 'P 0,93', 'K 0,80']);
    }

    public function test_replacing_a_file_clears_its_labels(): void
    {
        Storage::fake('public');
        $student = $this->subs[0]->user;
        $this->assignment->course->mahasiswa()->attach($student);
        $this->assignment->update(['due_date' => now()->addDay()]);
        Storage::disk('public')->put($this->subs[0]->file_path, 'lama');

        $this->label(0, 1, true);
        AiLabel::create(['assignment_id' => $this->assignment->id, 'submission_id' => $this->subs[0]->id, 'is_ai' => true]);

        $this->actingAs($student)->put(route('mahasiswa.submissions.update', $this->assignment), [
            'file' => UploadedFile::fake()->create('baru.pdf', 10, 'application/pdf'),
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('pair_labels', 0);
        $this->assertDatabaseCount('ai_labels', 0);
    }

    public function test_webhook_stores_layer_scores(): void
    {
        config(['services.fastapi.webhook_key' => 'k']);
        $run = $this->assignment->analysisRuns()->create(['status' => 'processing', 'threshold' => 0.7, 'documents_sent' => 2]);

        $this->withHeader('X-API-Key', 'k')->postJson('/api/webhooks/similarity-result', [
            'assignment_id' => $this->assignment->id,
            'analysis_run_id' => $run->id,
            'status' => 'completed',
            'results' => [[
                'doc_a' => ['submission_id' => $this->subs[0]->id],
                'doc_b' => ['submission_id' => $this->subs[1]->id],
                'similarity_score' => 0.73,
                'layer_scores' => ['document' => 0.88, 'passage' => 0.86, 'sentence' => 0.5, 'junk' => 'x'],
                'is_flagged' => true,
            ]],
        ])->assertOk();

        $this->assertSame(['document' => 0.88, 'passage' => 0.86, 'sentence' => 0.5], SimilarityResult::sole()->layer_scores);
    }
}
