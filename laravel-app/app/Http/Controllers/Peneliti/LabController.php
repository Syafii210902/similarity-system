<?php

namespace App\Http\Controllers\Peneliti;

use App\Http\Controllers\Controller;
use App\Models\ExperimentDataset;
use App\Models\ExperimentRun;
use Illuminate\View\View;

class LabController extends Controller
{
    public function index(): View
    {
        $datasets = ExperimentDataset::withCount(['documents', 'runs'])->latest()->get();
        $runs = ExperimentRun::with('dataset:id,name')->latest()->limit(8)->get();

        return view('peneliti.index', compact('datasets', 'runs'));
    }
}
