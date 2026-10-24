<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\ReviewInventoryItem;
use App\Actions\SaveCustomerAddress;
use App\Actions\SaveInventoryItem;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\MarketplaceTaxSnapshot;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartMarketplacePayment;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceVatTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function seller(string $plan = 'free'): Retailer
    {
        $this->seed(PlanSeeder::class);
        $seller = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $seller->id, 'plan_id' => Plan::where('code', $plan)->sole()->id]);

        return $seller;
    }

    private function product(array $data = [], ?Retailer $seller = null): InventoryItem
    {
        return InventoryItem::factory()->published()->create(array_replace([
            'retailer_id' => ($seller ?? $this->seller())->id, 'quantity' => '20', 'zero_price_cents' => 10000,
            'pickup_available' => true, 'shipping_available' => true, 'shipping_cost_cents' => 1000,
            'vat_treatment' => 'excluded', 'vat_rate_basis_points' => 2200,
            'shipping_vat_treatment' => 'excluded', 'shipping_vat_rate_basis_points' => 1000,
        ], $data));
    }

    private function quote(User $buyer, bool $shipping = true): array
    {
        $address = $shipping ? app(SaveCustomerAddress::class)->handle($buyer, ['label' => 'Casa', 'recipient_name' => 'Ada Rossi', 'address_line_1' => 'Via Roma 1', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country_code' => 'IT']) : null;
        $service = app(MarketplaceQuote::class);
        $quote = $service->build($buyer, [], $address);

        return [$quote, $address, $service->hash($quote)];
    }

    private function order(User $buyer, bool $shipping = true): Order
    {
        [, $address, $hash] = $this->quote($buyer, $shipping);

        return app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], $address?->id, $hash);
    }

    public static function amounts(): array
    {
        return [
            'included' => [12200, 'included', 2200, 10000, 2200, 12200],
            'excluded' => [10000, 'excluded', 2200, 10000, 2200, 12200],
            'included half cent' => [3, 'included', 2000, 3, 0, 3],
            'excluded half cent' => [5, 'excluded', 1000, 5, 1, 6],
            'fractional rate' => [10000, 'excluded', 425, 10000, 425, 10425],
            'explicit zero' => [10000, 'excluded', 0, 10000, 0, 10000],
            'not applicable' => [10000, 'not_applicable', null, 10000, 0, 10000],
            'missing rate' => [10000, 'included', null, null, null, null],
            'missing treatment' => [10000, null, 2200, null, null, null],
            'invalid rate' => [10000, 'excluded', 10001, null, null, null],
            'contradictory exemption' => [10000, 'not_applicable', 2200, null, null, null],
        ];
    }

    #[DataProvider('amounts')]
    public function test_exact_explicit_calculation(int $amount, ?string $treatment, ?int $rate, ?int $base, ?int $tax, ?int $gross): void
    {
        config(['marketplace.tax_rates_basis_points' => ['included' => 9999, 'excluded' => 9999]]);
        $snapshot = app(MarketplaceTaxSnapshot::class)->amount($amount, $treatment, $rate, 'IVA');
        $this->assertSame($base, $snapshot['taxable_cents']);
        $this->assertSame($tax, $snapshot['tax_cents']);
        $this->assertSame($gross, $snapshot['gross_cents']);
        $this->assertSame($gross === null, $snapshot['provisional']);
    }

    public function test_multivendor_shipping_totals_and_commissions_exclude_all_tax_and_shipping(): void
    {
        $buyer = User::factory()->create(['role' => 'customer']);
        $free = $this->product();
        $pro = $this->product(['vat_treatment' => 'included', 'zero_price_cents' => 12200, 'shipping_cost_cents' => 1220, 'shipping_vat_treatment' => 'included', 'shipping_vat_rate_basis_points' => 2200], $this->seller('pro'));
        app(MarketplaceCart::class)->save($buyer, $free, '1', 'shipping');
        app(MarketplaceCart::class)->save($buyer, $pro, '1', 'shipping');
        $order = $this->order($buyer);
        $this->assertFalse($order->totals_provisional);
        $this->assertSame(22000, $order->taxable_cents);
        $this->assertSame(4720, $order->tax_cents);
        $this->assertSame(2320, $order->shipping_cents);
        $this->assertSame(26720, $order->total_cents);
        $vendors = $order->vendorOrders()->get()->keyBy('retailer_id');
        $this->assertSame(200, $vendors[$free->retailer_id]->funds_flow_snapshot['commission_cents']);
        $this->assertSame(50, $vendors[$pro->retailer_id]->funds_flow_snapshot['commission_cents']);
        $this->assertSame(10000, $vendors[$pro->retailer_id]->items()->sole()->tax_snapshot['commission_base_cents']);
        $this->assertNull($vendors[$free->retailer_id]->funds_flow_snapshot['commission_tax']['tax_cents']);
        app(StartMarketplacePayment::class)->handle($order);
        $payment = $order->payment()->sole();
        $this->assertSame('fake_marketplace', $payment->provider);
        $this->assertTrue($payment->simulated);
        $this->assertSame('consumed', app(ApplyMarketplacePaymentResult::class)->handle($order, 'vat-paid', $payment->reference, 'paid', 26720, 'EUR'));
        $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()->assertSee('IVA spedizione')->assertSee('Base commissione');
    }

    public function test_zero_commission_is_a_known_amount_and_commission_vat_remains_separate(): void
    {
        config(['marketplace.commission_tax' => ['treatment' => 'excluded', 'rate_basis_points' => 2200]]);
        $buyer = User::factory()->create(['role' => 'customer']);
        $product = $this->product(['zero_price_cents' => 1, 'vat_treatment' => 'excluded', 'vat_rate_basis_points' => 0]);
        app(MarketplaceCart::class)->save($buyer, $product, '1', 'pickup');
        [$quote] = $this->quote($buyer, false);
        $flow = $quote['groups'][0]['funds_flow_snapshot'];
        $this->assertSame(0, $flow['commission_cents']);
        $this->assertSame(0, $flow['commission_tax']['tax_cents']);
        $this->assertSame(2200, $flow['commission_tax']['rate_basis_points']);
        $this->assertSame(1, $quote['total_cents']);
        $this->assertFalse($quote['totals_provisional']);
        $product->update(['zero_price_cents' => 0]);
        [$quote] = $this->quote($buyer, false);
        $this->assertSame(0, $quote['groups'][0]['funds_flow_snapshot']['commission_base_cents']);
        $this->assertSame(0, $quote['total_cents']);
    }

    public function test_fractional_quantity_and_shipping_once_per_distinct_line(): void
    {
        $buyer = User::factory()->create(['role' => 'customer']);
        $product = $this->product(['zero_price_cents' => 999, 'vat_rate_basis_points' => 425]);
        app(MarketplaceCart::class)->save($buyer, $product, '1.250', 'shipping');
        [$quote] = $this->quote($buyer);
        $line = $quote['groups'][0]['items'][0];
        $this->assertSame(1249, $line['subtotal_cents']);
        $this->assertSame(53, $line['tax_snapshot']['tax_cents']);
        $this->assertSame(100, $line['tax_snapshot']['shipping_tax']['tax_cents']);
        $this->assertSame(1100, $quote['shipping_cents']);
        $this->assertSame(2402, $quote['total_cents']);
        $this->assertSame(25, $line['commission_cents']);
    }

    public function test_missing_shipping_or_goods_tax_stays_provisional_and_cannot_be_paid(): void
    {
        foreach ([['vat_rate_basis_points' => null], ['shipping_vat_treatment' => null], ['shipping_vat_rate_basis_points' => null]] as $missing) {
            $buyer = User::factory()->create(['role' => 'customer']);
            $product = $this->product($missing);
            config(['marketplace.tax_rates_basis_points' => ['excluded' => 2200]]);
            app(MarketplaceCart::class)->save($buyer, $product, '1', 'shipping');
            $order = $this->order($buyer);
            $this->assertTrue($order->totals_provisional);
            $this->assertNull($order->tax_cents);
            $this->assertNull($order->taxable_cents);
            app(StartMarketplacePayment::class)->handle($order);
            $payment = $order->payment()->sole();
            $this->assertSame('tax_unresolved', app(ApplyMarketplacePaymentResult::class)->handle($order, 'unresolved-'.$order->id, $payment->reference, 'paid', $order->total_cents, 'EUR'));
            $this->assertSame('payment_pending', $order->fresh()->status->value);
            $this->assertSame(20000, $product->fresh()->quantity_milliunits);
        }
    }

    public function test_pickup_and_free_shipping_do_not_require_shipping_tax_and_exemption_needs_no_rate(): void
    {
        foreach (['pickup', 'shipping'] as $mode) {
            $buyer = User::factory()->create(['role' => 'customer']);
            $product = $this->product(['vat_treatment' => 'not_applicable', 'vat_rate_basis_points' => null, 'shipping_cost_cents' => 0, 'shipping_vat_treatment' => null, 'shipping_vat_rate_basis_points' => null]);
            app(MarketplaceCart::class)->save($buyer, $product, '1', $mode);
            [$quote] = $this->quote($buyer, $mode === 'shipping');
            $this->assertFalse($quote['totals_provisional']);
            $this->assertSame(0, $quote['tax_cents']);
            $this->assertSame(10000, $quote['total_cents']);
        }
    }

    public function test_tax_changes_require_review_and_existing_order_snapshots_remain_immutable(): void
    {
        $buyer = User::factory()->create(['role' => 'customer']);
        $product = $this->product();
        app(MarketplaceCart::class)->save($buyer, $product, '1', 'shipping');
        $order = $this->order($buyer);
        $line = $order->vendorOrders()->sole()->items()->sole();
        $before = $line->tax_snapshot;
        app(MarketplaceCart::class)->save($buyer, $product, '1', 'shipping');
        [, $address, $hash] = $this->quote($buyer);
        $product->update(['vat_rate_basis_points' => 1000, 'shipping_vat_rate_basis_points' => 2200]);
        try {
            app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], $address->id, $hash);
            $this->fail('Stale fiscal review must fail');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('checkout', $e->errors());
        }
        $this->assertSame($before, $line->fresh()->tax_snapshot);
        $this->assertSame(13300, $order->fresh()->total_cents);
        $this->assertDatabaseCount('orders', 1);
    }

    private function input(array $extra = []): array
    {
        return array_replace(['name' => 'Cemento', 'category_id' => Category::where('slug', 'edilizia')->sole()->id, 'quantity' => '1', 'zero_price' => '100', 'condition' => 'new', 'province' => 'Roma', 'description' => 'Sacco', 'pickup_available' => true, 'shipping_available' => true, 'shipping_cost' => '10', 'vat_treatment' => 'excluded', 'vat_rate' => '22', 'shipping_vat_treatment' => 'included', 'shipping_vat_rate' => '4,25', 'intent' => 'pending'], $extra);
    }

    public function test_fiscal_fields_are_validated_owned_moderated_and_displayed_in_form(): void
    {
        $seller = $this->seller();
        $product = $this->product([], $seller);
        $this->actingAs($seller->user)->get(route('retailer.stock.edit', $product))->assertOk()->assertSee('name="vat_rate"', false)->assertSee('name="shipping_vat_rate"', false);
        $input = $this->input();
        foreach (['-1', '100.01', '22.001', '2e1', 'abc', ['22']] as $invalid) {
            $this->post(route('retailer.stock.store'), $this->input(['vat_rate' => $invalid]))->assertSessionHasErrors('vat_rate');
        }
        $this->post(route('retailer.stock.store'), $this->input(['vat_treatment' => 'not_applicable']))->assertSessionHasErrors('vat_rate');
        $this->post(route('retailer.stock.store'), $this->input(['shipping_vat_rate' => '101']))->assertSessionHasErrors('shipping_vat_rate');
        $this->post(route('retailer.stock.store'), $this->input(['shipping_vat_treatment' => 'unknown']))->assertSessionHasErrors('shipping_vat_treatment');
        $this->put(route('retailer.stock.update', $product), $input)->assertSessionHasNoErrors();
        $this->assertSame(2200, $product->fresh()->vat_rate_basis_points);
        $this->assertSame(425, $product->fresh()->proposed_data['shipping_vat_rate_basis_points']);
        $this->assertSame(1000, $product->fresh()->shipping_vat_rate_basis_points);
        $other = $this->seller();
        $this->actingAs($other->user)->put(route('retailer.stock.update', $product), $input)->assertForbidden();
        app(ReviewInventoryItem::class)->handle(User::factory()->create(['role' => 'admin']), $product->fresh(), 'published', null);
        $this->assertSame(425, $product->fresh()->shipping_vat_rate_basis_points);
        $this->assertNull($product->fresh()->proposed_data);
        // Internal callers receive the same validation as HTTP submissions.
        $this->expectException(ValidationException::class);
        app(SaveInventoryItem::class)->handle($seller->user, $this->input(['vat_rate' => 22.5]));
    }
}
