<?php

namespace App\Services\Billing;

use App\Contracts\StripeBillingGateway;
use App\Models\BillingCheckout;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StartStripeCheckout
{
    public function handle(User $user, string $interval): string
    {
        Gate::forUser($user)->authorize('checkout', Subscription::class);
        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            $this->invalid('Intervallo non valido.');
        }
        $priceId = config('stripe.prices.'.$interval);
        if (! is_string($priceId) || $priceId === '' || ! config('stripe.secret')) {
            $this->invalid('Stripe non è ancora configurato.');
        }
        $checkout = DB::transaction(function () use ($user, $interval, $priceId) {
            $retailer = Retailer::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $user->setRelation('retailer', $retailer);
            Gate::forUser($user)->authorize('checkout', Subscription::class);

            $current = BillingCheckout::where('retailer_id', $retailer->id)->whereIn('status', ['creating', 'open'])->lockForUpdate()->first();
            if ($current && $current->expires_at <= now()) {
                $current->update(['status' => 'expired']);
                $current = null;
            }
            if ($current) {
                if ($current->billing_interval !== $interval) {
                    $this->invalid('Completa il checkout già avviato o attendi la sua scadenza.');
                }

                return $current;
            }
            if (BillingSubscription::where('retailer_id', $retailer->id)->whereNotIn('status', ['canceled', 'incomplete_expired'])->lockForUpdate()->first()) {
                $this->invalid('Hai già un abbonamento Stripe: non avviare un secondo pagamento.');
            }
            $plan = Plan::where('code', 'pro')->where('is_active', true)->sharedLock()->firstOrFail();
            if ($plan->currency !== 'EUR') {
                $this->invalid('Valuta non supportata.');
            }

            return BillingCheckout::create(['id' => (string) Str::uuid(), 'retailer_id' => $retailer->id, 'billing_interval' => $interval, 'price_id' => $priceId, 'price_cents' => $interval === 'monthly' ? $plan->monthly_price_cents : $plan->annual_price_cents, 'expires_at' => now()->addHour()]);
        }, 5);
        if ($checkout->status === 'open' && $checkout->checkout_url) {
            return $checkout->checkout_url;
        }
        try {
            $session = app(StripeBillingGateway::class)->createCheckout($checkout);
            $host = parse_url($session['url'] ?? '', PHP_URL_HOST);
            if ($host !== 'checkout.stripe.com' || parse_url($session['url'], PHP_URL_SCHEME) !== 'https' || ! is_string($session['id'] ?? null) || strlen($session['id']) > 191) {
                throw new \RuntimeException('Invalid checkout response.');
            }
            DB::transaction(function () use ($checkout, $session) {
                $current = BillingCheckout::whereKey($checkout->id)->lockForUpdate()->firstOrFail();
                $current->update(['checkout_session_id' => $session['id'], 'checkout_url' => $session['url'], 'status' => $current->status === 'completed' ? 'completed' : 'open', 'error_code' => null]);
            });

            return $session['url'];
        } catch (\Throwable) {
            $checkout->update(['error_code' => 'stripe_checkout_failed']);
            $this->invalid('Non è stato possibile avviare Checkout. Riprova senza creare un secondo abbonamento.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['checkout' => $message]);
    }
}
