<?php

namespace App\Services\Payments;

use App\Contracts\MarketplacePaymentGatewayInterface;
use App\Models\Order;
use App\Services\CustomerPromotionalBonus;
use App\Services\MarketplaceEconomicRules;
use App\Services\StockReservations;
use Illuminate\Support\Arr;

class StartMarketplacePayment
{
    public function handle(Order $order): void
    {
        app(MarketplaceEconomicRules::class)->sale();
        $stock = app(StockReservations::class);
        $shouldStart = $stock->locked($order, function ($current, $payment, $reservations, $items, $lines) use ($stock) {
            if ($payment->status === 'paid') {
                return false;
            }
            if (! $stock->isActive($current, $reservations, $lines) || ! $stock->marketable($items) || ! app(CustomerPromotionalBonus::class)->active($current)) {
                $stock->fail();
            }

            if (! $payment->reference) {
                $payment->update(['provider' => app(MarketplaceEconomicRules::class)->provider(), 'simulated' => ! app(MarketplaceEconomicRules::class)->isStripe()]);
            }

            return ! $payment->reference;
        });
        if (! $shouldStart) {
            return;
        }
        // No network/provider call within SQL locks. Ambiguous errors retain stock until expiry.
        $order = $order->fresh();
        $intent = app(MarketplacePaymentGatewayInterface::class)->createIntent($order, 'marketplace-order:'.$order->checkout_key);
        if (($intent['provider'] ?? null) !== app(MarketplaceEconomicRules::class)->provider() || ($intent['simulated'] ?? null) !== ! app(MarketplaceEconomicRules::class)->isStripe() || ($intent['status'] ?? null) !== 'payment_pending' || ($intent['amount_cents'] ?? null) !== ($order->payment->card_cents ?? $order->total_cents) || ($intent['currency'] ?? null) !== $order->currency || ! is_string($intent['reference'] ?? null) || $intent['reference'] === '' || strlen($intent['reference']) > 191) {
            throw new \RuntimeException('Invalid simulated payment intent.');
        }
        $stock->locked($order, function ($current, $payment, $reservations, $items, $lines) use ($stock, $intent) {
            if ($payment->reference && $payment->reference !== $intent['reference']) {
                throw new \LogicException('Idempotent gateway returned a different intent.');
            }
            if (! $payment->reference) {
                $payment->update(Arr::only($intent, ['provider', 'reference', 'currency', 'simulated', 'checkout_url']));
            }
            if (! $stock->isActive($current, $reservations, $lines) && $current->status->value === 'payment_pending') {
                $stock->releaseLocked($current, $payment, $reservations, 'expired');
            }
        });
    }
}
