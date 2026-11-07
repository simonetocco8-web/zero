<?php

namespace Tests\Feature;

use App\Contracts\ShippingQuoteProviderInterface;
use App\Services\Shipping\SendcloudShippingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SendcloudShippingProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['shipping.provider' => 'sendcloud', 'shipping.sendcloud.public_key' => 'test-public', 'shipping.sendcloud.private_key' => 'test-private', 'shipping.sendcloud.timeout' => 15]);
        Http::preventStrayRequests();
    }

    private function shipment(): array
    {
        $address = ['recipient_name' => 'Not needed for pricing', 'country_code' => 'IT', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'address_line_1' => 'Via Test 1', 'address_line_2' => 'Interno 2', 'phone' => 'private-unused'];

        return ['retailer_id' => 1, 'origin' => $address, 'destination' => array_replace($address, ['postal_code' => '20100', 'city' => 'Milano', 'province' => 'MI']), 'parcels' => [['length_mm' => 301, 'width_mm' => 200, 'height_mm' => 150, 'weight_g' => 1234]]];
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/sendcloud/shipping-options.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function invalid(callable $call): void
    {
        try {
            $call();
            $this->fail('Invalid operation accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shipping', $e->errors());
            $this->assertStringNotContainsString('test-private', $e->getMessage());
            $this->assertStringNotContainsString('private-unused', $e->getMessage());
        }
    }

    public function test_v3_basic_auth_payload_exact_units_timeouts_and_no_label_endpoint(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $httpOptions) use (&$options) {
            $options = $httpOptions;

            return Http::response($this->fixture());
        });
        $shipment = $this->shipment();
        $shipment['parcels'][] = ['length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150, 'weight_g' => 2000];
        $provider = app(SendcloudShippingProvider::class);
        $rates = $provider->quote($shipment);
        $this->assertInstanceOf(SendcloudShippingProvider::class, app(ShippingQuoteProviderInterface::class));
        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $this->assertSame(['from_address', 'to_address', 'parcels', 'calculate_quotes'], array_keys($data));
            $this->assertSame('IT-RM', $data['from_address']['state_province_code']);
            $this->assertSame('IT-MI', $data['to_address']['state_province_code']);
            $this->assertSame('Via Test 1, Interno 2', $data['from_address']['address_line_1']);
            $this->assertArrayNotHasKey('phone', $data['from_address']);
            $this->assertCount(2, $data['parcels']);
            $this->assertSame(['length' => '30.1', 'width' => '20', 'height' => '15', 'unit' => 'cm'], $data['parcels'][0]['dimensions']);
            $this->assertSame(['value' => '1.234', 'unit' => 'kg'], $data['parcels'][0]['weight']);
            $this->assertSame('2', $data['parcels'][1]['weight']['value']);
            $this->assertTrue($data['calculate_quotes']);

            return $request->method() === 'POST' && $request->url() === SendcloudShippingProvider::ENDPOINT && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-public:test-private'));
        });
        Http::assertSentCount(1);
        $this->assertSame(15, $options['timeout']);
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(1750, $rates[0]['final_cents']); // Total shipment, not multiplied by parcels.
        $this->assertSame('postnl:standard/signature', $rates[0]['shipping_option_code']);
        $this->assertSame('PostNL', $rates[0]['carrier']);
        $this->assertSame(2125, $rates[1]['final_cents']);
        $this->assertSame(325, $rates[0]['estimated_surcharges'][0]['amount_cents']);
        $this->assertSame(1550, $rates[0]['price_breakdown'][0]['amount_cents']);
        $this->assertNull($rates[0]['tax_treatment']);
        $this->assertNull($rates[0]['tax_rate_basis_points']);
        $this->assertSame(24, $rates[0]['lead_time_hours']);
    }

    public function test_empty_null_or_unpriced_options_have_no_invented_price(): void
    {
        $fixture = $this->fixture();
        unset($fixture['data'][0]['quotes'][0]['price']['total']);
        $fixture['data'][1]['quote_error'] = ['code' => 'contract_prices_not_uploaded'];
        Http::fakeSequence()->push(['data' => []])->push(['data' => null])->push($fixture);
        $provider = app(SendcloudShippingProvider::class);
        $this->assertSame([], $provider->quote($this->shipment()));
        $this->assertSame([], $provider->quote($this->shipment()));
        $this->assertSame([], $provider->quote($this->shipment()));
    }

    public static function invalidAmounts(): array
    {
        return [['USD', '17.50'], ['EUR', null], ['EUR', 17.50], ['EUR', '-1'], ['EUR', '0.001'], ['EUR', '1e2'], ['EUR', 'NaN']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_missing_non_eur_or_inexact_money_is_never_coerced(string $currency, mixed $value): void
    {
        $fixture = $this->fixture();
        $fixture['data'] = [$fixture['data'][0]];
        $fixture['data'][0]['quotes'][0]['price']['total'] = ['value' => $value, 'currency' => $currency];
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response($fixture)]);
        $this->assertSame([], app(SendcloudShippingProvider::class)->quote($this->shipment()));
    }

    public function test_documented_zero_and_trailing_zero_decimal_are_exact_not_fallbacks(): void
    {
        $fixture = $this->fixture();
        $fixture['data'][0]['quotes'][0]['price']['total']['value'] = '0.00';
        $fixture['data'][1]['quotes'][0]['price']['total']['value'] = '21.2500';
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response($fixture)]);
        $rates = app(SendcloudShippingProvider::class)->quote($this->shipment());
        $this->assertSame(0, $rates[0]['final_cents']);
        $this->assertSame(2125, $rates[1]['final_cents']);
    }

    public static function httpErrors(): array
    {
        return [[400], [401], [403], [422], [429], [500], [503], [302]];
    }

    #[DataProvider('httpErrors')]
    public function test_http_errors_are_sanitized_and_not_retried(int $status): void
    {
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response(['message' => 'test-private private-unused'], $status)]);
        $this->invalid(fn () => app(SendcloudShippingProvider::class)->quote($this->shipment()));
        Http::assertSentCount(1);
    }

    public static function malformedResponses(): array
    {
        return [['not-json'], ['{"data":123}'], ['{"data":{}}'], ['{"wrong":[]}'], ['[]']];
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_json_is_rejected(string $response): void
    {
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response($response)]);
        $this->invalid(fn () => app(SendcloudShippingProvider::class)->quote($this->shipment()));
    }

    public function test_connection_timeout_is_sanitized_and_not_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('test-private private-unused'));
        $this->invalid(fn () => app(SendcloudShippingProvider::class)->quote($this->shipment()));
    }

    public function test_invalid_address_missing_credentials_or_too_many_parcels_prevent_http(): void
    {
        Http::fake();
        $provider = app(SendcloudShippingProvider::class);
        $shipment = $this->shipment();
        $shipment['origin']['postal_code'] = '';
        $this->invalid(fn () => $provider->quote($shipment));
        $shipment = $this->shipment();
        $shipment['parcels'] = array_fill(0, 51, $shipment['parcels'][0]);
        $this->invalid(fn () => $provider->quote($shipment));
        config(['shipping.sendcloud.private_key' => null]);
        $this->invalid(fn () => $provider->quote($this->shipment()));
        Http::assertNothingSent();
    }

    public function test_provider_limits_and_multicollo_compatibility_are_respected(): void
    {
        $fixture = $this->fixture();
        $fixture['data'][0]['max_dimensions']['length'] = '20';
        $fixture['data'][1]['weight']['max']['value'] = '1';
        Http::fakeSequence()->push($fixture)->push(['data' => [array_replace_recursive($this->fixture()['data'][0], ['functionalities' => ['multicollo' => false]])]]);
        $provider = app(SendcloudShippingProvider::class);
        $this->assertSame([], $provider->quote($this->shipment()));
        $shipment = $this->shipment();
        $shipment['parcels'][] = $shipment['parcels'][0];
        $this->assertSame([], $provider->quote($shipment));
    }

    public function test_global_post_budget_is_shared_by_retailers_and_retry_after_is_honored(): void
    {
        $provider = app(SendcloudShippingProvider::class);
        $scope = 'sendcloud:'.$provider->context(1);
        for ($i = 0; $i < 80; $i++) {
            RateLimiter::hit($scope.':minute', 60);
        }
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response([], 429, ['Retry-After' => '120'])]);
        $shipment = $this->shipment();
        $shipment['retailer_id'] = 2;
        $this->invalid(fn () => $provider->quote($shipment));
        Http::assertNothingSent();
        RateLimiter::clear($scope.':minute');
        for ($i = 0; $i < 8; $i++) {
            RateLimiter::hit($scope.':second', 1);
        }
        $this->invalid(fn () => $provider->quote($shipment));
        Http::assertNothingSent();
        RateLimiter::clear($scope.':second');
        $this->invalid(fn () => $provider->quote($this->shipment()));
        $this->invalid(fn () => $provider->quote($shipment));
        Http::assertSentCount(1);
        $this->travel(121)->seconds();
        $this->invalid(fn () => $provider->quote($shipment));
        Http::assertSentCount(2);
    }

    public function test_network_call_inside_database_transaction_is_blocked(): void
    {
        Http::fake();
        DB::beginTransaction();
        try {
            $this->invalid(fn () => app(SendcloudShippingProvider::class)->quote($this->shipment()));
        } finally {
            DB::rollBack();
        }
        Http::assertNothingSent();
    }

    public function test_old_provider_configuration_cannot_activate_paccofacile(): void
    {
        config(['shipping.provider' => 'paccofacile']);
        $this->invalid(fn () => app(ShippingQuoteProviderInterface::class));
        Http::assertNothingSent();
    }
}
