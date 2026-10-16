<?php

namespace App\Services\Payments;

use App\Models\ExchangeDifferencePayment;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Services\ExchangeWorkflow;
use App\Services\StockReservations;
use Illuminate\Support\Facades\DB;
use Stripe\Webhook;

/** Verify original bytes before using any metadata. Never trust the success redirect. */
class ReceiveMarketplaceStripeEvent
{
    public function handle(string $body, string $signature): string
    {
        $secret = config('marketplace.stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('Marketplace webhook is not configured.');
        }
        $event = Webhook::constructEvent($body, $signature, $secret, 300);
        if ($event->livemode && config('marketplace.stripe.live_enabled') !== true) {
            throw new \InvalidArgumentException('Live marketplace webhook disabled.');
        }
        if ((bool) $event->livemode !== (config('marketplace.stripe.mode') === 'live')) {
            throw new \InvalidArgumentException('Stripe event environment mismatch.');
        }
        // Account-context events cannot settle a platform charge.
        if (! empty($event->account)) {
            throw new \InvalidArgumentException('Unexpected connected account event.');
        }
        $object = $event->data->object;
        $hash = hash('sha256', $body);
        if (! in_array($event->type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
            return 'ignored';
        }
        $kind = $object->metadata->marketplace_kind ?? null;
        $id = $object->metadata->marketplace_id ?? null;
        if (! in_array($kind, ['order', 'exchange'], true) || ! is_string($id) || ! ctype_digit($id)) {
            throw new \InvalidArgumentException('Invalid marketplace session metadata.');
        }
        $payment = $kind === 'order' ? MarketplacePayment::where('order_id', $id)->firstOrFail() : ExchangeDifferencePayment::where('exchange_version_id', $id)->firstOrFail();
        if ($payment->provider !== 'stripe_connect' || $payment->simulated || ($payment->reference !== null && $payment->reference !== $object->id) || $object->mode !== 'payment' || ! is_int($object->amount_total) || ($kind === 'order' ? ($payment->card_cents ?? $payment->amount_cents) : $payment->amount_cents) !== $object->amount_total || strtolower($payment->currency) !== $object->currency) {
            throw new \InvalidArgumentException('Marketplace session identity/amount mismatch.');
        }
        $target = $kind === 'order' ? Order::findOrFail($id) : $payment->version;
        $key = $kind === 'order' ? 'marketplace-order:'.$target->checkout_key : 'exchange:'.$target->exchange->uuid.':version:'.$target->id;
        if (! hash_equals(hash('sha256', $key), (string) ($object->metadata->marketplace_key ?? ''))) {
            throw new \InvalidArgumentException('Checkout request identity mismatch.');
        }
        $state = $event->type === 'checkout.session.expired' ? 'cancelled' : 'paid';
        if ($state === 'paid' && ($object->payment_status !== 'paid' || ! is_string($object->payment_intent) || ! str_starts_with($object->payment_intent, 'pi_'))) {
            throw new \InvalidArgumentException('Session has no successful payment.');
        }

        // Domain locks serialize duplicate events and stock; receipt is in the same transaction.
        return DB::transaction(function () use ($event, $object, $payment, $kind, $id, $hash, $state, $target) {
            $receipt = DB::table('marketplace_stripe_receipts')->where('event_id', $event->id)->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_hash, $hash)) {
                    throw new \LogicException('Stripe event reused with different data.');
                }

                return $receipt->outcome;
            }
            $bind = function () use ($payment, $object) {
                $current = $payment->newQuery()->lockForUpdate()->findOrFail($payment->id);
                if ($current->reference && $current->reference !== $object->id) {
                    throw new \LogicException('Provider session identity changed.');
                }
                if (! $current->reference) {
                    $current->update(['reference' => $object->id]);
                }
            };
            if ($kind === 'order') {
                app(StockReservations::class)->locked($target, $bind);
            } else {
                app(ExchangeWorkflow::class)->locked($target->exchange, $bind);
            }
            $outcome = $kind === 'order'
                ? app(ApplyMarketplacePaymentResult::class)->handle(Order::findOrFail($id), $event->id, $object->id, $state, $payment->amount_cents, $payment->currency, 'stripe_connect')
                : app(ApplyExchangePaymentResult::class)->handle($payment->version, $event->id, $object->id, $state, $payment->amount_cents, $payment->currency, 'stripe_connect');
            if ($state === 'paid') {
                $payment->update(['stripe_payment_intent_id' => $object->payment_intent]);
            }
            DB::table('marketplace_stripe_receipts')->insertOrIgnore(['event_id' => $event->id, 'event_type' => $event->type, 'object_id' => $object->id, 'payload_hash' => $hash, 'outcome' => $outcome, 'created_at' => now(), 'updated_at' => now()]);
            $stored = DB::table('marketplace_stripe_receipts')->where('event_id', $event->id)->lockForUpdate()->first();
            if (! hash_equals($stored->payload_hash, $hash)) {
                throw new \LogicException('Concurrent Stripe event identity conflict.');
            }

            return $outcome;
        }, 5);
    }
}
