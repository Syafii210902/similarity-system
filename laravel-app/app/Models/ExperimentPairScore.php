<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentPairScore extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id', 'document_low_id', 'document_high_id',
        'document_score', 'passage_score', 'sentence_score', 'combined_score',
        'llm_checked', 'llm_action', 'llm_error', 'llm_result',
    ];

    protected $casts = [
        'document_score' => 'float',
        'passage_score' => 'float',
        'sentence_score' => 'float',
        'combined_score' => 'float',
        'llm_checked' => 'boolean',
        'llm_error' => 'boolean',
        'llm_result' => 'array',
    ];

    public function documentLow(): BelongsTo
    {
        return $this->belongsTo(ExperimentDocument::class, 'document_low_id');
    }

    public function documentHigh(): BelongsTo
    {
        return $this->belongsTo(ExperimentDocument::class, 'document_high_id');
    }
}
