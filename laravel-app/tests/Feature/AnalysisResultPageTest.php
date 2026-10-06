<?php

namespace Tests\Feature;

use App\Models\AiDetectionResult;
use App\Models\AnalysisRun;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisResultPageTest extends TestCase
{
    use RefreshDatabase;

    private User $dosen;
    private Assignment $assignment;
    /** @var array<int, Submission> */
    private array $subs;
    private SimilarityResult $flaggedPair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dosen = User::factory()->dosen()->create();
        $this->assignment = Assignment::factory()
            ->for(Course::factory()->for($this->dosen, 'dosen'))
            ->closed()
            ->create();

        $this->subs = collect(['Ani', 'Budi', 'Cici'])->map(fn ($name) => $this->assignment->submissions()->create([
            'user_id' => User::factory()->create(['name' => $name])->id,
            'file_path' => "{$name}.pdf",
            'file_name' => "{$name}.pdf",
        ]))->all();

        $this->flaggedPair = $this->pair(0, 1, 0.91, true, [
            ['text_doc_a' => 'Kalimat salinan persis', 'text_doc_b' => 'Kalimat salinan persis', 'match_type' => 'VERBATIM_COPY'],
            ['text_doc_a' => 'Gagasan asli A', 'text_doc_b' => 'Gagasan yang disusun ulang', 'match_type' => 'PARAPHRASED'],
        ]);
        $this->pair(0, 2, 0.32, false);
        $this->pair(1, 2, -0.05, false);

        AiDetectionResult::create(['assignment_id' => $this->assignment->id, 'submission_id' => $this->subs[0]->id, 'ai_probability' => 0.82, 'verdict' => 'LIKELY_AI', 'analysis_summary' => 'Pola transisi berulang', 'flagged_patterns' => ['Penting untuk dicatat bahwa']]);
        AiDetectionResult::create(['assignment_id' => $this->assignment->id, 'submission_id' => $this->subs[1]->id, 'ai_probability' => null, 'verdict' => 'ERROR', 'analysis_summary' => 'Deteksi AI gagal']);

        $this->assignment->analysisRuns()->create([
            'status' => AnalysisRun::STATUS_COMPLETED, 'threshold' => 0.7, 'documents_sent' => 3,
            'embedding_model' => 'paraphrase-multilingual-MiniLM-L12-v2', 'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);
    }

    private function pair(int $a, int $b, float $score, bool $flagged, array $segments = []): SimilarityResult
    {
        return SimilarityResult::create([
            'assignment_id' => $this->assignment->id,
            'submission_a_id' => $this->subs[$a]->id,
            'submission_b_id' => $this->subs[$b]->id,
            'similarity_score' => $score,
            'is_flagged' => $flagged,
            'verdict' => $flagged ? 'Indikasi plagiarisme tinggi' : 'Di bawah ambang batas',
            'action_recommendation' => $flagged ? 'PERIKSA_MANUAL' : 'AMAN',
            'summary' => $flagged ? 'Banyak kalimat sama' : null,
            'matched_segments' => $segments,
        ]);
    }

    public function test_results_page_lists_pairs_matrix_and_ai_detection(): void
    {
        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.results', $this->assignment))
            ->assertOk()
            ->assertSeeInOrder(['0,910', '0,320', '-0,050'])
            ->assertSeeText('1 salinan langsung')
            ->assertSee('Periksa manual')
            ->assertSee('data-heatmap', false)
            ->assertSee('Kemungkinan AI')
            ->assertSee('82%')
            ->assertSee('Gagal dianalisis')
            ->assertSee('Penting untuk dicatat bahwa')
            ->assertSee('paraphrase-multilingual-MiniLM-L12-v2');
    }

    public function test_results_page_shows_llm_model_and_token_usage(): void
    {
        $this->assignment->analysisRuns()->latest('id')->first()->update(['metrics' => [
            'llm' => ['provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'effort' => 'low'],
            'llm_usage' => ['calls' => 4, 'failures' => 0, 'input_tokens' => 12345, 'output_tokens' => 2100],
        ]]);

        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.results', $this->assignment))
            ->assertOk()
            ->assertSee('LLM claude-opus-5-5 (effort low)')
            ->assertSeeText('12.345');
    }

    public function test_pair_page_shows_segments_side_by_side(): void
    {
        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.results.pair', [$this->assignment, $this->flaggedPair]))
            ->assertOk()
            ->assertSee('Ani × Budi')
            ->assertSee('Kalimat salinan persis')
            ->assertSee('Gagasan yang disusun ulang')
            ->assertSee('Salinan langsung')
            ->assertSee('Parafrase')
            ->assertSee('0,910');
    }

    public function test_pair_from_another_assignment_returns_404(): void
    {
        $other = Assignment::factory()->for($this->assignment->course)->create();

        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.results.pair', [$other, $this->flaggedPair]))
            ->assertNotFound();
    }

    public function test_other_dosen_and_students_cannot_see_results(): void
    {
        $this->actingAs(User::factory()->dosen()->create())
            ->get(route('dosen.assignments.results', $this->assignment))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('dosen.assignments.results', $this->assignment))
            ->assertForbidden();
    }

    public function test_empty_state_when_not_analyzed(): void
    {
        $fresh = Assignment::factory()->for($this->assignment->course)->create();

        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.results', $fresh))
            ->assertOk()
            ->assertSee('Belum ada hasil analisis');
    }

    public function test_assignment_page_links_to_results_and_uses_modal_confirmation(): void
    {
        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.show', $this->assignment))
            ->assertSee('Lihat hasil analisis')
            ->assertSee('data-confirm-title="Hapus tugas ini?"', false)
            ->assertSee('id="confirm-modal"', false)
            ->assertDontSee('confirm(', false);
    }
}
