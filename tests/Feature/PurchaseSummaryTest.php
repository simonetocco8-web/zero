<?php

namespace Tests\Feature;

use App\Actions\SaveCustomerAddress;
use App\Models\CustomerAddress;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\ShippingQuote;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\PurchaseSummary;
use App\Services\Shipping\SendcloudShippingProvider;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class PurchaseSummaryTest extends TestCase
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

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shipping.enabled' => true, 'shipping.provider' => 'fake']);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        $this->seed(PlanSeeder::class);
        $this->buyer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($this->buyer);
    }

    private function product(?Retailer $seller = null, array $extra = []): InventoryItem
    {
        if (! $seller) {
            $seller = Retailer::factory()->approved()->create(['address' => 'Via Origine 1', 'city' => 'Roma', 'postal_code' => '00100', 'province' => 'RM', 'country_code' => 'IT']);
            Subscription::factory()->active()->create(['retailer_id' => $seller->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);
        }

        return InventoryItem::factory()->published()->create($extra + ['retailer_id' => $seller->id, 'quantity' => '100', 'unit' => 'piece', 'zero_price_cents' => 1000, 'shipping_available' => true, 'pickup_available' => true, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150, 'weight_g' => 1250, 'units_per_parcel_milliunits' => 2000, 'packing_rule' => 'pieces_capacity', 'vat_treatment' => 'not_applicable', 'shipping_cost_cents' => 99999]);
    }

    private function destination(): array
    {
        return ['recipient_name' => 'Cliente Test', 'address_line_1' => 'Via Destinazione 2', 'postal_code' => '20100', 'city' => 'Milano', 'province' => 'MI', 'country_code' => 'IT'];
    }

    private function add(InventoryItem $p, string $q = '1', string $mode = 'shipping'): void
    {
        app(MarketplaceCart::class)->save($this->buyer, $p, $q, $mode);
    }

    private function summary(array $modes = [], ?array $destination = null): array
    {
        return app(PurchaseSummary::class)->cart($this->buyer, app(MarketplaceCart::class)->cart($this->buyer)->load('items.product.retailer'), $modes, $destination);
    }

    private function select(InventoryItem $p, bool $express = false): ShippingQuote
    {
        $result = $this->postJson(route('shipping.quote'), ['retailer_id' => $p->retailer_id, 'address' => $this->destination()])->assertOk();
        $q = ShippingQuote::findOrFail(collect($result->json('quotes'))->firstWhere('rate.service', $express ? 'TEST Express' : 'TEST Standard')['id']);
        $this->post(route('shipping.select'), ['shipping_quote_id' => $q->id, 'return_to' => 'cart'])->assertRedirect(route('cart.index'));

        return $q;
    }

    public function test_cart_opens_seller_calculators_and_never_substitutes_legacy_cost_for_missing_quotes(): void
    {
        $p = $this->product();
        $other = $this->product();
        $this->add($p);
        $this->add($other, '2');
        $response = $this->get(route('cart.index'))->assertOk()->assertSee('Spedizione da calcolare')->assertSee('Non ancora disponibile')->assertDontSee('999,99');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame(2, (new \DOMXPath($dom))->query('//button[@data-shipping-trigger]')->length);
        $s = $response->viewData('summary');
        $this->assertSame(3000, $s['goods_input_cents']);
        $this->assertNull($s['total_cents']);
        $this->assertSame(0, $s['shipping_estimate_cents']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipping_quotes', 0);
    }

    public function test_same_seller_multiple_products_are_quoted_once_and_selection_can_be_changed(): void
    {
        $p = $this->product();
        $b = $this->product($p->retailer);
        $this->add($p, '3');
        $this->add($b);
        $q = $this->select($p);
        $this->assertCount(3, $q->request_snapshot['parcels']);
        $this->assertCount(2, $q->request_snapshot['lines']);
        $s = $this->summary();
        $this->assertSame(4000, $s['goods_cents']);
        $this->assertSame(800, $s['shipping_cents']);
        $this->assertSame(4800, $s['total_cents']);
        $this->get(route('cart.index'))->assertOk()->assertSee('Modifica spedizione')->assertSee('TEST Standard');
        $this->select($p, true);
        $this->assertSame(5050, $this->summary()['total_cents']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        Http::assertNothingSent();
    }

    public function test_sellers_are_separate_and_incomplete_shipping_does_not_become_a_grand_total(): void
    {
        $a = $this->product();
        $b = $this->product();
        $this->add($a);
        $this->add($b);
        $this->select($a);
        $s = $this->summary();
        $this->assertNull($s['total_cents']);
        $this->assertSame(600, $s['shipping_estimate_cents']);
        $this->select($b, true);
        $s = $this->summary();
        $this->assertSame(1450, $s['shipping_cents']);
        $this->assertSame(3450, $s['total_cents']);
    }

    public function test_expiry_cart_packaging_and_address_changes_invalidate_visible_selection(): void
    {
        $p = $this->product();
        $this->add($p);
        $q = $this->select($p);
        $s = $this->summary([], array_replace($this->destination(), ['postal_code' => '20101']));
        $this->assertNull($s['groups'][0]['selected']);
        $this->assertNull($s['total_cents']);
        $p->update(['weight_g' => 1500]);
        $this->assertTrue($this->summary()['shipping_missing']);
        $p->update(['weight_g' => 1250]);
        $q->update(['expires_at' => now()->subSecond()]);
        $this->assertTrue($this->summary()['shipping_missing']);
        $this->post(route('shipping.select'), ['shipping_quote_id' => $q->id])->assertSessionHasErrors('shipping');
        $q->update(['expires_at' => now()->addMinutes(5)]);
        $this->add($p, '2');
        $this->assertTrue($this->summary()['shipping_missing']);
        $this->get(route('cart.index'))->assertSee('Calcola spedizione')->assertDontSee('Modifica spedizione');
    }

    public function test_pickup_ignores_selected_quotes_and_costs_zero_without_calling_provider(): void
    {
        $p = $this->product();
        $this->add($p);
        $this->select($p);
        $s = $this->summary([$p->retailer_id => 'pickup']);
        $this->assertSame(0, $s['shipping_cents']);
        $this->assertSame(1000, $s['total_cents']);
        $this->assertFalse($s['checkout_blocked']);
        $this->post(route('checkout.review'), ['modes' => [$p->retailer_id => 'pickup']])->assertOk()->assertViewIs('cart.review');
        Http::assertNothingSent();
    }

    public function test_vat_uses_existing_rounding_and_unknown_transport_tax_prevents_total(): void
    {
        $p = $this->product(null, ['unit' => 'kg', 'packing_rule' => 'whole_parcels', 'units_per_parcel_milliunits' => 500, 'zero_price_cents' => 1001, 'vat_treatment' => 'excluded', 'vat_rate_basis_points' => 2200]);
        $this->add($p, '1,5');
        $q = $this->select($p);
        $s = $this->summary();
        $this->assertSame(1502, $s['goods_input_cents']);
        $this->assertSame(1832, $s['goods_cents']);
        $this->assertSame(2632, $s['total_cents']);
        $q->update(['rate' => array_replace($q->rate, ['tax_treatment' => null, 'tax_rate_basis_points' => null])]);
        $s = $this->summary();
        $this->assertNull($s['total_cents']);
        $this->assertSame(800, $s['shipping_estimate_cents']);
        $this->assertTrue($s['tax_pending']);
        $this->get(route('cart.index'))->assertSee('eventuali imposte')->assertSee('Non ancora disponibile');
        $p->update(['vat_treatment' => null, 'vat_rate_basis_points' => null]);
        $this->assertNull($this->summary()['goods_cents']);
    }

    public function test_shipping_review_is_informational_and_does_not_create_a_confirmation_session_or_order(): void
    {
        $this->assertFalse(config('shipping.checkout_verified'));
        $p = $this->product();
        $this->add($p);
        $this->select($p);
        $r = $this->post(route('checkout.review'), ['modes' => [$p->retailer_id => 'shipping']])->assertOk()->assertViewIs('cart.shipping-review')->assertSee('Riepilogo indicativo')->assertDontSee('Conferma ordine');
        $r->assertSessionMissing('marketplace_checkout');
        $this->post(route('checkout.confirm'), ['key' => (string) Str::uuid()])->assertStatus(419);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('marketplace_payments', 0);
        $this->expectException(ValidationException::class);
        app(MarketplaceQuote::class)->build($this->buyer, [], (new CustomerAddress)->forceFill($this->destination() + ['user_id' => $this->buyer->id]));
    }

    public function test_saved_alternate_addresses_and_italian_validation_and_untrusted_amounts(): void
    {
        $p = $this->product();
        $this->add($p);
        $address = app(SaveCustomerAddress::class)->handle($this->buyer, $this->destination() + ['label' => 'Casa']);
        $this->postJson(route('shipping.quote'), ['retailer_id' => $p->retailer_id, 'address_id' => $address->id])->assertOk();
        $this->postJson(route('shipping.quote'), ['retailer_id' => $p->retailer_id, 'address' => ['country_code' => 'IT']])->assertUnprocessable()->assertJsonPath('errors.recipient_name.0', 'Il campo destinatario è obbligatorio.')->assertDontSee('validation.required');
        $this->postJson(route('shipping.quote'), ['item_id' => $p->id, 'address' => $this->destination()])->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Indica la quantità da spedire.');
        $this->postJson(route('shipping.quote'), ['retailer_id' => $p->retailer_id, 'address' => $this->destination(), 'price' => 1])->assertUnprocessable();
        $this->postJson(route('shipping.select'), ['shipping_quote_id' => (string) Str::uuid(), 'return_to' => 'https://example.test'])->assertUnprocessable();
        $other = User::factory()->create(['role' => 'customer']);
        $this->actingAs($other)->postJson(route('shipping.select'), ['shipping_quote_id' => ShippingQuote::first()->id])->assertNotFound();
    }

    public function test_product_estimate_and_order_summary_reuse_fiscal_results_without_mutating_cart(): void
    {
        $p = $this->product();
        $this->get(route('marketplace.purchase', $p))->assertOk()->assertSee('Stima per questo prodotto');
        $this->post(route('marketplace.purchase.preview', $p), ['quantity' => '3', 'delivery_mode' => 'pickup'])->assertOk()->assertSee('30,00');
        $this->assertDatabaseCount('cart_items', 0);
        $d = app(PurchaseSummary::class)->quoted(['subtotal_cents' => 1000, 'shipping_cents' => 800, 'total_cents' => 1800, 'totals_provisional' => false]);
        $this->assertSame(1800, $d['total_cents']);
    }

    public function test_legacy_shipping_reuses_existing_fiscal_rounding_and_dynamic_rows_never_fall_back(): void
    {
        config(['shipping.enabled' => false]);
        $p = $this->product(null, ['shipping_cost_cents' => 501, 'shipping_vat_treatment' => 'excluded', 'shipping_vat_rate_basis_points' => 2200]);
        $this->add($p);
        $summary = $this->summary();
        $this->assertSame(611, $summary['shipping_estimate_cents']);
        $this->assertSame(1611, $summary['total_cents']);
        $address = app(SaveCustomerAddress::class)->handle($this->buyer, $this->destination() + ['label' => 'Casa']);
        $this->assertSame(app(MarketplaceQuote::class)->build($this->buyer, [], $address)['total_cents'], $summary['total_cents']);
        app(MarketplaceCart::class)->cart($this->buyer)->items()->update(['shipping_pricing_mode' => 'dynamic']);
        $summary = $this->summary();
        $this->assertNull($summary['total_cents']);
        $this->assertTrue($summary['shipping_missing']);
        $this->assertTrue($summary['checkout_blocked']);
        Http::assertNothingSent();
    }

    public function test_foreign_selected_quote_is_not_exposed_or_counted_in_the_summary(): void
    {
        $p = $this->product();
        $this->add($p);
        $quote = $this->select($p);
        $quote->update(['user_id' => User::factory()->create(['role' => 'customer'])->id]);
        $summary = $this->summary();
        $this->assertNull($summary['groups'][0]['selected']);
        $this->assertNull($summary['groups'][0]['destination']);
        $this->assertNull($summary['total_cents']);
        $this->assertTrue($summary['shipping_missing']);
    }

    public function test_sendcloud_cards_keep_full_payload_but_do_not_render_internal_price_parts(): void
    {
        config(['shipping.provider' => 'sendcloud', 'shipping.sendcloud.public_key' => 'test-public', 'shipping.sendcloud.private_key' => 'test-private']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([SendcloudShippingProvider::ENDPOINT => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/sendcloud/shipping-options.json')), true))]);
        $p = $this->product();
        $this->add($p, '3');
        $r = $this->postJson(route('shipping.quote'), ['retailer_id' => $p->retailer_id, 'address' => $this->destination()])->assertOk()->assertJsonStructure(['quotes' => [['rate' => ['shipping_option_code', 'price_breakdown', 'estimated_surcharges', 'lead_time_hours']]]]);
        $this->assertSame(1750, $r->json('quotes.0.rate.final_cents'));
        $this->get(route('cart.index'))->assertOk()->assertSee('30 × 20 × 15 cm')->assertSee('1,25 kg')->assertDontSee('price_breakdown')->assertDontSee('shipping_option_code')->assertDontSee('Trattamento IVA da verificare')->assertDontSee('modello di incasso');
    }
}
