<?php

namespace App\Services\Payments;

use App\Contracts\ExchangePaymentGatewayInterface;
use App\Models\ExchangeVersion;
use App\Services\ExchangeRules;
use App\Services\ExchangeStock;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceEconomicRules;
use App\Services\RetailerCredit;
use Illuminate\Support\Arr;

class StartExchangeDifferencePayment
{
    public function handle(ExchangeVersion $version): void
    {
        app(MarketplaceEconomicRules::class)->exchange();
        $workflow = app(ExchangeWorkflow::class);
        $exchange = $version->exchange;
        $start = $workflow->locked($exchange, function ($e, $v, $lines) use ($version) {
            if ($v->id !== $version->id) {
                app(ExchangeRules::class)->fail('version', 'Versione di pagamento superata.');
            }
            $p = $v->payment()->lockForUpdate()->firstOrFail();
            if ($p->status === 'paid') {
                return false;
            }
            if ($e->status->value !== 'awaiting_difference_payment' || ! app(ExchangeStock::class)->active($e, $v, $lines) || ! app(RetailerCredit::class)->active($v)) {
                app(ExchangeRules::class)->fail('payment', 'Scambio o prenotazioni non più validi.');
            }

            if (! $p->reference) {
                $p->update(['provider' => app(MarketplaceEconomicRules::class)->provider(), 'simulated' => ! app(MarketplaceEconomicRules::class)->isStripe()]);
            }

            if ($p->card_cents === 0) {
                $reference = 'credit-exchange:'.$v->id;
                $p->update(['reference' => $reference]);
                app(ApplyExchangePaymentResult::class)->handle($v, $reference, $reference, 'paid', $v->difference_cents, $v->currency, $p->provider);

                return false;
            }

            return ! $p->reference;
        });
        if (! $start) {
            return;
        }
        $intent = app(ExchangePaymentGatewayInterface::class)->createDifferenceIntent($version, 'exchange:'.$exchange->uuid.':version:'.$version->id);
        if (($intent['provider'] ?? null) !== app(MarketplaceEconomicRules::class)->provider() || ($intent['simulated'] ?? null) !== ! app(MarketplaceEconomicRules::class)->isStripe() || ($intent['status'] ?? null) !== 'payment_pending' || ($intent['amount_cents'] ?? null) !== ($version->fresh()->payment->card_cents ?? $version->difference_cents) || ($intent['currency'] ?? null) !== $version->currency || ! is_string($intent['reference'] ?? null) || $intent['reference'] === '' || strlen($intent['reference']) > 191) {
            throw new \RuntimeException('Invalid fake exchange payment intent.');
        }
        $workflow->locked($exchange, function ($e, $v, $lines) use ($version, $intent) {
            // Keep provider identity even if the hold expired during the remote call.
            $p = $version->payment()->lockForUpdate()->firstOrFail();
            if ($p->reference && $p->reference !== $intent['reference']) {
                throw new \LogicException('Exchange intent idempotency mismatch.');
            }
            if (! $p->reference) {
                $p->update(Arr::only($intent, ['reference', 'provider', 'simulated', 'checkout_url']));
            }
        });
    }
}
