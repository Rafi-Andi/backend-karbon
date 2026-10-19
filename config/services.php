<?php

/*
| Penyedia AI vision untuk validasi foto sampah (lihat App\Services\GeminiService).
|
| AI_DRIVER=gemini (default)      -> Google AI Studio / Vertex AI Express.
| AI_DRIVER=openai|groq|openrouter -> endpoint OpenAI-compatible (Bearer token).
|
| Saat driver BUKAN gemini, nilai GEMINI_MODEL/GEMINI_BASE_URL/
| GEMINI_ENDPOINT_PATH/GEMINI_RELAY_KEY sengaja DIABAIKAN supaya sisa .env
| Google tidak terpakai di penyedia lain (model & URL-nya beda). Pakai AI_*.
*/
$aiDriver = strtolower(trim((string) env('AI_DRIVER', env('GEMINI_DRIVER', 'gemini'))));
$aiDriverIsGemini = $aiDriver === '' || $aiDriver === 'gemini';

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

    // Validasi foto sampah (AI vision, server-side).
    // Nama array tetap "gemini" (bukan "ai") supaya config lama & cache tetap valid,
    // tetapi penyedianya bisa ditukar lewat AI_DRIVER.
    //
    // Driver gemini (default): Google AI Studio / Vertex AI Express, auth ?key=.
    // Driver openai          : endpoint OpenAI-compatible (Groq, OpenRouter, dst),
    //                          auth Authorization: Bearer.
    //
    // Semua kunci punya fallback ke GEMINI_* agar .env lama tidak perlu diubah
    // (fallback itu hanya aktif saat driver = gemini, lihat komentar di atas).
    'gemini' => [
        'driver' => $aiDriver === '' ? 'gemini' : $aiDriver,
        'key' => env('AI_API_KEY', env('GEMINI_API_KEY')),
        'model' => env('AI_MODEL', $aiDriverIsGemini ? env('GEMINI_MODEL') : null),
        'min_confidence' => env('AI_MIN_CONFIDENCE', env('GEMINI_MIN_CONFIDENCE', 85)),
        // GEMINI_MOCK lama sengaja tidak berlaku saat driver bukan gemini:
        // AI asli wajib eksplisit (AI_MOCK=false) supaya sisa .env Google
        // tidak diam-diam menghidupkan mode mock di penyedia lain.
        'mock' => env('AI_MOCK', $aiDriverIsGemini ? env('GEMINI_MOCK', false) : false),
        // IP VPS/cloud sering diblokir Google ("User location is not supported").
        // Kosongkan = panggil penyedia langsung. Isi bila perlu jalur lain:
        // - proxy   : http://user:pass@host:port (proxy residensial)
        // - base_url: relay/proxy sendiri (mis. https://relay.contoh.id)
        'proxy' => env('AI_PROXY', env('GEMINI_PROXY')),
        'base_url' => env('AI_BASE_URL', $aiDriverIsGemini ? env('GEMINI_BASE_URL') : null),
        // Path endpoint; {model} diganti nama model.
        // Default per driver (lihat GeminiService):
        //   gemini : /v1beta/models/{model}:generateContent
        //   openai : /chat/completions
        // Vertex AI Express Mode (auth tetap ?key=, body sama):
        //   AI_BASE_URL=https://aiplatform.googleapis.com
        //   AI_ENDPOINT_PATH=/v1/publishers/google/models/{model}:generateContent
        'endpoint_path' => env('AI_ENDPOINT_PATH', $aiDriverIsGemini ? env('GEMINI_ENDPOINT_PATH') : null),
        // Header X-Relay-Key untuk relay pribadi (opsional, pengaman).
        'relay_key' => env('AI_RELAY_KEY', $aiDriverIsGemini ? env('GEMINI_RELAY_KEY') : null),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder' => env('CLOUDINARY_FOLDER', 'karbonkita'),
        'mock' => env('CLOUDINARY_MOCK', false),
        'timeout' => env('CLOUDINARY_TIMEOUT', 30),
    ],

    // Halaman monitoring akses juri (/monitoring?token=...).
    // Token kosong = halaman nonaktif (selalu 404).
    'monitoring' => [
        'token' => env('MONITORING_TOKEN'),
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
