<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiDetectionResult extends Model
{
    use HasFactory;

    protected $table = 'ai_detection_results';

    protected $fillable = [
        'assignment_id',
        'submission_id',
        'ai_probability',
        'verdict',
        'analysis_summary',
        'flagged_patterns',
    ];

    protected $casts = [
        'ai_probability' => 'float',
        'flagged_patterns' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }
}