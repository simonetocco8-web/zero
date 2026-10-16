<?php

namespace App\Services\Payments;

use App\Models\Exchange;
use App\Models\User;
use App\Services\ExchangeRules;
use App\Services\ExchangeWorkflow;
use Illuminate\Support\Facades\Gate;

class SimulateExchangeDifferencePayment
{
    public function handle(User $actor, Exchange $exchange, int $id, string $hash): string
    {
        Gate::forUser($actor)->authorize('negotiate', $exchange);
        abort_unless(config('marketplace.economic_rules.execution_mode') === 'simulation', 403);
        $v = app(ExchangeWorkflow::class)->locked($exchange, function ($e, $v, $lines, $products, $rs) use ($actor, $id, $hash) {
            if ($v->id !== $id || ! hash_equals($v->summary_hash, $hash)) {
                app(ExchangeRules::class)->fail('version', 'Rivedi la versione corrente prima di simulare il pagamento.');
            }
            if ($v->payer_retailer_id !== $actor->retailer->id) {
                abort(403);
            }
            foreach ($rs as $r) {
                app(ExchangeRules::class)->eligible($r);
            }

            return $v;
        });
        app(StartExchangeDifferencePayment::class)->handle($v);
        $p = $v->payment()->firstOrFail();
        abort_unless($p->provider === 'fake_marketplace' && $p->simulated, 403);

        return app(ApplyExchangePaymentResult::class)->handle($v, 'fake-exchange-paid:'.$v->id, $p->reference, 'paid', $v->difference_cents, $v->currency);
    }
}
