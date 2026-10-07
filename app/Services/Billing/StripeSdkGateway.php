<?php

namespace App\Services\Billing;

use App\Contracts\StripeBillingGateway;
use App\Data\StripeSubscriptionData;
use App\Models\BillingCheckout;
use Stripe\StripeClient;

class StripeSdkGateway implements StripeBillingGateway
{
    public function __construct(private StripeClient $client) {}

    public function createCheckout(BillingCheckout $checkout): array
    {
        $price = $this->client->prices->retrieve($checkout->price_id, []);
        $interval = $checkout->billing_interval === 'monthly' ? 'month' : 'year';
        if (! $price->active || $price->unit_amount !== $checkout->price_cents || $price->currency !== 'eur' || $price->recurring?->interval !== $interval || $price->recurring?->interval_count !== 1) {
            throw new \RuntimeException('Stripe price does not match the local plan.');
        }
        $metadata = ['zero_checkout_id' => $checkout->id, 'zero_retailer_id' => (string) $checkout->retailer_id];
        $session = $this->client->checkout->sessions->create([
            'mode' => 'subscription', 'line_items' => [['price' => $checkout->price_id, 'quantity' => 1]],
            'client_reference_id' => $checkout->id, 'metadata' => $metadata, 'subscription_data' => ['metadata' => $metadata],
            'customer_email' => $checkout->retailer->user->email,
            'success_url' => route('retailer.billing.return', ['result' => 'success']),
            'cancel_url' => route('retailer.billing.return', ['result' => 'cancelled']),
            'expires_at' => $checkout->expires_at->getTimestamp(),
        ], ['idempotency_key' => 'zero-checkout:'.$checkout->id]);

        return ['id' => $session->id, 'url' => $session->url];
    }

    public function subscription(string $id): StripeSubscriptionData
    {
        return StripeSubscriptionData::fromArray($this->client->subscriptions->retrieve($id, ['expand' => ['latest_invoice']])->toArray());
    }
}
