<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'openwa' => [
        'enabled' => env('OPENWA_ENABLED', false),
        'base_url' => env('OPENWA_BASE_URL'),
        'session_id' => env('OPENWA_SESSION_ID'),
        'api_key' => env('OPENWA_API_KEY'),
        'timeout' => env('OPENWA_TIMEOUT_SECONDS', 3),
    ],

    'hermes_webhook' => [
        'enabled' => env('HERMES_WEBHOOK_ENABLED', false),
        'uat_scope_enabled' => env('HERMES_WEBHOOK_UAT_SCOPE_ENABLED', false),
        'url' => env('HERMES_WEBHOOK_URL'),
        'allowed_host' => env('HERMES_WEBHOOK_ALLOWED_HOST'),
        'secret' => env('HERMES_WEBHOOK_SECRET'),
        'timeout' => (int) env('HERMES_WEBHOOK_TIMEOUT_SECONDS', 5),
        'max_attempts' => (int) env('HERMES_WEBHOOK_MAX_ATTEMPTS', 5),
        'backoff_base' => (int) env('HERMES_WEBHOOK_BACKOFF_BASE_SECONDS', 30),
        'backoff_cap' => (int) env('HERMES_WEBHOOK_BACKOFF_CAP_SECONDS', 900),
    ],

    'hermes_intelligence_callback' => [
        'secret' => env('HERMES_INTELLIGENCE_CALLBACK_SECRET'),
        'tolerance_seconds' => (int) env('HERMES_INTELLIGENCE_CALLBACK_TOLERANCE_SECONDS', 300),
        'max_body_bytes' => 2 * 1024 * 1024,
    ],

    // Pull snapshot historis (GET, read-only) dari Hermes. Secret HARUS berbeda dari callback dan webhook.
    'hermes_intelligence_snapshot' => [
        'secret' => env('HERMES_INTELLIGENCE_SNAPSHOT_SECRET'),
        'tolerance_seconds' => (int) env('HERMES_INTELLIGENCE_SNAPSHOT_TOLERANCE_SECONDS', 300),
    ],

    // Unggah artefak PDF/XLSX (PUT biner). Secret HARUS berbeda dari callback, webhook, dan snapshot; minimal 32 karakter.
    'hermes_intelligence_artifact' => [
        'python' => env('INTELLIGENCE_PDF_PYTHON', PHP_OS_FAMILY === 'Windows' ? 'python.exe' : 'python3'),
        'qpdf' => env('INTELLIGENCE_PDF_QPDF', PHP_OS_FAMILY === 'Windows' ? 'qpdf.exe' : 'qpdf'),
        'secret' => env('HERMES_INTELLIGENCE_ARTIFACT_SECRET'),
        'tolerance_seconds' => (int) env('HERMES_INTELLIGENCE_ARTIFACT_TOLERANCE_SECONDS', 300),
    ],

    'hermes_intelligence_delivery_status' => [
        'secret' => env('HERMES_INTELLIGENCE_DELIVERY_STATUS_SECRET'),
        'tolerance_seconds' => env('HERMES_INTELLIGENCE_DELIVERY_STATUS_TOLERANCE_SECONDS', 300),
        'message_domain' => env('INTELLIGENCE_DELIVERY_STATUS_MESSAGE_DOMAIN'),
    ],

    'hermes_intelligence_recipient' => [
        'secret' => env('HERMES_INTELLIGENCE_RECIPIENT_SECRET'),
        'tolerance_seconds' => env('HERMES_INTELLIGENCE_RECIPIENT_TOLERANCE_SECONDS', 300),
        'ttl_seconds' => env('INTELLIGENCE_RECIPIENT_TTL_SECONDS', 60),
        'test_mode' => env('INTELLIGENCE_EMAIL_TEST_MODE', true),
        'test_allowlist' => env('INTELLIGENCE_EMAIL_TEST_ALLOWLIST', ''),
    ],

    'intelligence' => [
        // Kunci HMAC untuk identitas penerima (fingerprint). Minimal 32 karakter; jangan dibagikan ke Hermes.
        'recipient_pepper' => env('INTELLIGENCE_RECIPIENT_PEPPER'),
    ],

    'geocoding' => [
        'url' => env('GEOCODING_URL'),
        'allowed_hosts' => array_map(
            fn (string $host): string => strtolower(rtrim(trim($host), '.')),
            explode(',', (string) env('GEOCODING_ALLOWED_HOSTS', '')),
        ),
        'user_agent' => env('GEOCODING_USER_AGENT'),
        'timeout' => env('GEOCODING_TIMEOUT_SECONDS', 4),
        'cache_store' => env('GEOCODING_CACHE_STORE', 'database'),
        'tile_url' => env('OSM_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    ],

];
