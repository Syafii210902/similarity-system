<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExperimentDocument extends Model
{
    public const CATEGORIES = [
        'ORIGINAL' => 'Asli',
        'VERBATIM_COPY' => 'Salinan langsung',
        'PARAPHRASE_MANUAL' => 'Parafrase manual',
        'PARAPHRASE_AI' => 'Parafrase oleh AI',
        'INDEPENDENT' => 'Independen (topik sama)',
        'AI_GENERATED' => 'Ditulis AI',
    ];

    /** Kategori turunan wajib menunjuk dokumen sumber. */
    public const DERIVED = ['VERBATIM_COPY', 'PARAPHRASE_MANUAL', 'PARAPHRASE_AI'];

    /** Label ground truth deteksi AI. */
    public const AI_WRITTEN = ['PARAPHRASE_AI', 'AI_GENERATED'];

    /** Urutan tingkat penyamaran (dipakai untuk kategori pasangan). */
    public const OBFUSCATION_ORDER = ['VERBATIM_COPY' => 1, 'PARAPHRASE_MANUAL' => 2, 'PARAPHRASE_AI' => 3];

    protected $fillable = ['dataset_id', 'title', 'category', 'source_document_id', 'file_path', 'file_name', 'word_count'];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ExperimentDataset::class, 'dataset_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_document_id');
    }

    public function isDerived(): bool
    {
        return in_array($this->category, self::DERIVED, true);
    }

    public function isAiWritten(): bool
    {
        return in_array($this->category, self::AI_WRITTEN, true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
