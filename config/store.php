<?php

use App\Services\Integrations\FakeStoreWebhook;
use App\Services\Store\FakeStoreGateway;

return [
    'driver' => env('STORE_DRIVER', 'fake'),
    // Register a concrete provider explicitly; unknown drivers never fall back to fake.
    'drivers' => [
        'fake' => ['gateway' => FakeStoreGateway::class, 'webhook' => FakeStoreWebhook::class, 'webhook_secret' => env('STORE_WEBHOOK_SECRET')],
    ],
];
