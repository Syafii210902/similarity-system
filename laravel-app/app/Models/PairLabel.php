<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ground truth dosen untuk satu pasangan dokumen: plagiat atau bukan.
 */
class PairLabel extends Model
{
    protected $fillable = [
        'assignment_id',
        'submission_low_id',
        'submission_high_id',
        'is_plagiarism',
        'note',
        'labeled_by',
    ];

    protected $casts = [
        'is_plagiarism' => 'boolean',
    ];

    public static function keyFor(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    public function key(): string
    {
        return self::keyFor($this->submission_low_id, $this->submission_high_id);
    }

    public function labeledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'labeled_by');
    }
}
