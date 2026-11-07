<?php

return [
    'enabled' => env('SHIPPING_QUOTES_ENABLED', false),
    'provider' => env('SHIPPING_PROVIDER', 'paccofacile'),
    // No fiscal/funds-flow contract has yet been verified with Paccofacile.
    'checkout_verified' => false,
    'ttl_seconds' => (int) env('SHIPPING_QUOTE_TTL_SECONDS', 300),
    'cache_seconds' => (int) env('SHIPPING_QUOTE_CACHE_SECONDS', 60),
    'retention_days' => 7,
    'max_parcels' => 100,
    'paccofacile' => [
        'environment' => env('PACCOFACILE_ENVIRONMENT', 'sandbox'),
        'api_key' => env('PACCOFACILE_API_KEY'),
        'token' => env('PACCOFACILE_TOKEN'),
        'account_number' => env('PACCOFACILE_ACCOUNT_NUMBER'),
    ],
];
