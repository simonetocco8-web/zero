<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Services\Shipping\ShippingErrors;
use App\Services\Shipping\ShippingFailure;
use App\Services\Shipping\ShippingParcelBuilder;
use App\Services\Shipping\ShippingQuoteService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ShippingErrorsTest extends TestCase
{
    public static function exceptions(): array
    {
        return [
            [new AuthenticationException, 'SHIPPING_SESSION_EXPIRED', 419],
            [new TokenMismatchException('secret'), 'SHIPPING_SESSION_EXPIRED', 419],
            [new HttpException(429, 'secret'), 'SHIPPING_RATE_LIMITED', 429],
            [new HttpException(403, 'secret'), 'SHIPPING_FORBIDDEN', 403],
            [new \RuntimeException('secret Failed to fetch'), 'SHIPPING_UNEXPECTED', 500],
        ];
    }

    #[DataProvider('exceptions')]
    public function test_all_quote_endpoints_render_safe_errors_even_with_debug_enabled(\Throwable $exception, string $code, int $status): void
    {
        config(['app.debug' => true]);
        Log::spy();
        foreach (['shipping.quote', 'retailer.exchanges.shipping', 'retailer.exchanges.shipping.counter'] as $index => $name) {
            Route::post('/error-probe-'.$index, fn () => throw $exception)->name($name);
            $response = $this->postJson('/error-probe-'.$index)->assertStatus($status)->assertJsonPath('success', false)->assertJsonPath('error.code', $code);
            $this->assertStringStartsWith('SHP-', $response->json('error.reference'));
            $this->assertStringNotContainsString('secret', $response->getContent());
            $this->assertStringNotContainsString('Failed to fetch', $response->getContent());
        }
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_address_error_codes_are_based_on_local_validation_and_identify_fields(): void
    {
        $address = ['recipient_name' => 'Test', 'address_line_1' => 'Via Test 1', 'postal_code' => 'ABCDE', 'city' => 'Roma', 'province' => 'RM', 'country_code' => 'IT'];
        foreach ([[$address, 'SHIPPING_INVALID_POSTAL_CODE', ['postal_code']], [[], 'SHIPPING_INCOMPLETE_ADDRESS', ['recipient_name', 'address_line_1', 'postal_code', 'city', 'province', 'country_code']]] as [$input, $code, $fields]) {
            try {
                app(ShippingQuoteService::class)->address($input);
                $this->fail('Invalid address accepted');
            } catch (ShippingFailure $error) {
                $this->assertSame($code, $error->shippingCode);
                $this->assertEqualsCanonicalizing($fields, $error->fields);
            }
        }
    }

    public function test_missing_weight_and_dimensions_have_specific_codes(): void
    {
        foreach (['weight_g' => 'SHIPPING_MISSING_WEIGHT', 'length_mm' => 'SHIPPING_MISSING_DIMENSIONS'] as $field => $code) {
            $product = new InventoryItem(['weight_g' => 1000, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 100]);
            $product->$field = null;
            try {
                app(ShippingParcelBuilder::class)->build([['product' => $product, 'quantity_milliunits' => 1000]]);
                $this->fail('Missing measurements accepted');
            } catch (ShippingFailure $error) {
                $this->assertSame($code, $error->shippingCode);
            }
        }
    }

    public function test_legacy_validation_messages_are_never_exposed(): void
    {
        $response = ShippingErrors::response(ValidationException::withMessages(['quantity' => 'secret']));
        $data = $response->getData(true);
        $this->assertSame('SHIPPING_INVALID_DATA', $data['error']['code']);
        $this->assertSame(['quantity'], $data['error']['fields']);
        $this->assertStringNotContainsString('secret', $response->getContent());
    }
}
