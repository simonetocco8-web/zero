<?php

// Initial catalog defaults. Persisted plan rows are the runtime source of truth.
return [
    'free' => [
        'name' => 'FREE',
        'currency' => 'EUR',
        'monthly_price_cents' => 0,
        'annual_price_cents' => 0,
        'max_items' => 5,
        'max_inventory_value_cents' => 1000000,
        'exchange_available' => false,
        'commission_basis_points' => 200,
    ],
    'pro' => [
        'name' => 'PRO',
        'currency' => 'EUR',
        'monthly_price_cents' => 6900,
        'annual_price_cents' => 49900,
        'max_items' => null,
        'max_inventory_value_cents' => null,
        'exchange_available' => true,
        'commission_basis_points' => 50,
    ],
];
