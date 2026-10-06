<?php

namespace App\Http\Controllers\Dosen;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Services\EvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvaluationController extends Controller
{
    public function __construct(
        protected EvaluationService $evaluation
    ) {}

    public function index(Request $request): View
    {
        $assignment = $this->selectedAssignment($request);

        $assignments = Assignment::whereIn('course_id', $request->user()->coursesTaught()->select('id'))
            ->with('course:id,code')
            ->orderBy('title')
            ->get(['id', 'title', 'course_id']);

        return view('dosen.evaluation.index', [
            'assignment' => $assignment,
            'assignments' => $assignments,
            'summary' => $this->evaluation->summarize($request->user(), $assignment),
            'layers' => EvaluationService::LAYERS,
        ]);
    }

    /**
     * Unduh data berlabel sebagai CSV (UTF-8, pemisah koma, desimal titik) untuk diolah di Excel/SPSS/Python.
     */
    public function export(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['pairs', 'ai'], true), 404);

        $assignment = $this->selectedAssignment($request);
        $ids = $this->evaluation->assignmentIdsFor($request->user(), $assignment);

        if ($type === 'pairs') {
            $header = ['tugas', 'mahasiswa_a', 'mahasiswa_b', 'skor_gabungan', 'skor_dokumen', 'skor_passage', 'skor_kalimat',
                'melewati_ambang', 'rekomendasi_llm', 'segmen_salinan', 'segmen_parafrase', 'label_plagiat'];
            $rows = $this->evaluation->labeledPairs($ids)->map(function (array $row) {
                $r = $row['result'];
                $segments = $r->segmentCounts();

                return [
                    $r->assignment?->title, $r->submissionA?->user?->name, $r->submissionB?->user?->name,
                    $row['scores']['combined'], $row['scores']['document'], $row['scores']['passage'], $row['scores']['sentence'],
                    (int) $r->is_flagged, $r->is_flagged ? $r->action_recommendation : '', $segments['VERBATIM_COPY'], $segments['PARAPHRASED'],
                    (int) $row['actual'],
                ];
            });
        } else {
            $header = ['tugas', 'mahasiswa', 'probabilitas_ai', 'verdict', 'label_ai'];
            $rows = $this->evaluation->labeledAiResults($ids)->map(fn (array $row) => [
                $row['result']->submission?->assignment?->title, $row['result']->submission?->user?->name,
                $row['result']->ai_probability, $row['result']->verdict, (int) $row['actual'],
            ]);
        }

        $filename = 'evaluasi-' . ($type === 'pairs' ? 'kemiripan' : 'deteksi-ai') . ($assignment ? "-tugas-{$assignment->id}" : '') . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function selectedAssignment(Request $request): ?Assignment
    {
        if (!$request->filled('assignment')) {
            return null;
        }

        $assignment = Assignment::findOrFail($request->integer('assignment'));
        Gate::authorize('manage', $assignment);

        return $assignment;
    }
}
