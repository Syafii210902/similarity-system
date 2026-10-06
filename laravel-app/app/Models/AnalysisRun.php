<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisRun extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING];

    protected $fillable = [
        'assignment_id',
        'triggered_by',
        'status',
        'threshold',
        'embedding_model',
        'documents_sent',
        'documents_processed',
        'pairs_evaluated',
        'pairs_flagged',
        'metrics',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'threshold' => 'float',
        'metrics' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_PENDING => 'Menunggu',
            self::STATUS_PROCESSING => 'Sedang diproses',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_FAILED => 'Gagal',
        ][$this->status] ?? $this->status;
    }

    /**
     * Durasi proses dalam detik (null jika belum selesai).
     */
    public function durationSeconds(): ?int
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at, true);
    }

    public function durationLabel(): ?string
    {
        $seconds = $this->durationSeconds();

        if ($seconds === null) {
            return null;
        }

        return $seconds < 60
            ? "{$seconds} dtk"
            : intdiv($seconds, 60) . ' mnt ' . ($seconds % 60) . ' dtk';
    }
}
