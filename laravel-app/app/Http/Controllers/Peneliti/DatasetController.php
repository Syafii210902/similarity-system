<?php

namespace App\Http\Controllers\Peneliti;

use App\Http\Controllers\Controller;
use App\Models\ExperimentDataset;
use App\Models\ExperimentDocument;
use App\Services\ExperimentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DatasetController extends Controller
{
    public function __construct(
        protected ExperimentService $experiments
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], ['name.required' => 'Nama dataset wajib diisi.']);

        $dataset = ExperimentDataset::create($data + ['created_by' => $request->user()->id]);

        return redirect()->route('peneliti.datasets.show', $dataset)->with('success', 'Dataset dibuat. Tambahkan dokumen berlabel.');
    }

    public function storeSample(Request $request): RedirectResponse
    {
        $dataset = $this->experiments->createSampleDataset($request->user());

        return redirect()->route('peneliti.datasets.show', $dataset)->with('success', 'Korpus contoh dibuat (14 dokumen). Ingat: hanya untuk mencoba alur, bukan data penelitian.');
    }

    public function show(ExperimentDataset $dataset): View
    {
        $this->experiments->expireStaleRuns($dataset);

        $documents = $dataset->documents()->with('source:id,title')->orderBy('id')->get();
        $runs = $dataset->runs()->latest()->get();
        $categoryCounts = $documents->countBy('category');

        // Jumlah pasangan plagiat yang terbentuk otomatis (keluarga dokumen yang sama).
        $root = function (ExperimentDocument $doc) use ($documents) {
            $seen = [];
            while ($doc->source_document_id && !isset($seen[$doc->id]) && $documents->firstWhere('id', $doc->source_document_id)) {
                $seen[$doc->id] = true;
                $doc = $documents->firstWhere('id', $doc->source_document_id);
            }

            return $doc->id;
        };
        $familySizes = $documents->groupBy($root)->map->count();
        $positivePairs = $familySizes->sum(fn ($n) => intdiv($n * ($n - 1), 2));
        $totalPairs = intdiv($documents->count() * ($documents->count() - 1), 2);

        return view('peneliti.datasets.show', [
            'dataset' => $dataset,
            'documents' => $documents,
            'runs' => $runs,
            'categoryCounts' => $categoryCounts,
            'positivePairs' => $positivePairs,
            'totalPairs' => $totalPairs,
            'activeRun' => $runs->first(fn ($r) => $r->isActive()),
            'defaults' => [
                'threshold' => config('services.analysis.default_threshold'),
                'weights' => [0.2, 0.4, 0.4],
                'passage_max_words' => 60,
                'sentence_match_threshold' => 0.85,
            ],
        ]);
    }

    public function destroy(ExperimentDataset $dataset): RedirectResponse
    {
        $this->experiments->deleteDataset($dataset);

        return redirect()->route('peneliti.dashboard')->with('success', 'Dataset beserta dokumen dan eksperimennya dihapus.');
    }
}
