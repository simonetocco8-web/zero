<?php

namespace App\Services\Payments;

use App\Models\ExchangeDifferencePayment;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceEconomicRules;
use App\Services\StockReservations;
use Illuminate\Support\Facades\DB;

class ReconcileMarketplacePayments
{
    public function handle(): void
    {
        if (! app(MarketplaceEconomicRules::class)->isStripe()) {
            return;
        }
        DB::table('marketplace_provider_refunds')->whereIn('status', ['requested', 'pending'])->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $r) {
                app(RefundMarketplaceVendor::class)->process($r->id);
            }
        });
        foreach ([MarketplacePayment::class, ExchangeDifferencePayment::class] as $model) {
            $model::where('provider', 'stripe_connect')->where('simulated', false)->whereNotNull('stripe_payment_intent_id')->whereIn('status', ['expired', 'failed', 'cancelled'])->orderBy('id')->chunkById(100, function ($payments) {
                foreach ($payments as $p) {
                    $create = function () use ($p) {
                        $current = $p->newQuery()->whereKey($p->id)->lockForUpdate()->firstOrFail();
                        if (! in_array($current->status, ['expired', 'failed', 'cancelled'], true)) {
                            return;
                        }
                        $order = $p instanceof MarketplacePayment;
                        $amount = $p->card_cents ?? $p->amount_cents;
                        if ($amount === 0) {
                            return;
                        }
                        DB::table('marketplace_late_capture_refunds')->insertOrIgnore(['order_id' => $order ? $p->order_id : null, 'exchange_version_id' => $order ? null : $p->exchange_version_id, 'payment_intent_id' => $p->stripe_payment_intent_id, 'idempotency_key' => 'late-capture:'.$p->stripe_payment_intent_id, 'amount_cents' => $amount, 'currency' => $p->currency, 'created_at' => now(), 'updated_at' => now()]);
                    };
                    if ($p instanceof MarketplacePayment) {
                        app(StockReservations::class)->locked(Order::findOrFail($p->order_id), $create);
                    } else {
                        app(ExchangeWorkflow::class)->locked($p->version->exchange, $create);
                    }
                }
            });
        }
        DB::table('marketplace_late_capture_refunds')->whereIn('status', ['requested', 'pending'])->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $r) {
                $gateway = app(StripeConnectMarketplaceGateway::class);
                $result = $r->stripe_refund_id ? $gateway->retrieveRefund($r->stripe_refund_id) : $gateway->refund($r->payment_intent_id, (int) $r->amount_cents, $r->idempotency_key);
                if (($result['amount'] ?? null) !== (int) $r->amount_cents || ($result['currency'] ?? null) !== strtolower($r->currency) || ($result['payment_intent'] ?? null) !== $r->payment_intent_id || ! is_string($result['id'] ?? null) || ! in_array($result['status'] ?? null, ['succeeded', 'pending', 'requires_action', 'failed', 'canceled'], true)) {
                    throw new \LogicException('Late refund provider mismatch.');
                }
                DB::transaction(function () use ($r, $result) {
                    $current = DB::table('marketplace_late_capture_refunds')->where('id', $r->id)->lockForUpdate()->first();
                    if ($current->stripe_refund_id && $current->stripe_refund_id !== $result['id']) {
                        throw new \LogicException('Late refund idempotency mismatch.');
                    }
                    if ($current->status !== 'succeeded') {
                        DB::table('marketplace_late_capture_refunds')->where('id', $r->id)->update(['stripe_refund_id' => $result['id'], 'status' => $result['status'], 'updated_at' => now()]);
                    }
                }, 5);
            }
        });
    }
}
