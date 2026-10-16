<?php

namespace App\Contracts;

use App\Models\ExchangeVersion;

interface ExchangePaymentGatewayInterface
{
    public function createDifferenceIntent(ExchangeVersion $version, string $idempotencyKey): array;
}
