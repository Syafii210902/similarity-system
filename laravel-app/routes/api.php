<?php

use App\Http\Controllers\Api\ExperimentWebhookController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\VerifyAnalysisWebhookKey;
use Illuminate\Support\Facades\Route;

// Callback dari FastAPI setelah analisis selesai/gagal (dilindungi X-API-Key).
Route::post('/webhooks/similarity-result', [WebhookController::class, 'handleSimilarityResult'])
    ->middleware(VerifyAnalysisWebhookKey::class)
    ->name('api.webhooks.similarity');

Route::post('/webhooks/experiment-result', [ExperimentWebhookController::class, 'handle'])
    ->middleware(VerifyAnalysisWebhookKey::class)
    ->name('api.webhooks.experiment');
