<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ground truth dosen untuk satu dokumen: ditulis AI atau manusia.
 */
class AiLabel extends Model
{
    protected $fillable = [
        'assignment_id',
        'submission_id',
        'is_ai',
        'labeled_by',
    ];

    protected $casts = [
        'is_ai' => 'boolean',
    ];
}
