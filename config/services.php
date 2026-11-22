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
