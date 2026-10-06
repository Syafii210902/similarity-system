<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fastapi' => [
        'url' => env('FASTAPI_URL', 'http://fastapi:8000'),
        // Dikirim sebagai X-API-Key ke FastAPI.
        'api_key' => env('FASTAPI_API_KEY'),
        // Wajib cocok dengan X-API-Key pada callback dari FastAPI.
        'webhook_key' => env('ANALYSIS_WEBHOOK_KEY'),
        // URL yang dipanggil FastAPI (dari dalam network Docker) setelah analisis selesai/gagal.
        'callback_url' => env('ANALYSIS_CALLBACK_URL', 'http://laravel/api/webhooks/similarity-result'),
        'experiment_callback_url' => env('EXPERIMENT_CALLBACK_URL', 'http://laravel/api/webhooks/experiment-result'),
    ],

    'analysis' => [
        'default_threshold' => (float) env('ANALYSIS_DEFAULT_THRESHOLD', 0.70),
        'min_threshold' => 0.50,
        'max_threshold' => 0.95,
        // Run yang tidak mendapat callback selama ini dianggap gagal (mis. container FastAPI restart).
        'stale_after_minutes' => (int) env('ANALYSIS_STALE_AFTER_MINUTES', 30),
    ],

];
