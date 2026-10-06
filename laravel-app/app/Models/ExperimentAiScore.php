<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentAiScore extends Model
{
    public $timestamps = false;

    protected $fillable = ['run_id', 'document_id', 'ai_probability', 'verdict', 'analysis_summary'];

    protected $casts = ['ai_probability' => 'float'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ExperimentDocument::class, 'document_id');
    }
}
