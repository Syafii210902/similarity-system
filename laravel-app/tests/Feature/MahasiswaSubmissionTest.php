<?php

namespace Tests\Feature;

use App\Models\AiDetectionResult;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MahasiswaSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private User $student;
    private Course $course;
    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->student = User::factory()->create();
        $this->course = Course::factory()->create();
        $this->course->mahasiswa()->attach($this->student);
        $this->assignment = Assignment::factory()->for($this->course)->create();
    }

    private function submissionUrl(?Assignment $assignment = null): string
    {
        return route('mahasiswa.submissions.store', $assignment ?? $this->assignment);
    }

    private function pdf(string $name = 'tugas.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 200, 'application/pdf');
    }

    public function test_enrolled_student_sees_course_and_assignment(): void
    {
        $this->actingAs($this->student)
            ->get(route('mahasiswa.dashboard'))
            ->assertOk()
            ->assertSee($this->course->name)
            ->assertSee($this->assignment->title);

        $this->get(route('mahasiswa.courses.show', $this->course))->assertOk()->assertSee('Belum dikumpulkan');
        $this->get(route('mahasiswa.assignments.show', $this->assignment))->assertOk()->assertSee('Pilih berkas tugas');
    }

    public function test_student_cannot_open_course_they_are_not_enrolled_in(): void
    {
        $otherAssignment = Assignment::factory()->create();

        $this->actingAs($this->student)
            ->get(route('mahasiswa.courses.show', $otherAssignment->course))
            ->assertForbidden();

        $this->get(route('mahasiswa.assignments.show', $otherAssignment))->assertForbidden();
        $this->post($this->submissionUrl($otherAssignment), ['file' => $this->pdf()])->assertForbidden();
    }

    public function test_student_can_upload_pdf_and_docx(): void
    {
        $this->actingAs($this->student)
            ->post($this->submissionUrl(), ['file' => $this->pdf('Laporan Akhir.pdf')])
            ->assertRedirect(route('mahasiswa.assignments.show', $this->assignment))
            ->assertSessionHas('success');

        $submission = Submission::sole();
        $this->assertSame('Laporan Akhir.pdf', $submission->file_name);
        $this->assertStringStartsWith("submissions/{$this->assignment->id}/", $submission->file_path);
        $this->assertStringEndsWith('.pdf', $submission->file_path);
        Storage::disk('public')->assertExists($submission->file_path);

        $this->get(route('mahasiswa.assignments.show', $this->assignment))
            ->assertOk()
            ->assertSee('Laporan Akhir.pdf')
            ->assertSee(' KB')
            ->assertSee('Ganti berkas')
            ->assertSee('Sudah dikumpulkan');

        $second = Assignment::factory()->for($this->course)->create();
        $this->post($this->submissionUrl($second), ['file' => UploadedFile::fake()->create('esai.docx', 50, self::DOCX_MIME)])
            ->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.docx', $second->submissions()->sole()->file_path);
    }

    public function test_upload_rejects_other_formats_and_oversized_files(): void
    {
        $this->actingAs($this->student);

        $this->post($this->submissionUrl(), ['file' => UploadedFile::fake()->create('tugas.doc', 50, 'application/msword')])
            ->assertSessionHasErrors('file');
        $this->post($this->submissionUrl(), ['file' => UploadedFile::fake()->create('script.php', 1, 'text/x-php')])
            ->assertSessionHasErrors('file');
        $this->post($this->submissionUrl(), ['file' => UploadedFile::fake()->create('besar.pdf', 11 * 1024, 'application/pdf')])
            ->assertSessionHasErrors('file');
        $this->post($this->submissionUrl(), [])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_student_cannot_submit_twice(): void
    {
        $this->actingAs($this->student)->post($this->submissionUrl(), ['file' => $this->pdf()]);
        $this->post($this->submissionUrl(), ['file' => $this->pdf('lagi.pdf')])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_student_can_replace_file_and_old_analysis_is_cleared(): void
    {
        $this->actingAs($this->student)->post($this->submissionUrl(), ['file' => $this->pdf('v1.pdf')]);
        $submission = Submission::sole();
        $oldPath = $submission->file_path;

        AiDetectionResult::create([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'ai_probability' => 0.9,
            'verdict' => 'LIKELY_AI',
        ]);

        $this->put(route('mahasiswa.submissions.update', $this->assignment), [
            'file' => UploadedFile::fake()->create('v2.docx', 80, self::DOCX_MIME),
        ])->assertSessionHas('success');

        $submission->refresh();
        $this->assertSame('v2.docx', $submission->file_name);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($submission->file_path);
        $this->assertDatabaseCount('ai_detection_results', 0);
    }

    public function test_student_can_delete_submission(): void
    {
        $this->actingAs($this->student)->post($this->submissionUrl(), ['file' => $this->pdf()]);
        $path = Submission::sole()->file_path;

        $this->delete(route('mahasiswa.submissions.destroy', $this->assignment))->assertSessionHas('success');

        $this->assertDatabaseCount('submissions', 0);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_student_can_download_own_file(): void
    {
        $this->actingAs($this->student)->post($this->submissionUrl(), ['file' => $this->pdf('Tugasku.pdf')]);

        $this->get(route('mahasiswa.submissions.download', $this->assignment))
            ->assertOk()
            ->assertDownload('Tugasku.pdf');
    }

    public function test_submission_is_locked_after_deadline(): void
    {
        $closed = Assignment::factory()->for($this->course)->closed()->create();
        $submission = $closed->submissions()->create([
            'user_id' => $this->student->id,
            'file_path' => 'submissions/x.pdf',
            'file_name' => 'x.pdf',
        ]);

        $this->actingAs($this->student)
            ->get(route('mahasiswa.assignments.show', $closed))
            ->assertOk()
            ->assertSee('Pengumpulan sudah ditutup')
            ->assertDontSee('Ganti berkas');

        $this->put(route('mahasiswa.submissions.update', $closed), ['file' => $this->pdf()])->assertForbidden();
        $this->delete(route('mahasiswa.submissions.destroy', $closed))->assertForbidden();
        $this->assertModelExists($submission);
    }

    public function test_dosen_cannot_use_student_submission_routes(): void
    {
        $this->actingAs($this->course->dosen)
            ->post($this->submissionUrl(), ['file' => $this->pdf()])
            ->assertForbidden();
    }
}
