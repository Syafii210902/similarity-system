<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExperimentDataset extends Model
{
    protected $fillable = ['name', 'description', 'is_sample', 'created_by'];

    protected $casts = ['is_sample' => 'boolean'];

    public function documents(): HasMany
    {
        return $this->hasMany(ExperimentDocument::class, 'dataset_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ExperimentRun::class, 'dataset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
