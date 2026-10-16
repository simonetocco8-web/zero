<?php

namespace App\Services\Payments;

use App\Contracts\ExchangePaymentGatewayInterface;
use App\Contracts\MarketplacePaymentGatewayInterface;
use App\Models\ExchangeVersion;
use App\Models\Order;
use App\Services\MarketplaceEconomicRules;

class FakeMarketplacePaymentGateway implements ExchangePaymentGatewayInterface, MarketplacePaymentGatewayInterface
{
    public function createDifferenceIntent(ExchangeVersion $version, string $idempotencyKey): array
    {
        app(MarketplaceEconomicRules::class)->exchange();

        return ['provider' => 'fake_marketplace', 'reference' => 'fake-exchange-'.hash('sha256', $idempotencyKey), 'status' => 'payment_pending', 'amount_cents' => $version->payment()->value('card_cents') ?? $version->difference_cents, 'currency' => $version->currency, 'simulated' => true];
    }

    public function createIntent(Order $order, string $idempotencyKey): array
    {
        app(MarketplaceEconomicRules::class)->sale();

        return ['provider' => 'fake_marketplace', 'reference' => 'fake-'.hash('sha256', $idempotencyKey), 'status' => 'payment_pending', 'amount_cents' => $order->payment->card_cents ?? $order->total_cents, 'currency' => $order->currency, 'simulated' => true];
    }
}
