<?php

return [
    'key' => env('STRIPE_KEY'), 'secret' => env('STRIPE_SECRET'), 'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'prices' => ['monthly' => env('STRIPE_PRO_MONTHLY_PRICE_ID'), 'yearly' => env('STRIPE_PRO_YEARLY_PRICE_ID')],
    'webhook_tolerance' => 300,
];
