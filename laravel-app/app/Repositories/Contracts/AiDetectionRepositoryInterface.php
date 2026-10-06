<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface AiDetectionRepositoryInterface
{
    public function saveAiDetectionResults(int $assignmentId, array $aiDetections): void;
}