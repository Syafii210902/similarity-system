<?php

namespace App\Http\Controllers\Dosen;

use App\Http\Controllers\Controller;
use App\Models\AiLabel;
use App\Models\Assignment;
use App\Models\PairLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validasi manual dosen (ground truth) untuk evaluasi efektivitas metode.
 */
class GroundTruthController extends Controller
{
    public function storePair(Request $request, Assignment $assignment): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $assignment);

        $belongs = Rule::exists('submissions', 'id')->where('assignment_id', $assignment->id);
        $data = $request->validate([
            'submission_a_id' => ['required', 'integer', $belongs],
            'submission_b_id' => ['required', 'integer', 'different:submission_a_id', $belongs],
            'label' => ['required', Rule::in(['plagiarism', 'not_plagiarism', 'clear'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $low = min($data['submission_a_id'], $data['submission_b_id']);
        $high = max($data['submission_a_id'], $data['submission_b_id']);
        $key = ['submission_low_id' => $low, 'submission_high_id' => $high];

        if ($data['label'] === 'clear') {
            PairLabel::where($key)->delete();
        } else {
            PairLabel::updateOrCreate($key, [
                'assignment_id' => $assignment->id,
                'is_plagiarism' => $data['label'] === 'plagiarism',
                'note' => $data['note'] ?? null,
                'labeled_by' => $request->user()->id,
            ]);
        }

        return $this->respond($request, $data['label'], 'Validasi pasangan disimpan.');
    }

    public function storeAi(Request $request, Assignment $assignment): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage', $assignment);

        $data = $request->validate([
            'submission_id' => ['required', 'integer', Rule::exists('submissions', 'id')->where('assignment_id', $assignment->id)],
            'label' => ['required', Rule::in(['ai', 'human', 'clear'])],
        ]);

        if ($data['label'] === 'clear') {
            AiLabel::where('submission_id', $data['submission_id'])->delete();
        } else {
            AiLabel::updateOrCreate(['submission_id' => $data['submission_id']], [
                'assignment_id' => $assignment->id,
                'is_ai' => $data['label'] === 'ai',
                'labeled_by' => $request->user()->id,
            ]);
        }

        return $this->respond($request, $data['label'], 'Validasi deteksi AI disimpan.');
    }

    private function respond(Request $request, string $label, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['label' => $label === 'clear' ? null : $label])
            : back()->with('success', $message);
    }
}
