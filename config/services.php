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

    'openapi_company' => [
        'token' => env('OPENAPI_COMPANY_TOKEN'),
        'base_url' => env('OPENAPI_COMPANY_BASE_URL', 'https://test.company.openapi.com'),
        'timeout' => (int) env('OPENAPI_COMPANY_TIMEOUT', 10),
    ],

    'openapi_trust' => [
        'token' => env('OPENAPI_TRUST_TOKEN'),
        'base_url' => env('OPENAPI_TRUST_BASE_URL', 'https://test.trust.openapi.com'),
        'timeout' => (int) env('OPENAPI_TRUST_TIMEOUT', 10),
        'max_attempts' => (int) env('OPENAPI_TRUST_MAX_ATTEMPTS', 8),
        'poll_seconds' => (int) env('OPENAPI_TRUST_POLL_SECONDS', 30),
        'max_minutes' => (int) env('OPENAPI_TRUST_MAX_MINUTES', 30),
    ],

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

];
