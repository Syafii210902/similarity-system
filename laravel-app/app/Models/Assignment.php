<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'due_date',
        'closed_at',
    ];

    protected $casts = [
        'due_date' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Pengumpulan dibuka selama belum ditutup manual oleh dosen dan tenggat belum lewat.
     */
    public function isOpen(): bool
    {
        return !$this->isClosedManually() && !$this->isPastDue();
    }

    public function isClosedManually(): bool
    {
        return $this->closed_at !== null;
    }

    public function isPastDue(): bool
    {
        return $this->due_date !== null && now()->greaterThan($this->due_date);
    }

    /**
     * Label status pengumpulan untuk ditampilkan.
     */
    public function submissionStateLabel(): string
    {
        return match (true) {
            $this->isClosedManually() => 'Ditutup',
            $this->isPastDue() => 'Lewat tenggat',
            default => 'Dibuka',
        };
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function similarityResults(): HasMany
    {
        return $this->hasMany(SimilarityResult::class);
    }

    public function aiDetectionResults(): HasMany
    {
        return $this->hasMany(AiDetectionResult::class);
    }

    public function analysisRuns(): HasMany
    {
        return $this->hasMany(AnalysisRun::class);
    }

    public function latestAnalysisRun(): HasOne
    {
        return $this->hasOne(AnalysisRun::class)->latestOfMany();
    }
}
