<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentAiRepeat extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['run_id', 'document_id', 'repeat', 'ai_probability', 'verdict', 'analysis_summary', 'model'];

    protected $casts = ['ai_probability' => 'float', 'repeat' => 'integer'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ExperimentDocument::class, 'document_id');
    }
}
