<?php

namespace App\Services;

use App\Models\AiDetectionResult;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\SimilarityResult;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class DashboardService
{
    /**
     * Ringkasan sistem untuk Admin.
     */
    public function getAdminSummary(): array
    {
        $usersByRole = User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        return [
            'stats' => [
                'admin' => (int) ($usersByRole[User::ROLE_ADMIN] ?? 0),
                'dosen' => (int) ($usersByRole[User::ROLE_DOSEN] ?? 0),
                'mahasiswa' => (int) ($usersByRole[User::ROLE_MAHASISWA] ?? 0),
                'courses' => Course::count(),
                'assignments' => Assignment::count(),
                'submissions' => Submission::count(),
                'pairs_evaluated' => SimilarityResult::count(),
                'pairs_flagged' => SimilarityResult::where('is_flagged', true)->count(),
                'likely_ai' => AiDetectionResult::where('verdict', 'LIKELY_AI')->count(),
            ],
            'recentUsers' => User::latest()->limit(5)->get(['id', 'name', 'email', 'role', 'created_at']),
            'engineOnline' => $this->isAnalysisEngineOnline(),
        ];
    }

    /**
     * Ringkasan mata kuliah & tugas milik Dosen yang sedang login.
     */
    public function getDosenSummary(User $dosen): array
    {
        $courseIds = $dosen->coursesTaught()->pluck('id');

        $assignments = Assignment::query()
            ->whereIn('course_id', $courseIds)
            ->with(['course:id,code,name', 'latestAnalysisRun'])
            ->withCount([
                'submissions',
                'similarityResults',
                'similarityResults as flagged_count' => fn ($q) => $q->where('is_flagged', true),
                'aiDetectionResults as likely_ai_count' => fn ($q) => $q->where('verdict', 'LIKELY_AI'),
            ])
            ->latest()
            ->get();

        return [
            'stats' => [
                'courses' => $courseIds->count(),
                'assignments' => $assignments->count(),
                'submissions' => $assignments->sum('submissions_count'),
                'pairs_flagged' => $assignments->sum('flagged_count'),
                'likely_ai' => $assignments->sum('likely_ai_count'),
            ],
            'assignments' => $assignments->take(10),
        ];
    }

    /**
     * Mata kuliah yang diikuti & status pengumpulan tugas Mahasiswa yang sedang login.
     */
    public function getMahasiswaSummary(User $mahasiswa): array
    {
        $courses = $mahasiswa->coursesEnrolled()
            ->with('dosen:id,name')
            ->withCount('assignments')
            ->orderBy('name')
            ->get();

        $assignments = Assignment::query()
            ->whereIn('course_id', $courses->pluck('id'))
            ->with([
                'course:id,code,name',
                'submissions' => fn ($q) => $q->where('user_id', $mahasiswa->id),
            ])
            ->get();

        [$submitted, $notSubmitted] = $assignments->partition(fn (Assignment $a) => $a->submissions->isNotEmpty());
        $pending = $notSubmitted->filter(fn (Assignment $a) => $a->isOpen());

        // Tugas terbuka yang belum dikumpulkan, tenggat terdekat lebih dulu (tanpa tenggat di akhir).
        $upcoming = $pending
            ->sortBy(fn (Assignment $a) => $a->due_date?->getTimestamp() ?? PHP_INT_MAX)
            ->take(5)
            ->values();

        return [
            'stats' => [
                'courses' => $courses->count(),
                'assignments' => $assignments->count(),
                'submitted' => $submitted->count(),
                'pending' => $pending->count(),
            ],
            'courses' => $courses,
            'upcoming' => $upcoming,
        ];
    }

    /**
     * Health check ringan ke FastAPI engine (timeout pendek agar dashboard tidak lambat).
     */
    private function isAnalysisEngineOnline(): bool
    {
        try {
            return Http::connectTimeout(2)
                ->timeout(2)
                ->get(config('services.fastapi.url'))
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
