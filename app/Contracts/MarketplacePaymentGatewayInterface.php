<?php

namespace App\Contracts;

use App\Models\Order;

interface MarketplacePaymentGatewayInterface
{
    public function createIntent(Order $order, string $idempotencyKey): array;
}
