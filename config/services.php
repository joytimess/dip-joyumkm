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
        'url' => env('HERMES_WEBHOOK_URL'),
        'allowed_host' => env('HERMES_WEBHOOK_ALLOWED_HOST'),
        'secret' => env('HERMES_WEBHOOK_SECRET'),
        'timeout' => (int) env('HERMES_WEBHOOK_TIMEOUT_SECONDS', 5),
        'max_attempts' => (int) env('HERMES_WEBHOOK_MAX_ATTEMPTS', 5),
        'backoff_base' => (int) env('HERMES_WEBHOOK_BACKOFF_BASE_SECONDS', 30),
        'backoff_cap' => (int) env('HERMES_WEBHOOK_BACKOFF_CAP_SECONDS', 900),
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
