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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        'min_confidence' => env('GEMINI_MIN_CONFIDENCE', 85),
        'mock' => env('GEMINI_MOCK', false),
    ],

    'xendit' => [
        'key' => env('XENDIT_API_KEY'),
        'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),
        'mock' => env('XENDIT_MOCK', false),
        'timeout' => env('XENDIT_TIMEOUT', 20),
        // Namespace untuk reference_id & idempotency key payout.
        // Opsional: bila kosong, diturunkan otomatis dari APP_ENV
        // (production→PROD, local→LOCAL; testing→tanpa prefix).
        // Isi manual hanya bila dua environment SAMA (mis. dua VPS
        // production) berbagi satu API key. Tanpa namespace yang beda,
        // claim id yang sama di dua tempat = 409 DUPLICATE_ERROR permanen.
        'key_prefix' => env('XENDIT_KEY_PREFIX', ''),
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
        'invoice_duration' => env('XENDIT_INVOICE_DURATION', 86400),
        'success_redirect_url' => env('XENDIT_SUCCESS_REDIRECT_URL'),
        'failure_redirect_url' => env('XENDIT_FAILURE_REDIRECT_URL'),
        'webhook_secret' => env('XENDIT_WEBHOOK_SECRET'),
        'retry_attempts' => env('XENDIT_RETRY_ATTEMPTS', 3),
        'retry_delay' => env('XENDIT_RETRY_DELAY', 300),
    ],

];
