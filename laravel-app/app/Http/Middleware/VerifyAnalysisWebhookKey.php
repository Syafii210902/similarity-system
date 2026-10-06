<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya FastAPI (pemegang ANALYSIS_WEBHOOK_KEY) yang boleh mengirim hasil analisis.
 */
class VerifyAnalysisWebhookKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.fastapi.webhook_key');

        if ($expected === '') {
            Log::error('ANALYSIS_WEBHOOK_KEY belum diset; callback analisis ditolak.');

            return response()->json(['status' => 'error', 'message' => 'Webhook belum dikonfigurasi.'], 503);
        }

        if (!hash_equals($expected, (string) $request->header('X-API-Key'))) {
            Log::warning('Callback analisis ditolak: X-API-Key tidak valid.', ['ip' => $request->ip()]);

            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
