<?php

namespace App\Http\Controllers\Peneliti;

use App\Http\Controllers\Controller;
use App\Models\ExperimentRun;
use App\Services\ExperimentEvaluator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Evaluasi gabungan: satu run per tugas (dataset) digabung menjadi hasil akhir penelitian.
 */
class CombinedController extends Controller
{
    public function __construct(
        protected ExperimentEvaluator $evaluator
    ) {}

    public function show(Request $request): View
    {
        $available = ExperimentRun::with('dataset')->where('status', 'completed')->latest()->get();
        $selectedIds = collect($request->input('runs', []))->map(fn ($id) => (int) $id)->unique()->values();
        $runs = $available->whereIn('id', $selectedIds)->sortBy('id')->values();

        return view('peneliti.combined', [
            'available' => $available,
            'selectedIds' => $selectedIds,
            'result' => $runs->count() >= 2 ? $this->evaluator->combined($runs->all()) : null,
        ]);
    }
}
