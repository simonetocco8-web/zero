<?php

return [
    'enabled' => env('SHIPPING_QUOTES_ENABLED', false),
    'provider' => env('SHIPPING_PROVIDER', 'sendcloud'),
    // Informational quotations only; transport VAT and collection are not approved.
    'checkout_verified' => false,
    'ttl_seconds' => (int) env('SHIPPING_QUOTE_TTL_SECONDS', 300),
    'cache_seconds' => (int) env('SHIPPING_QUOTE_CACHE_SECONDS', 60),
    'retention_days' => 7,
    'max_parcels' => 100,
    'sendcloud' => [
        'public_key' => env('SENDCLOUD_API_PUBLIC_KEY'),
        'private_key' => env('SENDCLOUD_API_PRIVATE_KEY'),
        'timeout' => (int) env('SENDCLOUD_API_TIMEOUT', 15),
    ],
    // Historical inactive provider; never selected by the current binding.
    'paccofacile' => [
        'environment' => env('PACCOFACILE_ENVIRONMENT', 'sandbox'),
        'api_key' => env('PACCOFACILE_API_KEY'),
        'token' => env('PACCOFACILE_TOKEN'),
        'account_number' => env('PACCOFACILE_ACCOUNT_NUMBER'),
    ],
];
