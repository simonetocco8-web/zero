<?php

namespace App\Services\Billing;

use App\Contracts\StripeBillingGateway;
use App\Models\BillingCheckout;
use App\Models\BillingSubscription;
use App\Models\IntegrationEvent;
use App\Models\Plan;
use App\Models\Retailer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncStripeSubscription
{
    public function handle(IntegrationEvent $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'];
        $object = $payload['data']['object'];
        $supported = ['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'invoice.payment_failed', 'invoice.paid'];
        if (! in_array($type, $supported, true)) {
            return;
        }
        if ($type === 'checkout.session.completed' && ($object['mode'] ?? null) !== 'subscription') {
            return;
        }
        $id = str_starts_with($type, 'customer.subscription.') ? ($object['id'] ?? null) : ($object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? null);
        if (is_array($id)) {
            $id = $id['id'] ?? null;
        }
        if (! is_string($id) || $id === '' || strlen($id) > 191) {
            throw new \RuntimeException('Missing subscription reference.');
        }
        Cache::lock('stripe-subscription:'.hash('sha256', $id), 50)->block(5, function () use ($id, $payload, $type, $object) {
            // Serialize fetch+apply outside SQL transactions, including events created in the same second.
            // Retrieve the current remote state; never regress from a stale webhook snapshot.
            $dto = app(StripeBillingGateway::class)->subscription($id);
            if ($dto->id !== $id) {
                throw new \RuntimeException('Subscription reference mismatch.');
            }
            $checkout = BillingCheckout::find($dto->checkoutId);
            // Events for subscriptions not created by this application are deliberately ignored.
            if (! $checkout) {
                return;
            }
            if ($checkout->retailer_id !== $dto->retailerId) {
                throw new \RuntimeException('Billing ownership mismatch.');
            }
            DB::transaction(function () use ($dto, $checkout, $payload, $type, $object) {
                $retailer = Retailer::whereKey($checkout->retailer_id)->lockForUpdate()->firstOrFail();
                $checkout = BillingCheckout::whereKey($checkout->id)->lockForUpdate()->firstOrFail();
                $billing = BillingSubscription::where('provider', 'stripe')->where('external_subscription_id', $dto->id)->lockForUpdate()->first();
                if ($billing && $billing->retailer_id !== $retailer->id) {
                    throw new \RuntimeException('Billing ownership mismatch.');
                }
                $pro = Plan::where('code', 'pro')->sharedLock()->firstOrFail();
                $expected = $dto->interval === 'monthly' ? $pro->monthly_price_cents : $pro->annual_price_cents;
                $known = ($dto->priceId === config('stripe.prices.'.$dto->interval) && $dto->priceCents === $expected) || ($billing && $dto->priceId === $billing->price_id && $dto->priceCents === $billing->price_cents);
                if (! $billing) {
                    $known = $dto->priceId === $checkout->price_id && $dto->priceCents === $checkout->price_cents && $dto->interval === $checkout->billing_interval;
                }
                if (! $known || $dto->priceCents < 0) {
                    throw new \RuntimeException('Unrecognized recurring price.');
                }
                if ($billing && $payload['created'] < $billing->last_event_created) {
                    return;
                }
                if ($type === 'checkout.session.completed') {
                    if (($object['client_reference_id'] ?? null) !== $checkout->id || ($checkout->checkout_session_id && $checkout->checkout_session_id !== $object['id'])) {
                        throw new \RuntimeException('Checkout reference mismatch.');
                    }
                    $checkout->update(['checkout_session_id' => $object['id']]);
                }
                $billing ??= new BillingSubscription(['retailer_id' => $retailer->id, 'billing_checkout_id' => $checkout->id, 'provider' => 'stripe', 'external_subscription_id' => $dto->id]);
                $billing->fill(['external_customer_id' => $dto->customerId, 'status' => $dto->status, 'invoice_paid' => $dto->invoicePaid, 'price_id' => $dto->priceId, 'price_cents' => $dto->priceCents, 'billing_interval' => $dto->interval, 'current_period_end' => $dto->periodEnd, 'cancel_at_period_end' => $dto->cancelAtPeriodEnd, 'last_event_created' => $payload['created']]);
                $billing->save();
                if ($dto->entitled() || $type === 'checkout.session.completed') {
                    $checkout->update(['status' => 'completed', 'completed_at' => $checkout->completed_at ?? now(), 'error_code' => null]);
                } elseif (in_array($dto->status, ['canceled', 'incomplete_expired'], true)) {
                    $checkout->update(['status' => 'expired']);
                }
                $effective = $dto->endedAt ?? CarbonImmutable::createFromTimestampUTC($payload['created']);
                if (BillingSubscription::where('retailer_id', $retailer->id)->where('id', '>', $billing->id)->lockForUpdate()->first()) {
                    return;
                }
                $active = $retailer->activeSubscription()->lockForUpdate()->first();
                $plan = $dto->entitled() ? $pro : Plan::where('code', 'free')->sharedLock()->firstOrFail();
                $same = $active && $active->plan_id === $plan->id && (! $dto->entitled() || ($active->billing_subscription_id === $billing->id && $active->billing_interval->value === $dto->interval && $active->price_cents === $dto->priceCents));
                if ($same) {
                    return;
                }
                if ($active) {
                    $effective = $effective->max($active->starts_at);
                    $active->update(['status' => 'cancelled', 'cancelled_at' => $effective, 'ends_at' => $effective > $active->starts_at ? $effective : null]);
                }
                // Keep all old entitlement intervals for delayed sales and historical commission snapshots.
                $retailer->subscriptions()->where('status', 'pending')->update(['status' => 'cancelled', 'cancelled_at' => $effective]);
                $retailer->subscriptions()->create(['plan_id' => $plan->id, 'billing_subscription_id' => $dto->entitled() ? $billing->id : null, 'status' => 'active', 'billing_interval' => $dto->entitled() ? $dto->interval : 'monthly', 'currency' => 'EUR', 'price_cents' => $dto->entitled() ? $dto->priceCents : 0, 'starts_at' => $effective]);
            }, 5);
        });
    }
}
