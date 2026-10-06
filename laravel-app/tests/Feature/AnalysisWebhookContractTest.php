<?php

namespace Tests\Feature;

use App\Models\AiDetectionResult;
use App\Models\AnalysisRun;
use App\Models\Assignment;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisWebhookContractTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/webhooks/similarity-result';
    private const KEY = 'test-webhook-key';

    private Assignment $assignment;
    private AnalysisRun $run;
    private Submission $a;
    private Submission $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.fastapi.webhook_key' => self::KEY]);

        $this->assignment = Assignment::factory()->closed()->create();
        [$this->a, $this->b] = collect([1, 2])->map(fn ($i) => $this->assignment->submissions()->create([
            'user_id' => User::factory()->create()->id, 'file_path' => "x{$i}.pdf", 'file_name' => "x{$i}.pdf",
        ]))->all();
        $this->run = $this->assignment->analysisRuns()->create([
            'status' => AnalysisRun::STATUS_PROCESSING, 'threshold' => 0.7, 'documents_sent' => 2,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'assignment_id' => $this->assignment->id,
            'analysis_run_id' => $this->run->id,
            'status' => 'completed',
            'results' => [],
            'ai_detections' => [],
        ], $overrides);
    }

    private function send(array $payload, ?string $key = self::KEY)
    {
        return $this->withHeaders($key === null ? [] : ['X-API-Key' => $key])->postJson(self::URL, $payload);
    }

    public function test_callback_without_valid_key_is_rejected(): void
    {
        $this->send($this->payload(), key: null)->assertUnauthorized();
        $this->send($this->payload(), key: 'salah')->assertUnauthorized();

        $this->assertSame(AnalysisRun::STATUS_PROCESSING, $this->run->fresh()->status);
    }

    public function test_callback_is_rejected_when_key_not_configured(): void
    {
        config(['services.fastapi.webhook_key' => null]);

        $this->send($this->payload(), key: '')->assertStatus(503);
    }

    public function test_llm_values_are_normalized_before_saving(): void
    {
        $this->send($this->payload(['results' => [[
            'doc_a' => ['submission_id' => $this->a->id],
            'doc_b' => ['submission_id' => $this->b->id],
            'similarity_score' => 1.0000001,
            'is_flagged' => true,
            'llm_analysis' => [
                'verdict' => str_repeat('panjang ', 60),
                'action_recommendation' => 'CEK_ULANG',   // di luar enum
                'matched_segments' => [
                    ['text_doc_a' => 'a', 'text_doc_b' => 'a', 'match_type' => 'copy_paste'],
                    ['text_doc_a' => 'b', 'text_doc_b' => 'c', 'match_type' => 'paraphrase'],
                    'bukan-array',
                ],
            ],
        ]]]))->assertOk();

        $result = SimilarityResult::sole();
        $this->assertSame('PERIKSA_MANUAL', $result->action_recommendation);
        $this->assertSame(1.0, $result->similarity_score);
        $this->assertSame(255, mb_strlen($result->verdict));
        $this->assertSame(['VERBATIM_COPY', 'PARAPHRASED'], array_column($result->matched_segments, 'match_type'));
        $this->assertSame(AnalysisRun::STATUS_COMPLETED, $this->run->fresh()->status);
    }

    public function test_failed_ai_detection_is_stored_as_error_not_human(): void
    {
        $this->send($this->payload(['ai_detections' => [
            ['submission_id' => $this->a->id, 'ai_probability' => null, 'verdict' => 'ERROR', 'analysis_summary' => 'Deteksi AI gagal'],
            ['submission_id' => $this->b->id, 'ai_probability' => 0.9, 'verdict' => 'ROBOT'],
        ]]))->assertOk();

        $rows = AiDetectionResult::orderBy('submission_id')->get();
        $this->assertSame(['ERROR', 'ERROR'], $rows->pluck('verdict')->all());
        $this->assertNull($rows[0]->ai_probability);
        $this->assertNull($rows[1]->ai_probability);
    }

    public function test_submissions_from_other_assignments_are_ignored(): void
    {
        $foreign = Submission::create([
            'assignment_id' => Assignment::factory()->create()->id,
            'user_id' => User::factory()->create()->id,
            'file_path' => 'f.pdf',
            'file_name' => 'f.pdf',
        ]);

        $this->send($this->payload([
            'results' => [[
                'doc_a' => ['submission_id' => $this->a->id],
                'doc_b' => ['submission_id' => $foreign->id],
                'similarity_score' => 0.9,
                'is_flagged' => true,
            ]],
            'ai_detections' => [['submission_id' => $foreign->id, 'ai_probability' => 0.1, 'verdict' => 'HUMAN_WRITTEN']],
        ]))->assertOk();

        $this->assertDatabaseCount('similarity_results', 0);
        $this->assertDatabaseCount('ai_detection_results', 0);
    }

    public function test_run_belonging_to_another_assignment_is_not_updated(): void
    {
        $other = Assignment::factory()->create();

        $this->send($this->payload(['assignment_id' => $other->id]))->assertStatus(500);

        $this->assertSame(AnalysisRun::STATUS_PROCESSING, $this->run->fresh()->status);
    }
}
