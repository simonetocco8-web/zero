<?php

namespace App\Services\Payments;

use App\Contracts\ExchangePaymentGatewayInterface;
use App\Contracts\MarketplacePaymentGatewayInterface;
use App\Models\ExchangeVersion;
use App\Models\Order;
use App\Services\MarketplaceEconomicRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

/** Platform charge; transfers are separate, never a subscription or destination charge. */
class StripeConnectMarketplaceGateway implements ExchangePaymentGatewayInterface, MarketplacePaymentGatewayInterface
{
    private ?StripeClient $client = null;

    private function client(): StripeClient
    {
        app(MarketplaceEconomicRules::class)->stripeReady();

        return $this->client ??= new StripeClient(['api_key' => config('marketplace.stripe.secret'), 'max_network_retries' => 2]);
    }

    public function createIntent(Order $order, string $idempotencyKey): array
    {
        app(MarketplaceEconomicRules::class)->sale();
        if ($order->totals_provisional) {
            throw ValidationException::withMessages(['payment' => 'Dati fiscali da definire: pagamento non disponibile.']);
        }
        foreach ($order->vendorOrders()->get() as $vendor) {
            if (($vendor->funds_flow_snapshot['commission_tax']['tax_cents'] ?? null) === null || ($vendor->funds_flow_snapshot['commission_tax']['gross_cents'] ?? null) === null || empty($vendor->funds_flow_snapshot['recipient_stripe_account_id'])) {
                throw ValidationException::withMessages(['payment' => 'Snapshot finanziario incompleto: richiedi un nuovo riepilogo.']);
            }
            $account = DB::table('marketplace_connected_accounts')->where('retailer_id', $vendor->retailer_id)->value('stripe_account_id');
            if ($account !== $vendor->funds_flow_snapshot['recipient_stripe_account_id']) {
                throw ValidationException::withMessages(['payment' => 'Beneficiario cambiato: richiedi un nuovo riepilogo.']);
            }
        }
        $this->beneficiaries($order->vendorOrders()->pluck('retailer_id')->all());

        return $this->session('order', $order->id, $order->payment->card_cents ?? $order->total_cents, $order->currency, $idempotencyKey, route('orders.show', $order));
    }

    public function createDifferenceIntent(ExchangeVersion $version, string $idempotencyKey): array
    {
        app(MarketplaceEconomicRules::class)->exchange();
        if ($version->payment_provisional || $version->difference_cents <= 0 || ($version->commission_snapshot['service_tax']['tax_cents'] ?? null) === null) {
            throw ValidationException::withMessages(['payment' => 'Conguaglio fiscale non disponibile.']);
        }
        $account = DB::table('marketplace_connected_accounts')->where('retailer_id', $version->payee_retailer_id)->value('stripe_account_id');
        if (! $account || $account !== ($version->commission_snapshot['recipient_stripe_account_id'] ?? null)) {
            throw ValidationException::withMessages(['payment' => 'Beneficiario incompleto o cambiato: serve una nuova versione accettata.']);
        }
        $this->beneficiaries([$version->payee_retailer_id]);

        return $this->session('exchange', $version->id, $version->difference_cents, $version->currency, $idempotencyKey, route('retailer.exchanges.show', $version->exchange));
    }

    public function refund(string $intent, int $cents, string $key): array
    {
        if ($cents <= 0) {
            throw new \InvalidArgumentException('Refund must be positive.');
        }

        return $this->client()->refunds->create(['payment_intent' => $intent, 'amount' => $cents], ['idempotency_key' => $key])->toArray();
    }

    public function retrieveRefund(string $reference): array
    {
        return $this->client()->refunds->retrieve($reference)->toArray();
    }

    private function beneficiaries(array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            $a = DB::table('marketplace_connected_accounts')->where('retailer_id', $id)->first();
            if (! $a || ! $a->details_submitted || ! $a->charges_enabled || ! $a->payouts_enabled || ! $a->transfers_active || ! $a->verified_at) {
                throw ValidationException::withMessages(['payment' => 'Un rivenditore deve completare la verifica pagamenti.']);
            }
        }
    }

    private function session(string $kind, int $id, int $amount, string $currency, string $key, string $return): array
    {
        $metadata = ['marketplace_kind' => $kind, 'marketplace_id' => (string) $id, 'marketplace_key' => hash('sha256', $key)];
        $session = $this->client()->checkout->sessions->create([
            'mode' => 'payment', 'payment_method_types' => ['card'],
            'success_url' => $return, 'cancel_url' => $return,
            'client_reference_id' => $kind.':'.$id, 'metadata' => $metadata,
            'payment_intent_data' => ['metadata' => $metadata, 'transfer_group' => 'zm_'.$kind.'_'.$id],
            'line_items' => [['price_data' => ['currency' => strtolower($currency), 'unit_amount' => $amount, 'product_data' => ['name' => $kind === 'order' ? 'Ordine ZeroMagazzino #'.$id : 'Conguaglio scambio #'.$id]], 'quantity' => 1]],
        ], ['idempotency_key' => $key]);
        if (! is_bool($session->livemode ?? null) || $session->livemode !== (config('marketplace.stripe.mode') === 'live') || ! is_string($session->id) || ! str_starts_with($session->id, 'cs_') || ! is_string($session->url) || ! str_starts_with($session->url, 'https://checkout.stripe.com/')) {
            throw new \RuntimeException('Invalid Stripe Checkout response.');
        }

        return ['provider' => 'stripe_connect', 'reference' => $session->id, 'checkout_url' => $session->url, 'status' => 'payment_pending', 'amount_cents' => $amount, 'currency' => $currency, 'simulated' => false];
    }
}
