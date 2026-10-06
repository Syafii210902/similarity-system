<?php

namespace App\Http\Controllers\Peneliti;

use App\Http\Controllers\Controller;
use App\Services\ExperimentService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Uji cepat dua teks: skor per lapis, passage bukti, dan analisis LLM (opsional).
 */
class CompareController extends Controller
{
    public function __construct(
        protected ExperimentService $experiments
    ) {}

    public function show(): View
    {
        return view('peneliti.compare', ['result' => null]);
    }

    public function run(Request $request): View
    {
        $data = $request->validate([
            'text_a' => ['required', 'string', 'min:20', 'max:200000'],
            'text_b' => ['required', 'string', 'min:20', 'max:200000'],
        ], [
            'text_a.required' => 'Teks A wajib diisi.',
            'text_b.required' => 'Teks B wajib diisi.',
            'min' => 'Teks minimal :min karakter.',
        ]);

        $result = null;
        $error = null;
        try {
            $result = $this->experiments->compareTexts($data['text_a'], $data['text_b'], [
                'run_tier2' => $request->boolean('run_tier2'),
                'run_ai_detection' => false,
            ]);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        return view('peneliti.compare', ['result' => $result, 'error' => $error, 'input' => $data + ['run_tier2' => $request->boolean('run_tier2')]]);
    }
}
