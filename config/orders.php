<?php

return [
    // external: provider owns stock. deduct_only: local deduction, no automatic returns.
    // restore_on_full_refund: return locally deducted units only after the whole line is refunded.
    'stock_rule' => env('ORDER_STOCK_RULE', 'external'),
    'allow_partial_refunds' => (bool) env('ORDER_ALLOW_PARTIAL_REFUNDS', true),
];
