<?php

// Explicit fake-only regression fixture. Never loaded by application bootstrap.
return [
    'marketplace.economic_rules' => [
        'execution_mode' => 'simulation', 'funds_flow' => 'simulated_vendor_allocations',
        'sale_commission_basis_points' => ['free' => 200, 'pro' => 50],
        'sale_commission_base' => 'taxable_goods_excluding_shipping',
        'exchange_commission_basis_points' => 50, 'exchange_commission_base' => 'paid_difference', 'exchange_commission_charged_to' => 'payee',
        'vat_rules' => 'descriptive_snapshots_v1', 'invoicing_rules' => 'retailer_goods_platform_service_v1',
        'shipping_rules' => 'buyer_sales_receiver_exchange_v1', 'cancellation_rules' => 'pre_dispatch_review_v1', 'refund_rules' => 'full_pre_dispatch_review_after_v1',
    ],
    'marketplace.reservation_minutes' => 30,
    'marketplace.exchange_difference_payment_minutes' => 1440,
];
