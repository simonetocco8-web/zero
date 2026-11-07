<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\SaveCustomerAddress;
use App\Actions\SaveInventoryItem;
use App\Actions\UpdateRetailerProfile;
use App\Contracts\ShippingQuoteProviderInterface;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\ShippingQuote;
use App\Models\StockReservation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ExchangeWorkflow;
use App\Services\InventoryImport\ImportFields;
use App\Services\InventoryImport\ImportWorkflow;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Shipping\PaccofacileShippingProvider;
use App\Services\Shipping\ShippingPackaging;
use App\Services\Shipping\ShippingParcelBuilder;
use App\Services\Shipping\ShippingQuoteService;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class ShippingQuoteTest extends TestCase
{
    use AdditiveRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shipping.enabled' => true, 'shipping.provider' => 'fake']);
        Queue::fake();
        $this->seed(PlanSeeder::class);
    }

    private function seller(): Retailer
    {
        $r = Retailer::factory()->approved()->create(['address' => 'Via Test 1', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country_code' => 'IT']);
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);

        return $r;
    }

    private function product(?Retailer $seller = null, array $extra = []): InventoryItem
    {
        return InventoryItem::factory()->published()->create($extra + ['retailer_id' => ($seller ?? $this->seller())->id, 'shipping_available' => true, 'exchange_available' => true, 'quantity' => '30', 'unit' => 'piece', 'length_mm' => 100, 'width_mm' => 200, 'height_mm' => 300, 'weight_g' => 1000, 'units_per_parcel_milliunits' => 2000, 'packing_rule' => 'pieces_capacity', 'zero_price_cents' => 1000, 'vat_treatment' => 'not_applicable', 'shipping_cost_cents' => 99999]);
    }

    private function destination(): array
    {
        return ['recipient_name' => 'Cliente Test', 'address_line_1' => 'Via Test 2', 'postal_code' => '20100', 'city' => 'Milano', 'province' => 'MI', 'country_code' => 'IT'];
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function lines(InventoryItem $p, int $q = 1000): array
    {
        return [['product' => $p->fresh(), 'quantity_milliunits' => $q]];
    }

    private function rates(User $u, InventoryItem $p, int $q = 1000): array
    {
        return app(ShippingQuoteService::class)->quote($u, $p->retailer, $this->lines($p, $q), $this->destination());
    }

    private function invalid(callable $f, string $field = 'shipping'): void
    {
        try {
            $f();
            $this->fail('Operazione non valida accettata');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_exact_units_and_fractional_parcels_require_an_explicit_packing_rule(): void
    {
        $data = app(ShippingPackaging::class)->data(['length' => '12,3', 'width' => '20', 'height' => '10', 'weight' => '1,234', 'units_per_parcel' => '0,5', 'dimension_unit' => 'cm', 'weight_unit' => 'kg', 'unit' => 'kg', 'packing_rule' => 'whole_parcels']);
        $this->assertSame(123, $data['length_mm']);
        $this->assertSame(1234, $data['weight_g']);
        $p = $this->product(null, $data + ['unit' => 'kg']);
        $this->assertCount(3, app(ShippingParcelBuilder::class)->build($this->lines($p, 1500)));
        $this->invalid(fn () => app(ShippingParcelBuilder::class)->build($this->lines($p, 1250)));
    }

    public static function badPackaging(): array
    {
        return [['length', null], ['length', '0'], ['weight', '-1'], ['height', '1e3'], ['width', '0.01'], ['weight', '0.0001'], ['units_per_parcel', '0'], ['length', '1001']];
    }

    #[DataProvider('badPackaging')]
    public function test_missing_invalid_or_excessive_packaging_is_rejected(string $field, mixed $value): void
    {
        $input = ['length' => '10', 'width' => '20', 'height' => '30', 'weight' => '1', 'units_per_parcel' => '1', 'dimension_unit' => 'cm', 'weight_unit' => 'kg', 'packing_rule' => 'whole_parcels'];
        $input[$field] = $value;
        $this->invalid(fn () => app(ShippingPackaging::class)->data($input), $field);
    }

    private function inventoryInput(): array
    {
        return ['name' => 'Articolo test', 'category_id' => Category::first()->id, 'unit' => 'piece', 'quantity' => '10', 'zero_price' => '10', 'province' => 'RM', 'condition' => 'new', 'description' => 'Test', 'pickup_available' => true, 'shipping_available' => true, 'intent' => 'pending', 'length' => '10', 'width' => '20', 'height' => '30', 'weight' => '1', 'dimension_unit' => 'cm', 'weight_unit' => 'kg', 'units_per_parcel' => '2', 'packing_rule' => 'pieces_capacity'];
    }

    public function test_new_inventory_and_published_changes_go_through_moderation(): void
    {
        $r = $this->seller();
        $save = app(SaveInventoryItem::class);
        $new = $save->handle($r->user, $this->inventoryInput());
        $this->assertSame(100, $new->length_mm);
        $p = $this->product($r);
        $save->handle($r->user, array_replace($this->inventoryInput(), ['length' => '15']), [], $p);
        $this->assertSame(100, $p->fresh()->length_mm);
        $this->assertSame(150, $p->fresh()->proposed_data['length_mm']);
        $this->assertSame('change_pending', $p->fresh()->status->value);
    }

    public function test_csv_mapping_supports_fixed_values_and_explicit_mm_grams(): void
    {
        $fields = app(ImportFields::class);
        $mapping = $fields->mapping(['name' => 'fixed', 'description' => 'fixed', 'condition' => 'fixed', 'unit' => 'fixed', 'province' => 'fixed', 'quantity' => 'fixed', 'zero_price' => 'fixed', 'length' => '0', 'width' => 'fixed', 'height' => 'fixed', 'weight' => '1', 'dimension_unit' => 'fixed', 'weight_unit' => 'fixed', 'units_per_parcel' => 'fixed', 'packing_rule' => 'fixed'], ['name' => 'Test', 'description' => 'Test', 'condition' => 'new', 'unit' => 'piece', 'province' => 'MI', 'quantity' => '1', 'zero_price' => '2', 'width' => '100', 'height' => '200', 'dimension_unit' => 'mm', 'weight_unit' => 'g', 'units_per_parcel' => '1', 'packing_rule' => 'whole_parcels'], 2);
        $input = $fields->normalize(['123', '1234'], ['mapping' => $mapping, 'number_format' => 'italian', 'category_mode' => 'single', 'category_id' => Category::first()->id]);
        $data = app(ShippingPackaging::class)->data($input);
        $this->assertSame(123, $data['length_mm']);
        $this->assertSame(1234, $data['weight_g']);
    }

    public function test_default_address_multiple_rates_cache_and_alternate_address_do_not_mutate_default(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $address = app(SaveCustomerAddress::class)->handle($u, $this->destination() + ['label' => 'Casa', 'is_default_shipping' => true]);
        $this->actingAs($u)->postJson(route('shipping.quote'), ['item_id' => $p->id, 'quantity' => '3'])->assertOk()->assertJsonCount(2, 'quotes')->assertJsonPath('quotes.0.rate.final_cents', 700);
        $this->assertSame(2, ShippingQuote::count());
        $this->actingAs($u)->postJson(route('shipping.quote'), ['item_id' => $p->id, 'quantity' => '3'])->assertOk();
        $this->assertSame(2, ShippingQuote::count());
        $this->actingAs($u)->postJson(route('shipping.quote'), ['item_id' => $p->id, 'quantity' => '3', 'address' => array_replace($this->destination(), ['postal_code' => '20101'])])->assertOk();
        $this->assertSame('20100', $address->fresh()->postal_code);
        $this->assertSame(1, $u->customerAddresses()->count());
        $this->assertStringNotContainsString('Via Test', DB::table('shipping_quotes')->value('request_snapshot'));
    }

    public function test_missing_addresses_and_seller_postal_code_do_not_produce_rates(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $this->actingAs($u)->postJson(route('shipping.quote'), ['item_id' => $p->id, 'quantity' => '1'])->assertUnprocessable();
        $p->retailer->update(['postal_code' => null]);
        $this->invalid(fn () => $this->rates($u, $p), 'postal_code');
        $this->assertSame(0, ShippingQuote::count());
    }

    public function test_expired_cross_user_changed_quantity_and_changed_origin_quotes_fail(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $q = $this->rates($u, $p)[0];
        $s = app(ShippingQuoteService::class);
        $this->invalid(fn () => $s->valid($this->buyer(), $q->id, $p->retailer, $this->lines($p), $this->destination()));
        $this->invalid(fn () => $s->valid($u, $q->id, $p->retailer, $this->lines($p, 2000), $this->destination()));
        $p->retailer->postal_code = '00101';
        $this->invalid(fn () => $s->valid($u, $q->id, $p->retailer, $this->lines($p), $this->destination()));
        $p->retailer->refresh();
        $q->update(['expires_at' => now()->subSecond()]);
        $this->invalid(fn () => $s->valid($u, $q->id, $p->retailer, $this->lines($p), $this->destination()));
    }

    public static function groupedShippingRates(): array
    {
        return [
            'Standard' => [800, 'TEST Courier A', 'TEST Standard'],
            'Express' => [1050, 'TEST Courier B', 'TEST Express'],
        ];
    }

    #[DataProvider('groupedShippingRates')]
    public function test_grouped_shipping_is_charged_once_snapshot_and_idempotent_order_keep_destination(int $shippingCents, string $carrier, string $service): void
    {
        config(['shipping.checkout_verified' => true]); // Explicit test-only fiscal/funds-flow approval.
        $u = $this->buyer();
        $p = $this->product();
        $other = $this->product($p->retailer);
        $cart = app(MarketplaceCart::class);
        $cart->save($u, $p, '3', 'shipping');
        $cart->save($u, $other, '1', 'shipping');
        $this->actingAs($u)->postJson(route('shipping.quote'), ['retailer_id' => $p->retailer_id, 'address' => $this->destination()])->assertOk();
        // Equal timestamps must not decide which service the customer selects.
        ShippingQuote::query()->update(['created_at' => now()->startOfSecond()]);
        $selected = ShippingQuote::where('user_id', $u->id)->where('retailer_id', $p->retailer_id)->where('rate->final_cents', $shippingCents)->sole();
        $this->assertSame($shippingCents, $selected->rate['final_cents']);
        $this->assertSame($carrier, $selected->rate['carrier']);
        $this->assertSame($service, $selected->rate['service']);
        $this->actingAs($u)->post(route('shipping.select'), ['shipping_quote_id' => $selected->id])->assertRedirect(route('checkout.index'));
        $address = app(SaveCustomerAddress::class)->handle($u, $this->destination() + ['label' => 'Casa']);
        $quote = app(MarketplaceQuote::class)->build($u, [], $address);
        $this->assertSame($shippingCents, $quote['shipping_cents']);
        $this->assertSame(4000 + $shippingCents, $quote['total_cents']);
        $this->assertSame(20, array_sum(array_column($quote['groups'][0]['items'], 'commission_cents')));
        $this->assertSame($shippingCents, array_sum(array_column($quote['groups'][0]['items'], 'shipping_cost_cents')));
        $key = (string) Str::uuid();
        $order = app(CreateMarketplaceOrder::class)->handle($u, $key, [], $address->id, app(MarketplaceQuote::class)->hash($quote));
        $this->assertSame('20100', $order->vendorOrders->first()->address_snapshot['postal_code']);
        $this->assertSame($shippingCents, $order->shipping_cents);
        $this->assertSame(4000 + $shippingCents, $order->total_cents);
        $this->assertSame($service, $order->vendorOrders->first()->items->first()->product_snapshot['shipping_quote']['rate']['service']);
        $this->assertSame('used', $selected->fresh()->status);
        $this->assertSame('valid', ShippingQuote::where('id', '!=', $selected->id)->sole()->status);
        $this->assertSame($order->id, app(CreateMarketplaceOrder::class)->handle($u, $key, [], $address->id, 'ignored')->id);
    }

    public function test_real_adapter_is_closed_and_performs_no_http_even_with_credentials(): void
    {
        Http::fake();
        $this->invalid(fn () => app(PaccofacileShippingProvider::class)->quote([]));
        Http::assertNothingSent();
    }

    public function test_informational_quotes_block_payment_and_pickup_does_not_call_provider(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $quote = app(MarketplaceQuote::class)->build($u, []);
        $this->assertSame(0, $quote['shipping_cents']);
        $this->assertSame(0, ShippingQuote::count());
        $this->invalid(fn () => app(ShippingQuoteService::class)->checkoutAllowed());
    }

    public function test_forged_cost_and_other_users_address_are_rejected_and_legacy_has_no_fake_parcels(): void
    {
        $u = $this->buyer();
        $p = $this->product(null, ['length_mm' => null]);
        $this->invalid(fn () => $this->rates($u, $p));
        $other = app(SaveCustomerAddress::class)->handle($this->buyer(), $this->destination() + ['label' => 'Privato']);
        $this->actingAs($u)->postJson(route('shipping.quote'), ['item_id' => $p->id, 'quantity' => '1', 'address_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('address_id');
        $this->actingAs($u)->postJson(route('shipping.quote'), ['item_id' => $p->id, 'quantity' => '1', 'shipping_cost_cents' => 1])->assertUnprocessable()->assertJsonValidationErrors('shipping_cost_cents');
    }

    public function test_empty_and_malformed_provider_responses_do_not_invent_prices(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $provider = new class implements ShippingQuoteProviderInterface
        {
            public array $rates = [];

            public function name(): string
            {
                return 'test';
            }

            public function quote(array $shipment): array
            {
                return $this->rates;
            }
        };
        $service = new ShippingQuoteService($provider, new ShippingParcelBuilder);
        $this->assertSame([], $service->quote($u, $p->retailer, $this->lines($p), $this->destination()));
        $provider->rates = [['original_cents' => 1.5, 'final_cents' => 100]];
        $this->invalid(fn () => $service->quote($u, $p->retailer, $this->lines($p, 2000), $this->destination()));
        $this->assertSame(0, ShippingQuote::count());
    }

    public function test_two_exchange_legs_are_separate_and_counterproposal_expires_old_quotes(): void
    {
        $a = $this->seller();
        $b = $this->seller();
        $pa = $this->product($a);
        $pb = $this->product($b);
        $key = (string) Str::uuid();
        $s = app(ShippingQuoteService::class);
        $qa = $s->quote($a->user, $a, $this->lines($pa), $s->origin($b), 'exchange:'.$key.':a')[0];
        $qb = $s->quote($a->user, $b, $this->lines($pb, 3000), $s->origin($a), 'exchange:'.$key.':b')[0];
        $input = ['key' => $key, 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '1', 'value' => '10']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '3', 'value' => '10']]], 'delivery' => ['a' => ['mode' => 'shipping', 'cost' => '999', 'shipping_quote_id' => $qa->id, 'settlement' => 'external'], 'b' => ['mode' => 'shipping', 'cost' => '999', 'shipping_quote_id' => $qb->id, 'settlement' => 'sender']]];
        $exchange = app(ExchangeWorkflow::class)->create($a->user, $pb, $input);
        $v = $exchange->currentVersion;
        $this->assertSame(600, $v->delivery_a['shipping_cost_cents']);
        $this->assertSame(700, $v->delivery_b['shipping_cost_cents']);
        $this->assertSame(2000, $v->difference_cents);
        $this->assertSame('sender', $v->delivery_b['settlement']);
        $this->invalid(fn () => app(ExchangeWorkflow::class)->accept($a->user, $exchange, $v->id, $v->summary_hash));
        $input['key'] = (string) Str::uuid();
        $input['base_version_id'] = $v->id;
        $input['base_summary_hash'] = $v->summary_hash;
        $input['delivery'] = ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']];
        app(ExchangeWorkflow::class)->counter($b->user, $exchange, $input);
        $this->assertSame('expired', $qa->fresh()->status);
        $this->assertSame('expired', $qb->fresh()->status);
        $this->assertSame($qa->id, $v->fresh()->delivery_a['shipping_quote']['id']);
    }

    public function test_multivendor_mixed_pickup_has_one_quote_per_origin_and_no_duplicate_vat(): void
    {
        config(['shipping.checkout_verified' => true]);
        $u = $this->buyer();
        $p = $this->product();
        $p2 = $this->product();
        $pickup = $this->product();
        $carts = app(MarketplaceCart::class);
        foreach ([$p, $p2] as $product) {
            $rate = $this->rates($u, $product)[0];
            $carts->save($u, $product, '1', 'shipping', true, $rate->id);
        }
        $carts->save($u, $pickup, '1', 'pickup');
        $address = app(SaveCustomerAddress::class)->handle($u, $this->destination() + ['label' => 'Casa']);
        $quote = app(MarketplaceQuote::class)->build($u, [], $address);
        $this->assertCount(3, $quote['groups']);
        $this->assertSame(1200, $quote['shipping_cents']);
        $this->assertSame(4200, $quote['total_cents']);
        $this->assertSame(3000, array_sum(array_map(fn ($g) => $g['funds_flow_snapshot']['commission_base_cents'], $quote['groups'])));
        $this->assertSame(15, array_sum(array_map(fn ($g) => $g['funds_flow_snapshot']['commission_cents'], $quote['groups'])));
        $this->assertSame(216, $quote['tax_cents']);
    }

    public function test_alternative_destination_is_preserved_by_checkout_without_saving_an_address(): void
    {
        config(['shipping.checkout_verified' => true]);
        $u = $this->buyer();
        $p = $this->product();
        $rate = $this->rates($u, $p)[0];
        app(MarketplaceCart::class)->save($u, $p, '1', 'shipping', true, $rate->id);
        $this->actingAs($u)->post(route('checkout.review'), ['modes' => [$p->retailer_id => 'shipping']])->assertOk();
        $review = session('marketplace_checkout');
        $this->assertSame('20100', $review['shipping_destination']['postal_code']);
        $order = app(CreateMarketplaceOrder::class)->handle($u, $review['key'], $review['modes'], null, $review['hash'], false, false, $review['shipping_destination']);
        $this->assertSame('20100', $order->vendorOrders->first()->address_snapshot['postal_code']);
        $this->assertSame(0, $u->customerAddresses()->count());
    }

    public function test_destination_changes_expiry_and_cart_mutation_prevent_stale_checkout(): void
    {
        config(['shipping.checkout_verified' => true]);
        $u = $this->buyer();
        $p = $this->product();
        $rate = $this->rates($u, $p)[0];
        $carts = app(MarketplaceCart::class);
        $row = $carts->save($u, $p, '1', 'shipping', true, $rate->id);
        $address = app(SaveCustomerAddress::class)->handle($u, array_replace($this->destination(), ['postal_code' => '20101']) + ['label' => 'Diverso']);
        $this->invalid(fn () => app(MarketplaceQuote::class)->build($u, [], $address));
        $address->update(['postal_code' => '20100']);
        $quote = app(MarketplaceQuote::class)->build($u, [], $address);
        $rate->update(['expires_at' => now()->subSecond()]);
        $this->invalid(fn () => app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], $address->id, app(MarketplaceQuote::class)->hash($quote)));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockReservation::count());
        $carts->save($u, $p, '2', 'shipping', false);
        $this->assertNull($row->fresh()->shipping_quote_id);
    }

    public function test_seller_profile_change_invalidates_open_quotes_and_prune_preserves_used_quotes(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $rate = $this->rates($u, $p)[0];
        app(UpdateRetailerProfile::class)->handle($p->retailer->user, $p->retailer, $p->retailer->only(['company_name', 'vat_number', 'city', 'province', 'country_code', 'address']) + ['postal_code' => '00101']);
        $this->assertSame('expired', $rate->fresh()->status);
        $rate->update(['expires_at' => now()->subDays(8)]);
        $used = $this->rates($u, $p->fresh())[0];
        $used->update(['status' => 'used', 'expires_at' => now()->subDays(8)]);
        $this->artisan('shipping-quotes:prune')->assertSuccessful();
        $this->assertNull($rate->fresh());
        $this->assertNotNull($used->fresh());
    }

    public function test_calculator_route_permissions_and_no_http_on_product_page(): void
    {
        Http::fake();
        $p = $this->product();
        $this->get(route('marketplace.show', $p))->assertRedirect(route('login'));
        $u = $this->buyer();
        $this->actingAs($u)->get(route('marketplace.show', $p))->assertOk()->assertSee('Calcola Spedizione');
        Http::assertNothingSent();
        $this->actingAs($u)->postJson(route('shipping.select'), ['shipping_quote_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_csv_workflow_validates_dimensions_and_keeps_old_registered_imports_compatible(): void
    {
        Storage::fake('local');
        $seller = $this->seller();
        $workflow = app(ImportWorkflow::class);
        $settings = ['delimiter' => ';', 'enclosure' => '"', 'encoding' => 'auto', 'has_headers' => true, 'number_format' => 'italian'];
        $csv = UploadedFile::fake()->createWithContent('colli.csv', "Nome;Lunghezza;Peso\nTest;12,3;1,234\n");
        Cache::put('inventory-import-worker:database:inventory-imports', now()->getTimestamp(), 180);
        $import = $workflow->upload($seller->user, $csv, $settings);
        $sources = ['name' => '0', 'length' => '1', 'weight' => '2'];
        $fixed = ['description' => 'Test imballaggio', 'unit' => 'piece', 'condition' => 'new', 'province' => 'RM', 'quantity' => '2', 'zero_price' => '5', 'pickup_available' => 'si', 'width' => '20', 'height' => '30', 'dimension_unit' => 'cm', 'weight_unit' => 'kg', 'units_per_parcel' => '2', 'packing_rule' => 'pieces_capacity'];
        foreach ($fixed as $field => $value) {
            $sources[$field] = 'fixed';
        }
        $workflow->configure($seller->user, $import, 'mapping', ['sources' => $sources, 'fixed' => $fixed]);
        $workflow->configure($seller->user, $import, 'configuration', ['category_mode' => 'single', 'category_id' => Category::where('slug', 'edilizia')->sole()->id, 'mode' => 'new', 'number_format' => 'italian']);
        $workflow->preview($seller->user, $import);
        $this->assertSame('valid', $import->rows()->sole()->status);
        $workflow->confirm($seller->user, $import->fresh(), $import->fresh()->preview_token, 'draft');
        $workflow->process($import->id);
        $this->assertSame(123, InventoryItem::sole()->length_mm);
        $this->assertSame(1234, InventoryItem::sole()->weight_g);
        // A pre-feature registration has no packaging-required marker and resumes without fabricated data.
        config(['shipping.enabled' => false]);
        $legacyCsv = UploadedFile::fake()->createWithContent('legacy.csv', "Nome;Lunghezza;Peso\nLegacy;12,3;1,234\n");
        $legacy = $workflow->upload($seller->user, $legacyCsv, $settings);
        $legacySources = array_intersect_key($sources, array_flip(['name', 'description', 'unit', 'condition', 'province', 'quantity', 'zero_price', 'pickup_available']));
        $workflow->configure($seller->user, $legacy, 'mapping', ['sources' => $legacySources, 'fixed' => $fixed]);
        $workflow->configure($seller->user, $legacy, 'configuration', ['category_mode' => 'single', 'category_id' => Category::where('slug', 'edilizia')->sole()->id, 'mode' => 'new', 'number_format' => 'italian']);
        config(['shipping.enabled' => true]);
        $workflow->preview($seller->user, $legacy);
        $this->assertSame('valid', $legacy->rows()->sole()->status);
        $workflow->confirm($seller->user, $legacy->fresh(), $legacy->fresh()->preview_token, 'draft');
        $workflow->process($legacy->id);
        $this->assertNull(InventoryItem::latest('id')->first()->length_mm);
    }

    public function test_disabling_quotes_never_converts_dynamic_cart_to_legacy_even_after_quantity_changes(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $carts = app(MarketplaceCart::class);
        $carts->save($u, $p, '1', 'shipping');
        config(['shipping.enabled' => false]);
        $carts->save($u, $p, '2', 'shipping', false);
        $address = app(SaveCustomerAddress::class)->handle($u, $this->destination() + ['label' => 'Casa']);
        $this->invalid(fn () => app(MarketplaceQuote::class)->build($u, [], $address));
        $this->assertSame('dynamic', $carts->cart($u)->items()->sole()->shipping_pricing_mode);
        $this->assertSame(0, app(MarketplaceQuote::class)->build($u, [$p->retailer_id => 'pickup'])['shipping_cents']);
    }
}
