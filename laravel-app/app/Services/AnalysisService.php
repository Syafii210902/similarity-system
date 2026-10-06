<?php

namespace App\Services;

use App\Models\AnalysisRun;
use App\Models\Assignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Memicu analisis ke FastAPI dan mengelola status AnalysisRun.
 * Seluruh pemrosesan NLP/LLM tetap di FastAPI; Laravel hanya mengirim daftar berkas.
 */
class AnalysisService
{
    private const DISK = 'public';

    /**
     * Pengumpulan yang berkasnya benar-benar ada di storage (yang akan dikirim ke FastAPI).
     *
     * @return array{ready: Collection<int, Submission>, missing: Collection<int, Submission>}
     */
    public function partitionSubmissions(Assignment $assignment): array
    {
        [$ready, $missing] = $assignment->submissions()
            ->with('user:id,name')
            ->get()
            ->partition(fn (Submission $s) => Storage::disk(self::DISK)->exists($s->file_path));

        return ['ready' => $ready->values(), 'missing' => $missing->values()];
    }

    /**
     * Alasan analisis belum bisa dijalankan, atau null jika siap.
     */
    public function blockingReason(Assignment $assignment, ?int $readyCount = null): ?string
    {
        if ($assignment->isOpen()) {
            return 'Pengumpulan masih dibuka. Tutup pengumpulan atau tunggu tenggat lewat.';
        }

        $readyCount ??= $this->partitionSubmissions($assignment)['ready']->count();
        if ($readyCount < 2) {
            return "Dibutuhkan minimal 2 berkas yang tersedia di penyimpanan (saat ini {$readyCount}).";
        }

        if ($this->activeRun($assignment)) {
            return 'Analisis sebelumnya masih berjalan.';
        }

        return null;
    }

    public function activeRun(Assignment $assignment): ?AnalysisRun
    {
        $this->expireStaleRuns($assignment);

        return $assignment->analysisRuns()->whereIn('status', AnalysisRun::ACTIVE_STATUSES)->latest()->first();
    }

    /**
     * @throws RuntimeException jika analisis tidak dapat dijalankan.
     */
    public function start(Assignment $assignment, User $dosen, float $threshold): AnalysisRun
    {
        ['ready' => $ready] = $this->partitionSubmissions($assignment);

        if ($reason = $this->blockingReason($assignment, $ready->count())) {
            throw new RuntimeException($reason);
        }

        $run = $assignment->analysisRuns()->create([
            'triggered_by' => $dosen->id,
            'status' => AnalysisRun::STATUS_PENDING,
            'threshold' => $threshold,
            'documents_sent' => $ready->count(),
        ]);

        $payload = [
            'assignment_id' => $assignment->id,
            'analysis_run_id' => $run->id,
            'callback_url' => config('services.fastapi.callback_url'),
            'threshold' => $threshold,
            'documents' => $ready->map(fn (Submission $s) => [
                'submission_id' => $s->id,
                'student_name' => $s->user?->name ?? "Mahasiswa #{$s->user_id}",
                'file_url' => Storage::disk(self::DISK)->url($s->file_path),
            ])->all(),
        ];

        try {
            $response = Http::withHeaders(['X-API-Key' => config('services.fastapi.api_key')])
                ->connectTimeout(5)
                ->timeout(15)
                ->post(rtrim(config('services.fastapi.url'), '/') . '/api/v1/analyze-similarity', $payload);
        } catch (\Throwable $e) {
            return $this->markFailed($run, 'Layanan analisis tidak dapat dihubungi: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            return $this->markFailed($run, "Layanan analisis menolak permintaan (HTTP {$response->status()}).");
        }

        $run->update(['status' => AnalysisRun::STATUS_PROCESSING, 'started_at' => now()]);

        return $run;
    }

    /**
     * Run yang terlalu lama tanpa callback ditandai gagal agar dosen bisa menjalankan ulang.
     */
    public function expireStaleRuns(Assignment $assignment): void
    {
        $limit = now()->subMinutes(config('services.analysis.stale_after_minutes'));

        $assignment->analysisRuns()
            ->whereIn('status', AnalysisRun::ACTIVE_STATUSES)
            ->where('created_at', '<', $limit)
            ->get()
            ->each(fn (AnalysisRun $run) => $this->markFailed(
                $run,
                'Tidak ada respons dari layanan analisis dalam ' . config('services.analysis.stale_after_minutes') . ' menit.'
            ));
    }

    public function markFailed(AnalysisRun $run, string $message): AnalysisRun
    {
        Log::warning("Analysis run #{$run->id} gagal: {$message}");

        $run->update([
            'status' => AnalysisRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
        ]);

        return $run;
    }
}
