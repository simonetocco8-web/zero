<?php

namespace Tests\Feature;

use App\Models\BillingCheckout;
use App\Models\Retailer;
use App\Services\Billing\StripeSdkGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeSdkGatewayTest extends TestCase
{
    use RefreshDatabase;

    private function checkout(string $interval = 'monthly'): BillingCheckout
    {
        return BillingCheckout::create(['id' => (string) Str::uuid(), 'retailer_id' => Retailer::factory()->create()->id, 'billing_interval' => $interval, 'price_id' => 'price_contract', 'price_cents' => $interval === 'monthly' ? 6900 : 49900, 'expires_at' => now()->addHour()]);
    }

    private function transport(array $responses): ClientInterface
    {
        return new class($responses) implements ClientInterface
        {
            public array $requests = [];

            public function __construct(private array $responses) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

                return [json_encode(array_shift($this->responses), JSON_THROW_ON_ERROR), 200, []];
            }
        };
    }

    public function test_official_sdk_creates_recurring_checkout_with_verified_price_and_idempotency_key(): void
    {
        $checkout = $this->checkout('yearly');
        $http = $this->transport([['id' => 'price_contract', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 49900, 'recurring' => ['interval' => 'year', 'interval_count' => 1]], ['id' => 'cs_contract', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/contract']]);
        ApiRequestor::setHttpClient($http);
        try {
            $result = (new StripeSdkGateway(new StripeClient('test-only-'.bin2hex(random_bytes(16)))))->createCheckout($checkout);
            $this->assertSame('cs_contract', $result['id']);
            $this->assertCount(2, $http->requests);
            $request = $http->requests[1];
            $this->assertSame('post', $request['method']);
            $this->assertSame('subscription', $request['params']['mode']);
            $this->assertSame('price_contract', $request['params']['line_items'][0]['price']);
            $this->assertSame($checkout->id, $request['params']['subscription_data']['metadata']['zero_checkout_id']);
            $this->assertSame((string) $checkout->retailer_id, $request['params']['subscription_data']['metadata']['zero_retailer_id']);
            $this->assertContains('Idempotency-Key: zero-checkout:'.$checkout->id, $request['headers']);
        } finally {
            ApiRequestor::setHttpClient(new CurlClient);
        }
    }

    public function test_mismatched_remote_price_never_creates_a_checkout_session(): void
    {
        $checkout = $this->checkout();
        $http = $this->transport([['id' => 'price_contract', 'object' => 'price', 'active' => true, 'currency' => 'eur', 'unit_amount' => 1, 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]);
        ApiRequestor::setHttpClient($http);
        try {
            try {
                (new StripeSdkGateway(new StripeClient('test-only-'.bin2hex(random_bytes(16)))))->createCheckout($checkout);
                $this->fail('Expected price validation.');
            } catch (\RuntimeException) {
                $this->assertCount(1, $http->requests);
            }
        } finally {
            ApiRequestor::setHttpClient(new CurlClient);
        }
    }

    public function test_sdk_expands_latest_invoice_and_handles_current_item_period_fields(): void
    {
        $checkout = $this->checkout();
        $end = now()->addMonth()->getTimestamp();
        $http = $this->transport([['id' => 'sub_contract', 'object' => 'subscription', 'customer' => 'cus_contract', 'metadata' => ['zero_checkout_id' => $checkout->id, 'zero_retailer_id' => (string) $checkout->retailer_id], 'status' => 'active', 'latest_invoice' => ['id' => 'in_contract', 'object' => 'invoice', 'status' => 'paid'], 'items' => ['object' => 'list', 'data' => [['id' => 'si_contract', 'object' => 'subscription_item', 'quantity' => 1, 'current_period_end' => $end, 'price' => ['id' => 'price_contract', 'object' => 'price', 'unit_amount' => 6900, 'currency' => 'eur', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]]]]]]);
        ApiRequestor::setHttpClient($http);
        try {
            $dto = (new StripeSdkGateway(new StripeClient('test-only-'.bin2hex(random_bytes(16)))))->subscription('sub_contract');
            $this->assertTrue($dto->entitled());
            $this->assertSame($end, $dto->periodEnd->getTimestamp());
            $this->assertSame(6900, $dto->priceCents);
            $this->assertSame(['latest_invoice'], $http->requests[0]['params']['expand']);
        } finally {
            ApiRequestor::setHttpClient(new CurlClient);
        }
    }
}
