<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface SimilarityRepositoryInterface
{
    public function saveResults(array $data): void;
    public function getResultsByAssignment(int $assignmentId): Collection;
    public function deleteOldResults(int $assignmentId): void;
    public function saveAiDetectionResults(int $assignmentId, array $aiDetections): void;
}