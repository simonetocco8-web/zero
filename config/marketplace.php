<?php

// Environment files contain strings. Reject malformed/overflowing amounts rather than coercing them to zero.
$integer = static function (string $key, ?int $default = null): ?int {
    $value = env($key, $default);
    if ($default === null && ($value === null || $value === '')) {
        return null;
    }
    if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^-?[0-9]+$/D', (string) $value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Invalid integer configuration: '.$key);
    }

    return (int) $value;
};

return [
    // PDF v1.0: approval of rules does not enable live collection.
    'provider' => 'stripe_connect',
    'commission_tax' => ['treatment' => env('MARKETPLACE_COMMISSION_VAT_TREATMENT'), 'rate_basis_points' => $integer('MARKETPLACE_COMMISSION_VAT_BASIS_POINTS')],
    'stripe' => [
        'secret' => env('MARKETPLACE_STRIPE_SECRET'),
        'mode' => env('MARKETPLACE_STRIPE_MODE', 'test'),
        'connected_account_country' => env('MARKETPLACE_STRIPE_CONNECTED_ACCOUNT_COUNTRY', 'IT'),
        'webhook_secret' => env('MARKETPLACE_STRIPE_WEBHOOK_SECRET'),
        'activation_verified' => env('MARKETPLACE_STRIPE_ACTIVATION_VERIFIED', false),
        'tax_verified' => env('MARKETPLACE_TAX_VERIFIED', false),
        'live_enabled' => env('MARKETPLACE_STRIPE_LIVE_ENABLED', false),
    ],
    'credit' => ['retailer_spending_enabled' => env('MARKETPLACE_RETAILER_CREDIT_ENABLED', true)],
    'bonus' => ['validity_months' => 12, 'maximum_goods_basis_points' => 1000],
    'settlement' => ['delivery_hold_hours' => 72, 'payout_iso_weekday' => 1, 'provider_cost_allocation' => 'proportional_received_amount'],
    'economic_rules' => [
        'execution_mode' => env('MARKETPLACE_EXECUTION_MODE', 'disabled'),
        'funds_flow' => env('MARKETPLACE_FUNDS_FLOW', 'stripe_connect_separate_charges_transfers'),
        'sale_commission_basis_points' => ['free' => 200, 'pro' => 50],
        'sale_commission_base' => env('MARKETPLACE_SALE_COMMISSION_BASE', 'taxable_goods_excluding_shipping'),
        'exchange_commission_basis_points' => $integer('MARKETPLACE_EXCHANGE_COMMISSION_BASIS_POINTS', 50),
        'exchange_commission_base' => env('MARKETPLACE_EXCHANGE_COMMISSION_BASE', 'paid_difference'),
        'exchange_commission_charged_to' => env('MARKETPLACE_EXCHANGE_COMMISSION_CHARGED_TO', 'payee'),
        'vat_rules' => env('MARKETPLACE_VAT_RULES', 'descriptive_snapshots_v1'),
        'invoicing_rules' => env('MARKETPLACE_INVOICING_RULES', 'retailer_goods_platform_service_v1'),
        'shipping_rules' => env('MARKETPLACE_SHIPPING_RULES', 'buyer_sales_receiver_exchange_v1'),
        'cancellation_rules' => env('MARKETPLACE_CANCELLATION_RULES', 'pre_dispatch_review_v1'),
        'refund_rules' => env('MARKETPLACE_REFUND_RULES', 'full_pre_dispatch_review_after_v1'),
    ],
    'reservation_minutes' => $integer('MARKETPLACE_RESERVATION_MINUTES', 30),
    'exchange_difference_payment_minutes' => $integer('EXCHANGE_DIFFERENCE_PAYMENT_MINUTES', 1440),
    'exchange_proposal_hours' => $integer('MARKETPLACE_EXCHANGE_PROPOSAL_HOURS', 48),
    'notifications' => ['channels' => ['database', 'mail'], 'queue_connection' => env('MARKETPLACE_NOTIFICATION_QUEUE_CONNECTION', 'database'), 'queue' => 'marketplace-notifications'],
    // Legacy exchange inputs only; sales use explicit per-product rates. No default VAT rate.
    'tax_rates_basis_points' => [],
    'featured_item_ids' => [],
    'vat_treatments' => ['included' => 'IVA inclusa', 'excluded' => 'IVA esclusa', 'not_applicable' => 'IVA non applicabile'],
];
