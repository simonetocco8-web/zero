<?php

namespace App\Services\Payments;

use App\Enums\MarketplaceNoticeKind;
use App\Models\Order;
use App\Services\CustomerPromotionalBonus;
use App\Services\MarketplaceEconomicRules;
use App\Services\MarketplaceLedger;
use App\Services\MarketplaceNotices;
use App\Services\RetailerCredit;
use App\Services\StockReservations;
use Illuminate\Support\Facades\DB;

/** Internal trusted adapter boundary. Never invoke with unverified browser/webhook data. */
class ApplyMarketplacePaymentResult
{
    public function handle(Order $order, string $eventId, string $reference, string $status, int $amountCents, string $currency, string $provider = 'fake_marketplace'): string
    {
        if ($eventId === '' || strlen($eventId) > 191 || ! in_array($status, ['paid', 'failed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid normalized marketplace payment event.');
        }
        $hash = hash('sha256', json_encode([$order->id, $reference, $status, $amountCents, $currency], JSON_THROW_ON_ERROR));
        $stock = app(StockReservations::class);

        return $stock->locked($order, function ($order, $payment, $reservations, $items, $lines) use ($eventId, $reference, $status, $amountCents, $currency, $hash, $stock, $provider) {
            if ($payment->provider !== $provider || ! in_array($provider, ['fake_marketplace', 'stripe_connect'], true) || $payment->simulated !== ($provider === 'fake_marketplace') || ! $payment->reference || ! hash_equals($payment->reference, $reference) || $amountCents !== $order->total_cents || $amountCents !== $payment->amount_cents || $currency !== $order->currency || $currency !== $payment->currency) {
                throw new \InvalidArgumentException('Payment identity or amount does not match the immutable order.');
            }
            $receipt = DB::table('marketplace_payment_events')->where('provider', $payment->provider)->where('event_id', $eventId)->lockForUpdate()->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_hash, $hash)) {
                    throw new \LogicException('Event ID reused with different data.');
                }

                return $receipt->outcome;
            }
            if ($payment->status === 'refunded') {
                $outcome = 'ignored_after_refund';
            } elseif ($payment->status === 'paid') {
                $outcome = $status === 'paid' ? 'already_paid' : 'ignored_after_paid';
            } elseif ($status !== 'paid') {
                $stock->releaseLocked($order, $payment, $reservations, $status);
                $outcome = 'released';
            } elseif (! $stock->isActive($order, $reservations, $lines)) {
                $stock->releaseLocked($order, $payment, $reservations, 'expired');
                // Retain a durable signal for a future provider's late-capture reconciliation.
                $outcome = 'reconciliation_required';
            } elseif (! app(CustomerPromotionalBonus::class)->active($order) || ! app(RetailerCredit::class)->active($order)) {
                $stock->releaseLocked($order, $payment, $reservations, 'expired');
                $outcome = 'reconciliation_required';
            } elseif (! $stock->marketable($items)) {
                $stock->releaseLocked($order, $payment, $reservations, 'cancelled');
                $outcome = 'reconciliation_required';
            } elseif ($order->totals_provisional) {
                // User-selected fiscal policy: no simulated capture until VAT is defined.
                $outcome = 'tax_unresolved';
            } else {
                app(MarketplaceEconomicRules::class)->sale();
                foreach ($reservations as $reservation) {
                    $item = $items->get($reservation->inventory_item_id);
                    $q = $reservation->quantity_milliunits;
                    if (! $item || $item->quantity_milliunits < $q) {
                        throw new \LogicException('Reserved physical stock was modified outside the stock domain.');
                    }
                    // Explicit guarded decrement + DB nonnegative constraint; any failure rolls back all vendors.
                    $changed = DB::table('inventory_items')->where('id', $item->id)->where('quantity_milliunits', '>=', $q)->update(['quantity_milliunits' => DB::raw('quantity_milliunits - '.$q), 'stock_version' => DB::raw('stock_version + 1'), 'updated_at' => now()]);
                    if ($changed !== 1) {
                        throw new \LogicException('Stock consumption failed.');
                    }
                    $reservation->update(['status' => 'consumed', 'consumed_at' => now()]);
                }
                app(CustomerPromotionalBonus::class)->finish($order, true);
                app(RetailerCredit::class)->finish($order, true);
                $payment->update(['status' => 'paid']);
                $order->update(['status' => 'paid']);
                $order->vendorOrders()->update(['status' => 'paid']);
                foreach ($order->vendorOrders()->get() as $vendor) {
                    app(MarketplaceLedger::class)->creditVendor($vendor);
                }
                app(MarketplaceNotices::class)->orderEvent($order, MarketplaceNoticeKind::PaymentConfirmed);
                $outcome = 'consumed';
            }
            DB::table('marketplace_payment_events')->insert(['order_id' => $order->id, 'provider' => $payment->provider, 'event_id' => $eventId, 'payload_hash' => $hash, 'outcome' => $outcome, 'created_at' => now(), 'updated_at' => now()]);

            return $outcome;
        });
    }
}
