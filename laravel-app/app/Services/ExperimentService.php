<?php

namespace App\Services;

use App\Models\ExperimentAiRepeat;
use App\Models\ExperimentAiScore;
use App\Models\ExperimentDataset;
use App\Models\ExperimentDocument;
use App\Models\ExperimentPairScore;
use App\Models\ExperimentRun;
use App\Models\User;
use App\Support\SampleCorpus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lab Pengujian: mengelola korpus uji & menjalankan eksperimen lewat FastAPI.
 * Laravel tidak melakukan pemrosesan NLP; FastAPI menghitung skor, Laravel menyimpan & mengevaluasi.
 */
class ExperimentService
{
    private const DISK = 'public';

    // ----------------------------------------------------------------- dokumen

    public function addDocument(ExperimentDataset $dataset, array $data, ?UploadedFile $file, ?string $text): ExperimentDocument
    {
        if ($file) {
            $extension = strtolower($file->getClientOriginalExtension());
            $path = $file->storeAs("experiments/{$dataset->id}", Str::uuid() . ".{$extension}", self::DISK);
            $fileName = $file->getClientOriginalName();
            $wordCount = $extension === 'txt' ? str_word_count((string) file_get_contents($file->getRealPath())) : null;
        } else {
            $path = "experiments/{$dataset->id}/" . Str::uuid() . '.txt';
            Storage::disk(self::DISK)->put($path, $text);
            $fileName = Str::slug($data['title']) . '.txt';
            $wordCount = count(preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY));
        }

        return $dataset->documents()->create([
            'title' => $data['title'],
            'category' => $data['category'],
            'source_document_id' => in_array($data['category'], ExperimentDocument::DERIVED, true) ? $data['source_document_id'] : null,
            'file_path' => $path,
            'file_name' => $fileName,
            'word_count' => $wordCount,
        ]);
    }

    public function deleteDocument(ExperimentDocument $document): void
    {
        $path = $document->file_path;
        $document->delete();
        Storage::disk(self::DISK)->delete($path);
    }

    public function deleteDataset(ExperimentDataset $dataset): void
    {
        $paths = $dataset->documents()->pluck('file_path')->all();
        $dataset->delete();
        Storage::disk(self::DISK)->delete($paths);
    }

    public function documentText(ExperimentDocument $document): ?string
    {
        return str_ends_with($document->file_path, '.txt') && Storage::disk(self::DISK)->exists($document->file_path)
            ? Storage::disk(self::DISK)->get($document->file_path)
            : null;
    }

    /**
     * Korpus contoh untuk mencoba alur Lab. BUKAN data penelitian: seluruh teks ditulis oleh AI.
     */
    public function createSampleDataset(User $user): ExperimentDataset
    {
        return DB::transaction(function () use ($user) {
            $dataset = ExperimentDataset::create([
                'name' => 'Korpus contoh (' . now()->translatedFormat('d M Y H:i') . ')',
                'description' => 'Contoh untuk mencoba alur Lab Pengujian. Semua teks ditulis oleh AI sehingga tidak boleh dipakai sebagai data penelitian.',
                'is_sample' => true,
                'created_by' => $user->id,
            ]);

            foreach (SampleCorpus::topics() as $topic) {
                $ids = [];
                foreach ($topic as $key => $doc) {
                    $created = $this->addDocument($dataset, [
                        'title' => $doc['title'],
                        'category' => $doc['category'],
                        'source_document_id' => isset($doc['source']) ? $ids[$doc['source']] : null,
                    ], null, $doc['text']);
                    $ids[$key] = $created->id;
                }
            }

            return $dataset;
        });
    }

    // ----------------------------------------------------------------- eksperimen

    public function activeRun(ExperimentDataset $dataset): ?ExperimentRun
    {
        $this->expireStaleRuns($dataset);

        return $dataset->runs()->whereIn('status', ExperimentRun::ACTIVE_STATUSES)->latest()->first();
    }

    /**
     * @throws RuntimeException
     */
    public function startRun(ExperimentDataset $dataset, User $user, array $config): ExperimentRun
    {
        $documents = $dataset->documents()->get();
        if ($documents->count() < 2) {
            throw new RuntimeException('Dataset membutuhkan minimal 2 dokumen.');
        }
        if ($this->activeRun($dataset)) {
            throw new RuntimeException('Masih ada eksperimen yang berjalan pada dataset ini.');
        }

        $run = $dataset->runs()->create([
            'created_by' => $user->id,
            'name' => $config['name'],
            'status' => 'pending',
            'config' => collect($config)->except('name')->all(),
        ]);

        $payload = [
            'analysis_run_id' => $run->id,
            'callback_url' => config('services.fastapi.experiment_callback_url'),
            'threshold' => $config['threshold'],
            'documents' => $documents->map(fn (ExperimentDocument $d) => [
                'submission_id' => $d->id,
                'student_name' => $d->title,
                'file_url' => Storage::disk(self::DISK)->url($d->file_path),
            ])->values()->all(),
            'config' => [
                'passage_max_words' => $config['passage_max_words'],
                'sentence_match_threshold' => $config['sentence_match_threshold'],
                'layer_weights' => $config['weights'],
                'run_tier2' => $config['run_tier2'],
                'run_ai_detection' => $config['run_ai_detection'],
            ],
        ];

        try {
            $response = $this->engine()->timeout(15)->post($this->engineUrl('/api/v1/analyze-similarity'), $payload);
        } catch (\Throwable $e) {
            return $this->fail($run, 'Layanan analisis tidak dapat dihubungi: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            return $this->fail($run, "Layanan analisis menolak permintaan (HTTP {$response->status()}): " . $response->body());
        }

        $run->update(['status' => 'processing', 'started_at' => now()]);

        return $run;
    }

    public function handleCallback(array $payload): void
    {
        $run = ExperimentRun::with('dataset')->findOrFail($payload['analysis_run_id'] ?? 0);

        if (($payload['status'] ?? null) !== 'completed') {
            $run->update([
                'status' => 'failed',
                'error_message' => $payload['error'] ?? 'Layanan analisis melaporkan kegagalan tanpa keterangan.',
                'metrics' => $payload['metrics'] ?? null,
                'finished_at' => now(),
            ]);

            return;
        }

        $validIds = $run->dataset->documents()->pluck('id')->flip();

        DB::transaction(function () use ($run, $payload, $validIds) {
            $run->pairScores()->delete();
            $run->aiScores()->delete();

            $rows = [];
            foreach ($payload['results'] ?? [] as $item) {
                $a = (int) ($item['doc_a']['submission_id'] ?? 0);
                $b = (int) ($item['doc_b']['submission_id'] ?? 0);
                if (!$validIds->has($a) || !$validIds->has($b) || $a === $b) {
                    continue;
                }
                $layers = $item['layer_scores'] ?? [];
                $llm = is_array($item['llm_analysis'] ?? null) ? $item['llm_analysis'] : null;

                $rows[] = [
                    'run_id' => $run->id,
                    'document_low_id' => min($a, $b),
                    'document_high_id' => max($a, $b),
                    'document_score' => $this->clamp($layers['document'] ?? 0),
                    'passage_score' => $this->clamp($layers['passage'] ?? 0),
                    'sentence_score' => $this->clamp($layers['sentence'] ?? 0),
                    'combined_score' => $this->clamp($item['similarity_score'] ?? 0),
                    'llm_checked' => $llm !== null,
                    'llm_action' => $llm['action_recommendation'] ?? null,
                    'llm_error' => (bool) ($llm['llm_error'] ?? false),
                    'llm_result' => $llm ? json_encode([
                        'verdict' => $llm['verdict'] ?? null,
                        'summary' => $llm['summary'] ?? null,
                        'segments' => array_count_values(array_column($llm['matched_segments'] ?? [], 'match_type')),
                    ]) : null,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                ExperimentPairScore::insert($chunk);
            }

            foreach ($payload['ai_detections'] ?? [] as $ai) {
                $id = (int) ($ai['submission_id'] ?? 0);
                if (!$validIds->has($id)) {
                    continue;
                }
                ExperimentAiScore::create([
                    'run_id' => $run->id,
                    'document_id' => $id,
                    'ai_probability' => is_numeric($ai['ai_probability'] ?? null) ? $this->clamp($ai['ai_probability'], 0) : null,
                    'verdict' => in_array($ai['verdict'] ?? null, ['HUMAN_WRITTEN', 'MIXED_AI', 'LIKELY_AI'], true) ? $ai['verdict'] : 'ERROR',
                    'analysis_summary' => $ai['analysis_summary'] ?? null,
                ]);
            }

            $run->update([
                'status' => 'completed',
                'metrics' => array_merge($payload['metrics'] ?? [], [
                    'embedding_model' => $payload['embedding_model'] ?? null,
                    'documents_processed' => $payload['total_documents'] ?? null,
                ]),
                'error_message' => null,
                'finished_at' => now(),
            ]);
        });
    }

    public function expireStaleRuns(ExperimentDataset $dataset): void
    {
        $minutes = (int) config('services.analysis.stale_after_minutes');

        $dataset->runs()
            ->whereIn('status', ExperimentRun::ACTIVE_STATUSES)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get()
            ->each(fn (ExperimentRun $run) => $this->fail($run, "Tidak ada respons dari layanan analisis dalam {$minutes} menit."));
    }

    // ----------------------------------------------------------------- uji konsistensi deteksi AI

    /** Tanpa kabar dari FastAPI selama ini (menit), proses dianggap terhenti. */
    public const CONSISTENCY_STALE_MINUTES = 10;

    /**
     * Ulangan deteksi AI yang belum ada atau gagal, untuk ulangan ke-2 s.d. $target.
     * Diurutkan per ulangan agar ulangan ke-2 lengkap dulu sebelum ulangan ke-3.
     *
     * @param  array<int>|null  $documentIds  null = semua dokumen run
     * @return array<int, array{document: ExperimentDocument, repeat: int}>
     */
    public function pendingAiRepeats(ExperimentRun $run, int $target, ?array $documentIds = null): array
    {
        $documents = $run->aiScores()->with('document')->get()->pluck('document')->filter()
            ->when($documentIds !== null, fn ($docs) => $docs->whereIn('id', $documentIds))
            ->sortBy('id')->values();
        $done = $run->aiRepeats()->where('verdict', '!=', 'ERROR')->get()
            ->map(fn ($r) => $r->document_id . ':' . $r->repeat)->flip();

        $pending = [];
        for ($repeat = 2; $repeat <= $target; $repeat++) {
            foreach ($documents as $document) {
                if (!$done->has($document->id . ':' . $repeat)) {
                    $pending[] = ['document' => $document, 'repeat' => $repeat];
                }
            }
        }

        return $pending;
    }

    /**
     * Mulai atau lanjutkan uji konsistensi. Ulangan yang sudah berhasil tidak dijalankan lagi.
     *
     * @param  array<int>|null  $documentIds
     *
     * @throws RuntimeException
     */
    public function startAiRepeat(ExperimentRun $run, int $target, ?array $documentIds = null): int
    {
        $this->expireStaleConsistency($run);
        if ($run->status !== 'completed' || !($run->config['run_ai_detection'] ?? false)) {
            throw new RuntimeException('Uji konsistensi hanya untuk eksperimen selesai yang menjalankan deteksi AI.');
        }
        if ($run->consistencyRunning()) {
            throw new RuntimeException('Uji konsistensi pada eksperimen ini masih berjalan.');
        }

        $pending = $this->pendingAiRepeats($run, $target, $documentIds);
        if ($pending === []) {
            throw new RuntimeException('Semua ulangan untuk pilihan ini sudah lengkap.');
        }

        $payload = [
            'analysis_run_id' => $run->id,
            'callback_url' => config('services.fastapi.experiment_ai_repeat_callback_url'),
            'passage_max_words' => $run->config['passage_max_words'] ?? null,
            'items' => array_map(fn ($p) => [
                'submission_id' => $p['document']->id,
                'student_name' => $p['document']->title,
                'file_url' => Storage::disk(self::DISK)->url($p['document']->file_path),
                'repeat' => $p['repeat'],
            ], $pending),
        ];

        try {
            $response = $this->engine()->timeout(15)->post($this->engineUrl('/api/v1/detect-ai-repeat'), $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException('Layanan analisis tidak dapat dihubungi: ' . $e->getMessage());
        }
        if (!$response->successful()) {
            throw new RuntimeException("Layanan analisis menolak permintaan (HTTP {$response->status()}).");
        }

        $run->update(['consistency' => [
            'status' => 'processing',
            'target' => max($target, (int) ($run->consistency['target'] ?? 0)),
            'queued' => count($pending),
            'message' => null,
            'updated_at' => now()->toIso8601String(),
        ]]);

        return count($pending);
    }

    public function handleAiRepeatCallback(array $payload): void
    {
        $run = ExperimentRun::findOrFail($payload['analysis_run_id'] ?? 0);
        $state = $run->consistency ?? [];
        $status = $payload['status'] ?? null;

        if ($status === 'item') {
            $item = $payload['item'] ?? [];
            $documentId = (int) ($item['submission_id'] ?? 0);
            $repeat = (int) ($item['repeat'] ?? 0);
            if ($repeat >= 2 && $run->dataset->documents()->whereKey($documentId)->exists()) {
                ExperimentAiRepeat::updateOrCreate(
                    ['run_id' => $run->id, 'document_id' => $documentId, 'repeat' => $repeat],
                    [
                        'ai_probability' => is_numeric($item['ai_probability'] ?? null) ? $this->clamp($item['ai_probability'], 0) : null,
                        'verdict' => in_array($item['verdict'] ?? null, ['HUMAN_WRITTEN', 'MIXED_AI', 'LIKELY_AI'], true) ? $item['verdict'] : 'ERROR',
                        'analysis_summary' => $item['analysis_summary'] ?? null,
                        'model' => $payload['model'] ?? null,
                    ]
                );
            }
        } else {
            $state['status'] = $status === 'stopped' ? 'stopped' : 'idle';
            $state['message'] = $payload['message'] ?? null;
        }

        $state['updated_at'] = now()->toIso8601String();
        $run->update(['consistency' => $state]);
    }

    public function expireStaleConsistency(ExperimentRun $run): void
    {
        $state = $run->consistency ?? [];
        if (($state['status'] ?? null) !== 'processing') {
            return;
        }
        $last = isset($state['updated_at']) ? Carbon::parse($state['updated_at']) : null;
        if ($last === null || $last->lt(now()->subMinutes(self::CONSISTENCY_STALE_MINUTES))) {
            $state['status'] = 'stopped';
            $state['message'] = 'Tidak ada kabar dari layanan analisis dalam ' . self::CONSISTENCY_STALE_MINUTES . ' menit. Lanjutkan untuk mencoba lagi.';
            $run->update(['consistency' => $state]);
        }
    }

    // ----------------------------------------------------------------- uji cepat

    /**
     * @throws RuntimeException
     */
    public function compareTexts(string $textA, string $textB, array $config): array
    {
        try {
            $response = $this->engine()->timeout(180)->post($this->engineUrl('/api/v1/compare-texts'), [
                'text_a' => $textA,
                'text_b' => $textB,
                'config' => $config,
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Layanan analisis tidak dapat dihubungi: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            throw new RuntimeException("Layanan analisis menolak permintaan (HTTP {$response->status()}).");
        }

        return $response->json();
    }

    // ----------------------------------------------------------------- helper

    private function engine()
    {
        return Http::withHeaders(['X-API-Key' => config('services.fastapi.api_key')])->connectTimeout(5);
    }

    private function engineUrl(string $path): string
    {
        return rtrim(config('services.fastapi.url'), '/') . $path;
    }

    private function fail(ExperimentRun $run, string $message): ExperimentRun
    {
        Log::warning("Experiment run #{$run->id} gagal: {$message}");
        $run->update(['status' => 'failed', 'error_message' => $message, 'finished_at' => now()]);

        return $run;
    }

    private function clamp(mixed $value, float $min = -1): float
    {
        return round(max($min, min(1, (float) $value)), 4);
    }
}
