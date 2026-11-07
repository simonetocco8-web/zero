<?php

namespace Tests\Feature;

use App\Actions\SaveCustomerAddress;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\ShippingQuote;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Shipping\SendcloudShippingProvider;
use App\Services\Shipping\ShippingQuoteService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class SendcloudShippingIntegrationTest extends TestCase
{
    use AdditiveDatabaseMigrations { runDatabaseMigrations as private migrateIsolated; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->migrateIsolated();
        } else {
            $this->artisan('migrate', ['--force' => true]);
        }
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['shipping.enabled' => true, 'shipping.provider' => 'sendcloud', 'shipping.sendcloud.public_key' => 'test-public', 'shipping.sendcloud.private_key' => 'test-private']);
        Queue::fake();
        $this->seed(PlanSeeder::class);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(SendcloudShippingProvider::ENDPOINT, $request->url());

            return Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/sendcloud/shipping-options.json')), true));
        });
    }

    private function seller(string $postalCode = '00100', string $city = 'Roma', string $province = 'RM'): Retailer
    {
        $retailer = Retailer::factory()->approved()->create(['postal_code' => $postalCode, 'city' => $city, 'province' => $province, 'country_code' => 'IT', 'address' => 'Via Test 1']);
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);

        return $retailer;
    }

    private function product(Retailer $seller): InventoryItem
    {
        return InventoryItem::factory()->published()->create(['retailer_id' => $seller->id, 'shipping_available' => true, 'exchange_available' => true, 'quantity' => '30', 'unit' => 'piece', 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150, 'weight_g' => 1000, 'units_per_parcel_milliunits' => 2000, 'packing_rule' => 'pieces_capacity', 'vat_treatment' => 'not_applicable', 'shipping_cost_cents' => 99999]);
    }

    private function destination(): array
    {
        return ['recipient_name' => 'Cliente Test', 'address_line_1' => 'Via Test 2', 'postal_code' => '20100', 'city' => 'Milano', 'province' => 'MI', 'country_code' => 'IT'];
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function invalid(callable $call): void
    {
        try {
            $call();
            $this->fail('Invalid quotation accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shipping', $e->errors());
        }
    }

    public function test_product_quotes_use_default_address_cache_and_owner_selection_without_enabling_checkout(): void
    {
        $seller = $this->seller();
        $p = $this->product($seller);
        $buyer = $this->buyer();
        $address = app(SaveCustomerAddress::class)->handle($buyer, $this->destination() + ['label' => 'Casa', 'is_default_shipping' => true]);
        $input = ['item_id' => $p->id, 'quantity' => '3'];
        $response = $this->actingAs($buyer)->postJson(route('shipping.quote'), $input)->assertOk()->assertJsonCount(2, 'quotes')->assertJsonPath('informational', true)->assertJsonPath('quotes.0.rate.final_cents', 1750)->assertJsonPath('quotes.0.rate.shipping_option_code', 'postnl:standard/signature');
        $id = $response->json('quotes.0.id');
        $this->postJson(route('shipping.quote'), $input)->assertOk()->assertJsonPath('quotes.0.id', $id);
        Http::assertSentCount(1);
        $selected = ShippingQuote::findOrFail($id);
        $this->assertSame(325, $selected->rate['estimated_surcharges'][0]['amount_cents']);
        $this->assertNull($selected->rate['tax_treatment']);
        app(MarketplaceCart::class)->save($buyer, $p, '3', 'shipping', true, $id);
        $this->assertSame($id, app(MarketplaceCart::class)->cart($buyer)->items()->sole()->shipping_quote_id);
        $this->invalid(fn () => app(MarketplaceQuote::class)->build($buyer, [], $address));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $otherBuyer = $this->buyer();
        $this->actingAs($otherBuyer)->postJson(route('shipping.select'), ['shipping_quote_id' => $id])->assertNotFound();
        $this->postJson(route('shipping.quote'), ['item_id' => $p->id, 'quantity' => '1', 'address_id' => $address->id])->assertUnprocessable()->assertJsonValidationErrors('address_id');
        Http::assertSentCount(1);
    }

    public function test_grouped_multicollo_single_request_distinct_origins_and_rotation_invalidate_quotes(): void
    {
        $buyer = $this->buyer();
        $a = $this->seller();
        $b = $this->seller('10100', 'Torino', 'TO');
        $p = $this->product($a);
        $p2 = $this->product($a);
        $p3 = $this->product($b);
        $carts = app(MarketplaceCart::class);
        $carts->save($buyer, $p, '3', 'shipping');
        $carts->save($buyer, $p2, '1', 'shipping');
        $carts->save($buyer, $p3, '1', 'shipping');
        $this->actingAs($buyer)->postJson(route('shipping.quote'), ['retailer_id' => $a->id, 'address' => $this->destination()])->assertOk();
        $this->postJson(route('shipping.quote'), ['retailer_id' => $b->id, 'address' => $this->destination()])->assertOk();
        Http::assertSentCount(2);
        $requests = Http::recorded()->pluck(0);
        $this->assertCount(3, $requests[0]['parcels']);
        $this->assertCount(1, $requests[1]['parcels']);
        $this->assertSame('00100', $requests[0]['from_address']['postal_code']);
        $this->assertSame('10100', $requests[1]['from_address']['postal_code']);
        $selected = ShippingQuote::where('user_id', $buyer->id)->where('retailer_id', $a->id)->where('rate->shipping_option_code', 'postnl:standard/signature')->sole();
        $this->post(route('shipping.select'), ['shipping_quote_id' => $selected->id])->assertRedirect();
        $this->assertSame([$selected->id], $carts->cart($buyer)->items()->whereHas('product', fn ($q) => $q->where('retailer_id', $a->id))->pluck('shipping_quote_id')->unique()->all());
        $lines = [['product' => $p, 'quantity_milliunits' => 3000], ['product' => $p2, 'quantity_milliunits' => 1000]];
        config(['shipping.sendcloud.private_key' => 'rotated-test-private']);
        $this->invalid(fn () => app(ShippingQuoteService::class)->valid($buyer, $selected->id, $a, $lines, $this->destination()));
        $this->postJson(route('shipping.quote'), ['retailer_id' => $a->id, 'address' => $this->destination()])->assertOk();
        Http::assertSentCount(3);
        $carts->save($buyer, $p, '4', 'shipping', false);
        $this->assertNull($carts->cart($buyer)->items()->where('inventory_item_id', $p->id)->sole()->shipping_quote_id);
    }

    public function test_exchange_directions_use_separate_v3_quotes_and_preserve_immutable_transport_snapshots(): void
    {
        $a = $this->seller();
        $b = $this->seller('20100', 'Milano', 'MI');
        $pa = $this->product($a);
        $pb = $this->product($b);
        $input = ['key' => (string) Str::uuid(), 'requested_item_id' => $pb->id, 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '3', 'value' => '10']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '1', 'value' => '10']]], 'delivery' => ['a' => ['mode' => 'shipping', 'cost' => '0', 'settlement' => 'external'], 'b' => ['mode' => 'shipping', 'cost' => '0', 'settlement' => 'sender']]];
        $response = $this->actingAs($a->user)->postJson(route('retailer.exchanges.shipping'), $input)->assertOk()->assertJsonCount(2, 'sides.a')->assertJsonCount(2, 'sides.b');
        Http::assertSentCount(2);
        $requests = Http::recorded()->pluck(0);
        $this->assertSame('00100', $requests[0]['from_address']['postal_code']);
        $this->assertSame('20100', $requests[1]['from_address']['postal_code']);
        $this->assertSame('20100', $requests[0]['to_address']['postal_code']);
        $this->assertSame('00100', $requests[1]['to_address']['postal_code']);
        $input['delivery']['a']['shipping_quote_id'] = $response->json('sides.a.0.id');
        $input['delivery']['b']['shipping_quote_id'] = $response->json('sides.b.1.id');
        $exchange = app(ExchangeWorkflow::class)->create($a->user, $pb, $input);
        $version = $exchange->currentVersion;
        $this->assertSame(1750, $version->delivery_a['shipping_cost_cents']);
        $this->assertSame(2125, $version->delivery_b['shipping_cost_cents']);
        $this->assertSame('postnl:standard/signature', $version->delivery_a['shipping_quote']['rate']['shipping_option_code']);
        $this->assertSame(2000, $version->difference_cents);
        $this->invalid(fn () => app(ExchangeWorkflow::class)->accept($a->user, $exchange, $version->id, $version->summary_hash));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_per_user_rate_limit_still_applies_to_cached_quotes(): void
    {
        $buyer = $this->buyer();
        $p = $this->product($this->seller());
        $input = ['item_id' => $p->id, 'quantity' => '1', 'address' => $this->destination()];
        $this->actingAs($buyer);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('shipping.quote'), $input)->assertOk();
        }
        $this->postJson(route('shipping.quote'), $input)->assertStatus(429);
        Http::assertSentCount(1);
    }

    public function test_manual_diagnostic_only_outputs_non_personal_quotation_data(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sendcloud-diagnostic-');
        file_put_contents($file, json_encode(['retailer_id' => 1, 'origin' => $this->destination(), 'destination' => $this->destination(), 'parcels' => [['length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150, 'weight_g' => 1000]]], JSON_THROW_ON_ERROR));
        try {
            $this->artisan('shipping:diagnose-sendcloud', ['--input' => $file])->expectsOutputToContain('Nessuna spedizione o etichetta creata.')->assertSuccessful();
        } finally {
            unlink($file);
        }
        Http::assertSentCount(1);
    }

    public function test_equal_price_options_keep_deterministic_order_on_cache_hits(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/sendcloud/shipping-options.json')), true);
        $fixture['data'][1]['quotes'][0]['price']['total']['value'] = '17.50';
        $fixture['data'][1]['quotes'][0]['price']['breakdown'][0]['price']['value'] = '17.50';
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response($fixture)]);
        $buyer = $this->buyer();
        $p = $this->product($this->seller());
        $input = ['item_id' => $p->id, 'quantity' => '1', 'address' => $this->destination()];
        $first = $this->actingAs($buyer)->postJson(route('shipping.quote'), $input)->assertOk()->assertJsonPath('quotes.0.rate.shipping_option_code', 'dpd:test-express')->json('quotes');
        $cached = $this->postJson(route('shipping.quote'), $input)->assertOk()->json('quotes');
        $this->assertSame(array_column($first, 'id'), array_column($cached, 'id'));
        Http::assertSentCount(1);
    }

    public function test_accounts_with_over_one_hundred_options_are_not_cut_down_to_an_arbitrary_subset(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $sample = json_decode(file_get_contents(base_path('tests/Fixtures/sendcloud/shipping-options.json')), true)['data'][0];
        $options = [];
        for ($i = 1; $i <= 101; $i++) {
            $option = $sample;
            $option['code'] = 'postnl:test-'.$i;
            $options[] = $option;
        }
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response(['data' => $options])]);
        $buyer = $this->buyer();
        $product = $this->product($this->seller());
        $this->actingAs($buyer)->postJson(route('shipping.quote'), ['item_id' => $product->id, 'quantity' => '1', 'address' => $this->destination()])->assertOk()->assertJsonCount(101, 'quotes');
        $this->assertDatabaseCount('shipping_quotes', 101);
        Http::assertSentCount(1);
    }
}
