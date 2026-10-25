<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\SaveCustomerAddress;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VendorOrder;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\StockReservations;
use App\Services\VendorOrderWorkflow;
use Database\Seeders\PlanSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class VendorOrderPresentationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function fixture(string $mode = 'shipping', bool $paid = true, bool $multiple = false, bool $provisional = false): array
    {
        $this->seed(PlanSeeder::class);
        $seller = Retailer::factory()->approved()->create(['company_name' => 'Villaggio Tramonto']);
        Subscription::factory()->active()->create(['retailer_id' => $seller->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);
        $buyer = User::factory()->create(['role' => 'customer', 'name' => 'Simone Tocco', 'email' => 'buyer-'.Str::uuid().'@example.test']);
        for ($i = 0; $i < ($multiple ? 2 : 1); $i++) {
            $p = InventoryItem::factory()->published()->create(['retailer_id' => $seller->id, 'name' => $i === 0 ? 'Cemento Portland 32,5 R' : 'Stucco per esterni', 'sku' => 'SKU-'.$i, 'unit' => 'bancale', 'quantity' => '10', 'zero_price_cents' => 59900, 'vat_treatment' => 'included', 'vat_rate_basis_points' => $provisional ? null : 2200, 'pickup_available' => true, 'shipping_available' => true, 'shipping_cost_cents' => $i === 0 ? 30000 : 0, 'shipping_vat_treatment' => 'included', 'shipping_vat_rate_basis_points' => 2200]);
            app(MarketplaceCart::class)->save($buyer, $p, '1', $mode);
        }
        $address = $mode === 'shipping' ? app(SaveCustomerAddress::class)->handle($buyer, ['label' => 'Sede', 'recipient_name' => 'SunSet', 'address_line_1' => 'Via Tonicello', 'postal_code' => '89866', 'city' => 'Ricadi', 'province' => 'VV', 'country_code' => 'IT']) : null;
        $quotes = app(MarketplaceQuote::class);
        $order = app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], $address?->id, $quotes->hash($quotes->build($buyer, [], $address)));
        if ($paid) {
            app(StartMarketplacePayment::class)->handle($order);
            app(ApplyMarketplacePaymentResult::class)->handle($order, 'paid:'.$order->id, $order->fresh()->payment->reference, 'paid', $order->total_cents, 'EUR');
        }

        return [$seller, $buyer, $order->vendorOrders()->sole()];
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }

    private function page(Retailer $seller, VendorOrder $vendor): DOMXPath
    {
        return $this->xpath($this->actingAs($seller->user)->get(route('retailer.sales.show', $vendor))->assertOk()->getContent());
    }

    private function advanceTo(Retailer $seller, VendorOrder $vendor, string $target): void
    {
        if (in_array($target, ['preparing', 'shipped', 'ready_for_pickup', 'completed'], true)) {
            app(VendorOrderWorkflow::class)->advance($seller->user, $vendor->fresh(), 'preparing');
        }
        if (in_array($target, ['shipped', 'ready_for_pickup', 'completed'], true)) {
            app(VendorOrderWorkflow::class)->advance($seller->user, $vendor->fresh(), $vendor->delivery_mode === 'shipping' ? 'shipped' : 'ready_for_pickup', ['carrier' => 'BRT', 'tracking_reference' => '123456123456']);
        }
        if ($target === 'completed') {
            app(VendorOrderWorkflow::class)->advance($seller->user, $vendor->fresh(), 'completed');
        }
    }

    public static function states(): array
    {
        return [
            ['shipping', 'paid', 'preparing'], ['shipping', 'preparing', 'shipped'], ['shipping', 'shipped', 'completed'],
            ['pickup', 'preparing', 'ready_for_pickup'], ['pickup', 'ready_for_pickup', 'completed'],
            ['shipping', 'completed', null], ['shipping', 'payment_pending', null], ['pickup', 'completed', null],
        ];
    }

    #[DataProvider('states')]
    public function test_status_progress_and_primary_action_match_existing_workflow(string $mode, string $state, ?string $next): void
    {
        [$seller, , $vendor] = $this->fixture($mode, $state !== 'payment_pending');
        $this->advanceTo($seller, $vendor, $state);
        $xpath = $this->page($seller, $vendor);
        $this->assertSame($vendor->fresh()->status->label(), trim($xpath->query('//*[@data-vendor-status]')->item(0)->textContent));
        $this->assertSame(4, $xpath->query('//*[@data-status-progress]//li')->length);
        $this->assertSame($state === 'payment_pending' ? 0 : 1, $xpath->query('//*[@data-status-progress]//*[@aria-current="step"]')->length);
        $this->assertSame($mode === 'shipping' ? 1 : 0, $xpath->query('//*[@data-step="shipped"]')->length);
        $forms = $xpath->query('//main//form[@action="'.route('retailer.sales.update', $vendor).'"]');
        $this->assertSame($next === null ? 0 : 1, $forms->length);
        if ($next !== null) {
            $this->assertSame($next, $xpath->query('.//input[@name="status"]', $forms->item(0))->item(0)->getAttribute('value'));
        }
        if ($state === 'preparing' && $mode === 'shipping') {
            $this->assertSame(2, $xpath->query('//main//form//label/input')->length);
        }
    }

    public function test_multiple_products_keep_fiscal_details_collapsed_and_commission_separate(): void
    {
        [$seller, , $vendor] = $this->fixture(multiple: true);
        // The live plan is not a source for historical commission presentation.
        $seller->activeSubscription()->first()->update(['plan_id' => Plan::where('code', 'free')->sole()->id]);
        $xpath = $this->page($seller, $vendor);
        $this->assertSame(2, $xpath->query('//*[@data-vendor-product]')->length);
        foreach ($xpath->query('//details[@data-product-tax or @data-vendor-tax]') as $details) {
            $this->assertFalse($details->hasAttribute('open'));
        }
        $commission = $xpath->query('//*[@data-vendor-commission]')->item(0)->textContent;
        $this->assertStringContainsString('0,5%', $commission);
        $this->assertStringNotContainsString('Aliquota FREE', $commission);
        $this->assertStringContainsString('non modifica il totale pagato dal cliente', $commission);
        $this->assertStringContainsString('IVA spedizione', $xpath->query('//*[@data-product-tax]')->item(0)->textContent);
    }

    public function test_summary_uses_stored_tax_and_commission_values_and_minimal_customer_data(): void
    {
        [$seller, $buyer, $vendor] = $this->fixture();
        $xpath = $this->page($seller, $vendor);
        $this->assertSame('899,00 €', trim($xpath->query('//*[@data-customer-total]')->item(0)->textContent));
        $fiscal = $xpath->query('//*[@data-vendor-tax]')->item(0)->textContent;
        $this->assertStringContainsString('736,88 €', $fiscal);
        $this->assertStringContainsString('162,12 €', $fiscal);
        $commission = $xpath->query('//*[@data-vendor-commission]')->item(0)->textContent;
        foreach (['490,98 €', '0,5%', '2,45 €'] as $value) {
            $this->assertStringContainsString($value, $commission);
        }
        $this->assertStringContainsString($buyer->name, $xpath->query('//*[@aria-labelledby="vendor-customer-title"]')->item(0)->textContent);
        $this->assertStringContainsString('89866 Ricadi (VV)', $xpath->query('//*[@aria-labelledby="vendor-delivery-title"]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@aria-labelledby="vendor-tracking-title"]')->length);
    }

    public function test_tracking_and_copy_control_are_only_present_when_available_and_escaped(): void
    {
        [$seller, , $vendor] = $this->fixture();
        $vendor->update(['carrier' => 'BRT', 'tracking_reference' => 'TRACK-"<script>alert(1)</script>']);
        $xpath = $this->page($seller, $vendor);
        $tracking = $xpath->query('//*[@aria-labelledby="vendor-tracking-title"]')->item(0);
        $this->assertNotNull($tracking);
        $this->assertSame('TRACK-"<script>alert(1)</script>', trim($xpath->query('.//*[@x-ref="tracking"]', $tracking)->item(0)->textContent));
        $this->assertSame(0, $xpath->query('.//script', $tracking)->length);
        $this->assertSame(1, $xpath->query('.//button[@type="button" and @*[name()="x-on:click"]="copyTracking()"]', $tracking)->length);
    }

    public function test_form_payloads_advance_shipping_and_pickup_without_changing_post_contract(): void
    {
        foreach (['shipping', 'pickup'] as $mode) {
            [$seller, , $vendor] = $this->fixture($mode);
            foreach (['preparing', $mode === 'shipping' ? 'shipped' : 'ready_for_pickup', 'completed'] as $target) {
                $xpath = $this->page($seller, $vendor);
                $form = $xpath->query('//main//form')->item(0);
                $this->assertSame('POST', $form->getAttribute('method'));
                $payload = [];
                foreach ($xpath->query('.//input', $form) as $input) {
                    $payload[$input->getAttribute('name')] = $input->getAttribute('value');
                }
                $this->assertSame('PATCH', $payload['_method']);
                $this->assertArrayHasKey('_token', $payload);
                $this->assertSame($target, $payload['status']);
                if ($target === 'shipped') {
                    $payload['carrier'] = 'BRT';
                    $payload['tracking_reference'] = '123456123456';
                }
                $this->patch(route('retailer.sales.update', $vendor), $payload)->assertSessionHasNoErrors()->assertRedirect(route('retailer.sales.show', $vendor));
                $this->assertSame($target, $vendor->fresh()->status->value);
            }
            $xpath = $this->page($seller, $vendor);
            $this->assertSame(3, $xpath->query('//*[@aria-labelledby="vendor-history-title"]//ol/li')->length);
            $this->assertSame(0, $xpath->query('//main//form')->length);
        }
    }

    public function test_refund_cancelled_and_provisional_states_do_not_offer_fulfilment(): void
    {
        [$seller, , $vendor] = $this->fixture(paid: false);
        app(StockReservations::class)->release($vendor->order);
        $this->assertSame(0, $this->page($seller, $vendor)->query('//main//form')->length);
        [$seller, , $vendor] = $this->fixture();
        app(VendorOrderWorkflow::class)->refund(User::factory()->create(['role' => 'admin']), $vendor, 'Rimborso prima della spedizione');
        $xpath = $this->page($seller, $vendor);
        $this->assertSame('refunded', $vendor->fresh()->status->value);
        $this->assertSame(0, $xpath->query('//main//form')->length);
        $this->assertStringContainsString('Rimborso simulato: 899,00 €', $xpath->query('//main')->item(0)->textContent);
        [$seller, , $vendor] = $this->fixture(paid: false, provisional: true);
        $xpath = $this->page($seller, $vendor);
        $this->assertStringContainsString('IVA da definire', $xpath->query('//main')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//main//form')->length);
    }

    public function test_existing_authorization_and_shared_customer_admin_views_are_preserved(): void
    {
        [$seller, $buyer, $vendor] = $this->fixture();
        $other = Retailer::factory()->approved()->create();
        $this->actingAs($other->user)->get(route('retailer.sales.show', $vendor))->assertForbidden();
        $this->patch(route('retailer.sales.update', $vendor), ['status' => 'preparing'])->assertForbidden();
        $this->actingAs($buyer)->get(route('customer.deliveries.show', $vendor))->assertOk()->assertSee('Sottoordine #'.$vendor->id)->assertDontSee('Prodotti da consegnare');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.marketplace.vendors.show', $vendor))->assertOk()->assertSee('Sottoordine #'.$vendor->id)->assertSee('Registra richiesta');
        Gate::before(fn ($user, $ability) => $ability === 'fulfil' ? false : null);
        $this->assertSame(0, $this->page($seller, $vendor)->query('//main//form')->length);
    }

    public function test_rendering_is_read_only_and_does_not_query_live_product_images(): void
    {
        [$seller, , $vendor] = $this->fixture(multiple: true);
        $before = $vendor->fresh()->getAttributes();
        $stock = InventoryItem::orderBy('id')->pluck('quantity_milliunits')->all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->page($seller, $vendor);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $vendor->fresh()->getAttributes());
        $this->assertSame($stock, InventoryItem::orderBy('id')->pluck('quantity_milliunits')->all());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/from ["`]?inventory_(items|images)/i', $query['query']);
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
    }
}
