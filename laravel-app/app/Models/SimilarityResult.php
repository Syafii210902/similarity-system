<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimilarityResult extends Model
{
    use HasFactory;

    protected $table = 'similarity_results';

    protected $fillable = [
        'assignment_id',
        'submission_a_id',
        'submission_b_id',
        'similarity_score',
        'layer_scores',
        'is_flagged',
        'verdict',
        'action_recommendation',
        'summary',
        'matched_segments',
    ];

    protected $casts = [
        'is_flagged' => 'boolean',
        'matched_segments' => 'array',
        'layer_scores' => 'array',
        'similarity_score' => 'float',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function submissionA(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_a_id');
    }

    public function submissionB(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'submission_b_id');
    }

    /**
     * Jumlah segmen per jenis: ['VERBATIM_COPY' => n, 'PARAPHRASED' => n].
     */
    public function segmentCounts(): array
    {
        $counts = ['VERBATIM_COPY' => 0, 'PARAPHRASED' => 0];
        foreach ($this->matched_segments ?? [] as $segment) {
            $type = $segment['match_type'] ?? 'PARAPHRASED';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }
}
