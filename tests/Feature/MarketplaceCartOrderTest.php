<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\SaveCustomerAddress;
use App\Contracts\MarketplacePaymentGatewayInterface;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Payments\FakeMarketplacePaymentGateway;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceCartOrderTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function buyer(string $role = 'customer'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function product(array $data = []): InventoryItem
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);

        return InventoryItem::factory()->published()->create(array_replace(['retailer_id' => $r->id, 'name' => 'Mattoni', 'sku' => 'SAME-SKU', 'quantity' => '10', 'zero_price_cents' => 1000, 'pickup_available' => true, 'shipping_available' => true, 'shipping_cost_cents' => 200, 'vat_treatment' => null], $data));
    }

    private function address(User $u)
    {
        return app(SaveCustomerAddress::class)->handle($u, ['label' => 'Casa', 'recipient_name' => 'Ada Rossi', 'address_line_1' => 'Via Roma 1', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country_code' => 'IT']);
    }

    private function create(User $u, array $modes = [], $address = null): Order
    {
        $quotes = app(MarketplaceQuote::class);
        $quote = $quotes->build($u, $modes, $address);

        return app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), $modes, $address?->id, $quotes->hash($quote));
    }

    public function test_single_vendor_cart_quantities_delivery_and_remove(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $this->actingAs($u)->post(route('cart.store', $p), ['quantity' => '1.250', 'delivery_mode' => 'pickup'])->assertRedirect(route('cart.index'));
        $line = CartItem::sole();
        $this->assertSame(1250, $line->quantity_milliunits);
        $this->get(route('cart.index'))->assertOk()->assertSee('Mattoni');
        $this->put(route('cart.update', $line), ['quantity' => '2.500', 'delivery_mode' => 'shipping'])->assertSessionHasNoErrors();
        $this->assertSame(2500, $line->fresh()->quantity_milliunits);
        $this->delete(route('cart.destroy', $line))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_client_prices_and_invalid_quantities_cannot_control_totals(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $this->actingAs($u)->post(route('cart.store', $p), ['quantity' => '1', 'delivery_mode' => 'pickup', 'price' => 1])->assertSessionHasErrors('price');
        foreach (['0', '-1', '11', '1.0001'] as $quantity) {
            $this->post(route('cart.store', $p), ['quantity' => $quantity, 'delivery_mode' => 'pickup'])->assertSessionHasErrors();
        }$this->post(route('cart.store', $p), ['quantity' => '1', 'delivery_mode' => 'pickup', 'zero_price_cents' => 1, 'retailer_id' => 999])->assertSessionHasNoErrors();
        $order = $this->create($u);
        $this->assertSame(1000, $order->total_cents);
        $this->assertNull($order->tax_cents);
        $this->assertTrue($order->totals_provisional);
    }

    public function test_multiple_vendors_same_sku_split_shipping_and_parent_totals(): void
    {
        $u = $this->buyer();
        $a = $this->product(['shipping_cost_cents' => 200]);
        $b = $this->product(['shipping_cost_cents' => 700, 'zero_price_cents' => 3000]);
        $c = app(MarketplaceCart::class);
        $c->save($u, $a, '2', 'shipping');
        $c->save($u, $b, '1', 'shipping');
        $order = $this->create($u, [$a->retailer_id => 'shipping', $b->retailer_id => 'shipping'], $this->address($u));
        $this->assertSame(2, $order->vendorOrders()->count());
        $this->assertSame(5000, $order->subtotal_cents);
        $this->assertSame(900, $order->shipping_cents);
        $this->assertSame(5900, $order->total_cents);
        $this->assertSame([200, 700], $order->vendorOrders()->orderBy('retailer_id')->pluck('shipping_cents')->all());
        $this->assertSame(2, OrderItem::where('sku', 'SAME-SKU')->count());
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_checkout_saves_product_commission_retailer_address_snapshots(): void
    {
        $u = $this->buyer();
        $p = $this->product(['vat_treatment' => 'not_applicable']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'shipping');
        $address = $this->address($u);
        $order = $this->create($u, [$p->retailer_id => 'shipping'], $address);
        $vendor = $order->vendorOrders()->first();
        $line = $vendor->items()->first();
        $p->update(['name' => 'Nome nuovo', 'zero_price_cents' => 99999, 'sku' => 'NUOVO']);
        $p->retailer->update(['company_name' => 'Azienda cambiata']);
        $address->update(['address_line_1' => 'Via nuova']);
        $this->assertSame('Mattoni', $line->fresh()->name);
        $this->assertSame(1000, $line->fresh()->unit_price_cents);
        $this->assertSame('SAME-SKU', $line->fresh()->sku);
        $this->assertSame('Via Roma 1', $vendor->fresh()->address_snapshot['address_line_1']);
        $this->assertSame(200, $line->commission_basis_points);
        $this->assertSame(OrderStatus::PaymentPending, $order->status);
    }

    public function test_review_price_change_requires_reconfirmation_and_does_not_clear_cart(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $quotes = app(MarketplaceQuote::class);
        $hash = $quotes->hash($quotes->build($u, []));
        $p->update(['zero_price_cents' => 2000]);
        try {
            app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $hash);
            $this->fail('Expected stale review failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('checkout', $e->errors());
        }$this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_suspension_stock_and_delivery_rechecked_at_checkout(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        $c = app(MarketplaceCart::class);
        $c->save($u, $p, '2', 'pickup');
        $p->update(['quantity' => '1']);
        try {
            $this->create($u);
            $this->fail('Expected stock failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }$p->update(['quantity' => '10', 'pickup_available' => false]);
        try {
            $this->create($u);
            $this->fail('Expected delivery failure');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('delivery_mode', $e->errors());
        }$p->retailer->update(['status' => 'suspended']);
        $this->expectException(ValidationException::class);
        $this->create($u);
    }

    public function test_retailer_can_buy_but_never_own_inventory(): void
    {
        $p = $this->product();
        $seller = $p->retailer->user;
        $this->actingAs($seller)->post(route('cart.store', $p), ['quantity' => '1', 'delivery_mode' => 'pickup'])->assertSessionHasErrors('cart');
        $other = $this->product();
        $this->post(route('cart.store', $other), ['quantity' => '1', 'delivery_mode' => 'pickup'])->assertSessionHasNoErrors();
        $order = $this->create($seller);
        $this->assertSame($seller->id, $order->user_id);
        $this->assertSame('retailer', $seller->fresh()->role->value);
    }

    public function test_cart_and_order_ownership_are_enforced(): void
    {
        $u = $this->buyer();
        $other = $this->buyer();
        $p = $this->product();
        $line = app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $this->actingAs($other)->put(route('cart.update', $line), ['quantity' => '1', 'delivery_mode' => 'pickup'])->assertForbidden();
        $this->delete(route('cart.destroy', $line))->assertForbidden();
        $order = $this->create($u);
        $this->get(route('orders.show', $order))->assertForbidden();
        $this->post(route('orders.payment-retry', $order))->assertForbidden();
        $this->actingAs($u)->get(route('orders.show', $order))->assertOk()->assertSee('Mattoni');
    }

    public function test_shipping_requires_owned_address_and_known_cost(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        app(MarketplaceCart::class)->save($u, $p, '1', 'shipping');
        $this->actingAs($u)->post(route('checkout.review'), ['modes' => [$p->retailer_id => 'shipping']])->assertSessionHasErrors('address_id');
        $foreign = $this->address($this->buyer());
        $this->post(route('checkout.review'), ['modes' => [$p->retailer_id => 'shipping'], 'address_id' => $foreign->id])->assertSessionHasErrors('address_id');
        $p->update(['shipping_cost_cents' => null]);
        $this->expectException(ValidationException::class);
        $this->create($u, [$p->retailer_id => 'shipping'], $this->address($u));
    }

    public function test_http_checkout_is_idempotent_fake_and_separate_from_subscription_billing(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $this->actingAs($u)->get(route('checkout.index'))->assertOk();
        $review = $this->post(route('checkout.review'), ['modes' => [$p->retailer_id => 'pickup']]);
        $review->assertOk()->assertSee('Totale provvisorio');
        $key = $review->viewData('key');
        $this->post(route('checkout.confirm'), ['key' => $key])->assertRedirect();
        $this->post(route('checkout.confirm'), ['key' => $key])->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('marketplace_payments', 1);
        $order = Order::sole();
        $this->assertSame('fake_marketplace', $order->payment->provider);
        $this->assertSame('payment_pending', $order->payment->status);
        $this->assertTrue($order->payment->simulated);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('billing_checkouts', 0);
        $this->assertDatabaseCount('billing_subscriptions', 0);
    }

    public function test_configured_tax_is_snapshotted_without_default_rate(): void
    {
        config(['marketplace.tax_rates_basis_points' => ['included' => 2200, 'excluded' => 2200]]);
        $u = $this->buyer();
        $p = $this->product(['vat_treatment' => 'included', 'vat_rate_basis_points' => 2200, 'zero_price_cents' => 1220]);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $order = $this->create($u);
        $this->assertSame(1000, $order->taxable_cents);
        $this->assertSame(220, $order->tax_cents);
        $this->assertSame(1220, $order->total_cents);
        $this->assertFalse($order->totals_provisional);
        config(['marketplace.tax_rates_basis_points' => []]);
        $this->assertSame(2200, $order->vendorOrders()->first()->items()->first()->tax_snapshot['rate_basis_points']);
    }

    public function test_fake_gateway_failure_does_not_lose_order_and_retry_is_possible(): void
    {
        $u = $this->buyer();
        $p = $this->product();
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $this->actingAs($u);
        $key = $this->post(route('checkout.review'), ['modes' => [$p->retailer_id => 'pickup']])->viewData('key');
        $this->app->bind(MarketplacePaymentGatewayInterface::class, fn () => new class implements MarketplacePaymentGatewayInterface
        {
            public function createIntent(Order $order, string $key): array
            {
                throw new \RuntimeException('Simulated outage');
            }
        });
        $this->post(route('checkout.confirm'), ['key' => $key])->assertRedirect(route('orders.show', Order::sole()));
        $this->assertNull(Order::sole()->payment->reference);
        $this->app->bind(MarketplacePaymentGatewayInterface::class, FakeMarketplacePaymentGateway::class);
        $this->post(route('orders.payment-retry', Order::sole()))->assertRedirect();
        $this->assertNotNull(Order::sole()->payment->reference);
    }
}
