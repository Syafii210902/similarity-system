<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExperimentRun extends Model
{
    public const ACTIVE_STATUSES = ['pending', 'processing'];

    protected $fillable = ['dataset_id', 'created_by', 'name', 'status', 'config', 'metrics', 'error_message', 'started_at', 'finished_at'];

    protected $casts = [
        'config' => 'array',
        'metrics' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ExperimentDataset::class, 'dataset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pairScores(): HasMany
    {
        return $this->hasMany(ExperimentPairScore::class, 'run_id');
    }

    public function aiScores(): HasMany
    {
        return $this->hasMany(ExperimentAiScore::class, 'run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return ['pending' => 'Menunggu', 'processing' => 'Sedang diproses', 'completed' => 'Selesai', 'failed' => 'Gagal'][$this->status] ?? $this->status;
    }

    public function durationLabel(): ?string
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }
        $seconds = (int) $this->started_at->diffInSeconds($this->finished_at, true);

        return $seconds < 60 ? "{$seconds} dtk" : intdiv($seconds, 60) . ' mnt ' . ($seconds % 60) . ' dtk';
    }
}
