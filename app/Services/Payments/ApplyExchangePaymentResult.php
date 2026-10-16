<?php

namespace App\Services\Payments;

use App\Models\ExchangeVersion;
use App\Services\ExchangeRules;
use App\Services\ExchangeStock;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceEconomicRules;
use App\Services\MarketplaceLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Trusted internal fake/provider adapter boundary; never accepts unsigned browser callbacks. */
class ApplyExchangePaymentResult
{
    public function handle(ExchangeVersion $version, string $eventId, string $reference, string $state, int $amount, string $currency, string $provider = 'fake_marketplace'): string
    {
        if ($eventId === '' || strlen($eventId) > 191 || ! in_array($state, ['paid', 'failed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid exchange payment event.');
        }
        $hash = hash('sha256', json_encode([$version->id, $reference, $state, $amount, $currency], JSON_THROW_ON_ERROR));
        $flow = app(ExchangeWorkflow::class);
        $stock = app(ExchangeStock::class);

        return $flow->locked($version->exchange, function ($e, $current, $lines, $products, $retailers) use ($version, $eventId, $reference, $state, $amount, $currency, $hash, $flow, $stock, $provider) {
            $payment = $version->payment()->lockForUpdate()->firstOrFail();
            if ($payment->provider !== $provider || ! in_array($provider, ['fake_marketplace', 'stripe_connect'], true) || $payment->simulated !== ($provider === 'fake_marketplace') || ! $payment->reference || ! hash_equals($payment->reference, $reference) || $payment->amount_cents !== $amount || $version->difference_cents !== $amount || $currency !== $version->currency || $payment->currency !== $currency) {
                throw new \InvalidArgumentException('Exchange payment identity or amount mismatch.');
            }
            $receipt = DB::table('exchange_payment_events')->where('provider', $payment->provider)->where('event_id', $eventId)->lockForUpdate()->first();
            if ($receipt) {
                if (! hash_equals($receipt->payload_hash, $hash)) {
                    throw new \LogicException('Exchange event ID reused with different data.');
                }

                return $receipt->outcome;
            }
            if ($payment->status === 'paid') {
                $outcome = $state === 'paid' ? 'already_paid' : 'ignored_after_paid';
            } elseif ($current->id !== $version->id) {
                $outcome = $state === 'paid' ? 'reconciliation_required' : 'obsolete_ignored';
            } elseif ($state !== 'paid') {
                if ($e->status->open()) {
                    $stock->release($current);
                    $payment->refresh()->update(['status' => $state]);
                    $e->update(['status' => 'cancelled']);
                    $flow->event($e, 'difference_'.$state);
                }
                $outcome = 'released';
            } elseif ($e->status->value !== 'awaiting_difference_payment' || ! $stock->active($e, $current, $lines)) {
                if ($e->status->open()) {
                    $stock->release($current);
                    $e->update(['status' => 'expired']);
                    $payment->refresh()->update(['status' => 'expired']);
                    $flow->event($e, 'expired');
                }
                $outcome = 'reconciliation_required';
            } elseif ($current->payment_provisional) {
                $outcome = 'tax_unresolved';
            } else {
                app(MarketplaceEconomicRules::class)->exchange();
                $eligible = true;
                try {
                    foreach ($retailers as $r) {
                        app(ExchangeRules::class)->eligible($r);
                    }
                } catch (ValidationException) {
                    $eligible = false;
                }
                if (! $eligible) {
                    $stock->release($current);
                    $e->update(['status' => 'cancelled']);
                    $flow->event($e, 'eligibility_lost');
                    $outcome = 'reconciliation_required';
                } else {
                    $stock->consume($e, $current, $lines, $products);
                    $payment->update(['status' => 'paid']);
                    if (($current->commission_snapshot['policy'] ?? null) === 'approved_difference_v1') {
                        app(MarketplaceLedger::class)->creditExchange($current);
                    }
                    $flow->event($e, 'difference_paid');
                    $flow->event($e, 'confirmed');
                    $outcome = 'confirmed';
                }
            }
            DB::table('exchange_payment_events')->insert(['exchange_difference_payment_id' => $payment->id, 'provider' => $payment->provider, 'event_id' => $eventId, 'payload_hash' => $hash, 'outcome' => $outcome, 'created_at' => now()]);

            return $outcome;
        });
    }
}
