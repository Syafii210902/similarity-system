<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DosenCourseManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $dosen;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->dosen = User::factory()->dosen()->create();
        $this->course = Course::factory()->for($this->dosen, 'dosen')->create(['code' => 'IF101']);
    }

    public function test_dosen_creates_updates_and_deletes_course(): void
    {
        $this->actingAs($this->dosen)
            ->post(route('dosen.courses.store'), ['code' => 'rpl-201', 'name' => 'Rekayasa Perangkat Lunak'])
            ->assertSessionHasNoErrors();

        $course = Course::where('code', 'RPL-201')->sole();
        $this->assertSame($this->dosen->id, $course->dosen_id);

        $this->post(route('dosen.courses.store'), ['code' => 'IF101', 'name' => 'Duplikat'])
            ->assertSessionHasErrors('code');

        $this->put(route('dosen.courses.update', $course), ['code' => 'RPL-201', 'name' => 'RPL Lanjut'])
            ->assertSessionHasNoErrors();
        $this->assertSame('RPL Lanjut', $course->fresh()->name);

        $this->delete(route('dosen.courses.destroy', $course))->assertRedirect(route('dosen.courses.index'));
        $this->assertModelMissing($course);
    }

    public function test_dosen_cannot_manage_other_dosens_course(): void
    {
        $other = Course::factory()->create();

        $this->actingAs($this->dosen)->get(route('dosen.courses.show', $other))->assertForbidden();
        $this->put(route('dosen.courses.update', $other), ['code' => 'X1', 'name' => 'X'])->assertForbidden();
        $this->delete(route('dosen.courses.destroy', $other))->assertForbidden();
        $this->post(route('dosen.courses.assignments.store', $other), ['title' => 'X'])->assertForbidden();
    }

    public function test_dosen_enrolls_and_removes_students(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $notStudent = User::factory()->dosen()->create();

        $this->actingAs($this->dosen)
            ->post(route('dosen.courses.students.store', $this->course), ['student_ids' => [$a->id, $b->id]])
            ->assertSessionHas('success');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->course->mahasiswa()->pluck('users.id')->all());

        $this->post(route('dosen.courses.students.store', $this->course), ['student_ids' => [$notStudent->id]])
            ->assertSessionHasErrors('student_ids.0');

        $this->delete(route('dosen.courses.students.destroy', [$this->course, $a]))->assertSessionHas('success');
        $this->assertSame([$b->id], $this->course->mahasiswa()->pluck('users.id')->all());
    }

    public function test_dosen_creates_assignment_with_wib_deadline(): void
    {
        $this->actingAs($this->dosen)
            ->post(route('dosen.courses.assignments.store', $this->course), [
                'title' => 'Tugas Esai',
                'description' => 'Tulis esai.',
                'due_date' => '2030-01-15T23:59',
            ])
            ->assertSessionHasNoErrors();

        $assignment = Assignment::sole();
        $this->assertSame('2030-01-15 23:59', $assignment->due_date->format('Y-m-d H:i'));
        $this->assertSame('Asia/Jakarta', $assignment->due_date->getTimezone()->getName());

        $this->get(route('dosen.assignments.show', $assignment))->assertOk()->assertSee('Tugas Esai');
    }

    public function test_closing_assignment_blocks_student_changes_and_reopen_restores(): void
    {
        $student = User::factory()->create();
        $this->course->mahasiswa()->attach($student);
        $assignment = Assignment::factory()->for($this->course)->create();

        $this->actingAs($this->dosen)->post(route('dosen.assignments.close', $assignment))->assertSessionHas('success');
        $this->assertTrue($assignment->fresh()->isClosedManually());
        $this->assertFalse($assignment->fresh()->isOpen());

        $upload = fn () => $this->actingAs($student)->post(
            route('mahasiswa.submissions.store', $assignment),
            ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]
        );
        $upload()->assertForbidden();

        $this->actingAs($this->dosen)->post(route('dosen.assignments.reopen', $assignment));
        $this->assertTrue($assignment->fresh()->isOpen());
        $upload()->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_assignment_page_lists_submitted_and_missing_students(): void
    {
        [$done, $missing] = User::factory()->count(2)->sequence(['name' => 'Budi Sudah'], ['name' => 'Cici Belum'])->create();
        $this->course->mahasiswa()->attach([$done->id, $missing->id]);
        $assignment = Assignment::factory()->for($this->course)->create();

        $this->actingAs($done)->post(route('mahasiswa.submissions.store', $assignment), [
            'file' => UploadedFile::fake()->create('budi.pdf', 10, 'application/pdf'),
        ]);

        $this->actingAs($this->dosen)
            ->get(route('dosen.assignments.show', $assignment))
            ->assertOk()
            ->assertSeeInOrder(['Budi Sudah', 'budi.pdf', 'Cici Belum', 'Belum mengumpulkan'])
            ->assertSee('1 belum mengumpulkan');

        $submission = $assignment->submissions()->sole();
        $this->get(route('dosen.submissions.download', $submission))->assertOk()->assertDownload('budi.pdf');
    }

    public function test_each_student_appears_once_and_former_students_are_marked(): void
    {
        // Buat beberapa submission lain dulu agar id submission ≠ id user (memicu bug except() Eloquent).
        Assignment::factory()->count(3)->create()->each(fn ($a) => $a->submissions()->create([
            'user_id' => User::factory()->create()->id, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf',
        ]));

        [$current, $former] = User::factory()->count(2)->sequence(['name' => 'Masih Terdaftar'], ['name' => 'Sudah Keluar'])->create();
        $this->course->mahasiswa()->attach([$current->id, $former->id]);
        $assignment = Assignment::factory()->for($this->course)->create();
        foreach ([$current, $former] as $student) {
            $assignment->submissions()->create(['user_id' => $student->id, 'file_path' => 'a.pdf', 'file_name' => "{$student->id}.pdf"]);
        }
        $this->course->mahasiswa()->detach($former->id);
        Storage::disk('public')->put('a.pdf', 'isi');

        $html = $this->actingAs($this->dosen)->get(route('dosen.assignments.show', $assignment))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Masih Terdaftar'));
        $this->assertSame(1, substr_count($html, 'Sudah Keluar'));
        $this->assertSame(1, substr_count($html, 'tidak lagi terdaftar'));
    }

    public function test_deleting_assignment_removes_stored_files(): void
    {
        $student = User::factory()->create();
        $this->course->mahasiswa()->attach($student);
        $assignment = Assignment::factory()->for($this->course)->create();

        $this->actingAs($student)->post(route('mahasiswa.submissions.store', $assignment), [
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ]);
        $path = $assignment->submissions()->sole()->file_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->dosen)->delete(route('dosen.assignments.destroy', $assignment))
            ->assertRedirect(route('dosen.courses.show', $this->course));

        $this->assertModelMissing($assignment);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_mahasiswa_cannot_access_dosen_pages(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dosen.courses.index'))
            ->assertForbidden();
    }
}
