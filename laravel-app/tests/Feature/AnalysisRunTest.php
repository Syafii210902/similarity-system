<?php

namespace Tests\Feature;

use App\Models\AnalysisRun;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalysisRunTest extends TestCase
{
    use RefreshDatabase;

    private const FASTAPI = 'http://fastapi:8000/api/v1/analyze-similarity';

    private User $dosen;
    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.fastapi.api_key' => 'test-engine-key',
            'services.fastapi.webhook_key' => 'test-webhook-key',
        ]);

        $this->dosen = User::factory()->dosen()->create();
        $course = Course::factory()->for($this->dosen, 'dosen')->create();
        $this->assignment = Assignment::factory()->for($course)->closed()->create();
    }

    private function addSubmission(bool $withFile = true): Submission
    {
        $path = "submissions/{$this->assignment->id}/" . uniqid() . '.pdf';
        if ($withFile) {
            Storage::disk('public')->put($path, 'isi');
        }

        return $this->assignment->submissions()->create([
            'user_id' => User::factory()->create()->id,
            'file_path' => $path,
            'file_name' => 'tugas.pdf',
        ]);
    }

    private function trigger(float $threshold = 0.7)
    {
        return $this->actingAs($this->dosen)->post(
            route('dosen.assignments.analysis.store', $this->assignment),
            ['threshold' => $threshold]
        );
    }

    public function test_dosen_starts_analysis_and_payload_is_built_from_database(): void
    {
        Http::fake([self::FASTAPI => Http::response(['status' => 'accepted'], 202)]);
        $a = $this->addSubmission();
        $b = $this->addSubmission();
        $this->addSubmission(withFile: false);

        $this->trigger(0.8)->assertSessionHas('success');

        $run = AnalysisRun::sole();
        $this->assertSame(AnalysisRun::STATUS_PROCESSING, $run->status);
        $this->assertSame(2, $run->documents_sent);
        $this->assertSame(0.8, $run->threshold);
        $this->assertNotNull($run->started_at);

        Http::assertSent(function (Request $request) use ($run, $a, $b) {
            $docs = collect($request['documents']);

            return $request->header('X-API-Key') === ['test-engine-key']
                && $request['analysis_run_id'] === $run->id
                && $request['threshold'] === 0.8
                && $docs->pluck('submission_id')->sort()->values()->all() === [$a->id, $b->id]
                && str_contains($docs->first()['file_url'], "/storage/submissions/{$this->assignment->id}/");
        });
    }

    public function test_analysis_is_blocked_while_submission_is_open(): void
    {
        Http::fake();
        $this->assignment->update(['due_date' => now()->addDay()]);
        $this->addSubmission();
        $this->addSubmission();

        $this->trigger()->assertSessionHas('error');

        $this->assertDatabaseCount('analysis_runs', 0);
        Http::assertNothingSent();
    }

    public function test_analysis_needs_at_least_two_available_files(): void
    {
        Http::fake();
        $this->addSubmission();
        $this->addSubmission(withFile: false);

        $this->trigger()->assertSessionHas('error');
        $this->assertDatabaseCount('analysis_runs', 0);
    }

    public function test_second_run_is_blocked_while_first_is_active(): void
    {
        Http::fake([self::FASTAPI => Http::response([], 202)]);
        $this->addSubmission();
        $this->addSubmission();

        $this->trigger();
        $this->trigger()->assertSessionHas('error', 'Analisis sebelumnya masih berjalan.');

        $this->assertDatabaseCount('analysis_runs', 1);
    }

    public function test_unreachable_engine_marks_run_failed(): void
    {
        Http::fake([self::FASTAPI => Http::response('down', 500)]);
        $this->addSubmission();
        $this->addSubmission();

        $this->trigger()->assertSessionHas('error');

        $run = AnalysisRun::sole();
        $this->assertSame(AnalysisRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('HTTP 500', $run->error_message);
    }

    public function test_threshold_must_be_within_range(): void
    {
        Http::fake();
        $this->addSubmission();
        $this->addSubmission();

        $this->trigger(0.3)->assertSessionHasErrors('threshold');
        $this->trigger(1.2)->assertSessionHasErrors('threshold');
    }

    public function test_other_dosen_cannot_trigger(): void
    {
        $this->actingAs(User::factory()->dosen()->create())
            ->post(route('dosen.assignments.analysis.store', $this->assignment), ['threshold' => 0.7])
            ->assertForbidden();
    }

    public function test_completed_callback_stores_results_and_closes_run(): void
    {
        $a = $this->addSubmission();
        $b = $this->addSubmission();
        $run = $this->assignment->analysisRuns()->create([
            'status' => AnalysisRun::STATUS_PROCESSING, 'threshold' => 0.7, 'documents_sent' => 2, 'started_at' => now(),
        ]);

        $this->withHeader('X-API-Key', 'test-webhook-key')->postJson('/api/webhooks/similarity-result', [
            'assignment_id' => $this->assignment->id,
            'analysis_run_id' => $run->id,
            'status' => 'completed',
            'embedding_model' => 'paraphrase-multilingual-MiniLM-L12-v2',
            'total_documents' => 2,
            'total_pairs_evaluated' => 1,
            'flagged_count' => 1,
            'metrics' => ['tier1_seconds' => 0.4, 'llm_similarity_calls' => 1],
            'results' => [[
                'doc_a' => ['submission_id' => $a->id],
                'doc_b' => ['submission_id' => $b->id],
                'similarity_score' => 0.91,
                'is_flagged' => true,
                'llm_analysis' => ['verdict' => 'Tinggi', 'action_recommendation' => 'PERIKSA_MANUAL', 'summary' => 'Mirip', 'matched_segments' => []],
            ]],
            'ai_detections' => [
                ['submission_id' => $a->id, 'ai_probability' => 0.8, 'verdict' => 'LIKELY_AI'],
            ],
        ])->assertOk();

        $run->refresh();
        $this->assertSame(AnalysisRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->pairs_flagged);
        $this->assertSame('paraphrase-multilingual-MiniLM-L12-v2', $run->embedding_model);
        $this->assertSame(0.4, $run->metrics['tier1_seconds']);
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseCount('similarity_results', 1);
        $this->assertDatabaseCount('ai_detection_results', 1);
    }

    public function test_failed_callback_marks_run_failed(): void
    {
        $run = $this->assignment->analysisRuns()->create([
            'status' => AnalysisRun::STATUS_PROCESSING, 'threshold' => 0.7, 'documents_sent' => 2,
        ]);

        $this->withHeader('X-API-Key', 'test-webhook-key')->postJson('/api/webhooks/similarity-result', [
            'assignment_id' => $this->assignment->id,
            'analysis_run_id' => $run->id,
            'status' => 'failed',
            'error' => 'Hanya 1 dari 2 dokumen yang teksnya dapat diekstrak',
        ])->assertOk();

        $this->assertSame(AnalysisRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertStringContainsString('Hanya 1 dari 2', $run->fresh()->error_message);
    }

    public function test_stale_run_expires_and_status_endpoint_reports_it(): void
    {
        $run = $this->assignment->analysisRuns()->create([
            'status' => AnalysisRun::STATUS_PROCESSING, 'threshold' => 0.7, 'documents_sent' => 2,
        ]);
        $run->forceFill(['created_at' => now()->subHour()])->save();

        $this->actingAs($this->dosen)
            ->getJson(route('dosen.assignments.analysis.status', $this->assignment))
            ->assertOk()
            ->assertJson(['run_id' => $run->id, 'status' => 'failed']);
    }

    public function test_assignment_page_shows_analysis_panel_states(): void
    {
        $this->addSubmission();
        $this->addSubmission(withFile: false);

        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.show', $this->assignment))
            ->assertOk()
            ->assertSee('1 berkas tidak ditemukan di penyimpanan')
            ->assertSee('Dibutuhkan minimal 2 berkas');

        $this->addSubmission();
        $this->get(route('dosen.assignments.show', $this->assignment))->assertSee('Jalankan analisis');

        $this->assignment->analysisRuns()->create(['status' => AnalysisRun::STATUS_PROCESSING, 'threshold' => 0.7, 'documents_sent' => 2]);
        $this->get(route('dosen.assignments.show', $this->assignment))
            ->assertSee('Sedang diproses')
            ->assertSee('data-analysis-poll', false)
            ->assertDontSee('Jalankan analisis');
    }
}
