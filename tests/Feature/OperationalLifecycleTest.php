<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\InventoryItem;
use App\Models\LifecycleEvent;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\VendorOrder;
use App\Services\OperationalLifecycle;
use App\Services\SaleAccounting;
use App\Services\WalletBalance;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $retailers;

    private array $products;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(PlanSeeder::class);
        foreach ([0, 1] as $i) {
            $this->retailers[$i] = $r = Retailer::factory()->approved()->create();
            Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);
            $this->products[$i] = InventoryItem::factory()->create(['retailer_id' => $r->id, 'quantity' => '10']);
        }
        $this->payload = ['provider' => 'fake', 'external_order_id' => 'operational-1', 'timestamp' => now()->toIso8601String(), 'items' => []];
        foreach ([0, 1] as $i) {
            $this->payload['items'][] = ['external_line_id' => 'line-'.$i, 'inventory_item_id' => $this->products[$i]->id, 'retailer_id' => $this->retailers[$i]->id, 'quantity' => '2', 'unit_price_cents' => 1000];
        }
    }

    private function paid(): Sale
    {
        return app(SaleAccounting::class)->recordSale($this->payload);
    }

    private function move(VendorOrder $order, string $status, array $extra = []): void
    {
        app(OperationalLifecycle::class)->transition($this->retailers[0]->user, $order, ['status' => $status] + $extra);
        $order->refresh();
    }

    private function refund(int $amount, string $id = 'r1', string $line = 'line-0'): void
    {
        app(SaleAccounting::class)->refund(['provider' => 'fake', 'external_order_id' => 'operational-1', 'external_refund_id' => $id, 'timestamp' => now()->toIso8601String(), 'items' => [['external_line_id' => $line, 'amount_cents' => $amount]]]);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejected transition');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_multi_vendor_fulfillment_and_ownership(): void
    {
        $sale = $this->paid();
        [$a, $b] = $sale->vendorOrders()->orderBy('retailer_id')->get()->all();
        $this->actingAs($this->retailers[0]->user)->patchJson(route('retailer.orders.fulfillment', $b), ['status' => 'preparing'])->assertForbidden();
        $this->move($a, 'preparing');
        $this->move($a, 'shipped', ['carrier' => 'Corriere', 'tracking_code' => 'TRACK']);
        $this->assertNotNull($a->shipped_at);
        $this->actingAs($this->retailers[0]->user)->get(route('retailer.credit'))->assertOk()->assertSee('Spedita');
        $this->assertSame('paid', $sale->fresh()->status->value);
        $this->assertSame('paid', $b->fresh()->status);
        $this->move($a, 'completed');
        app(OperationalLifecycle::class)->transition($this->retailers[1]->user, $b, ['status' => 'preparing']);
        $this->assertSame('preparing', $sale->fresh()->status->value);
        app(OperationalLifecycle::class)->transition($this->retailers[1]->user, $b, ['status' => 'shipped']);
        app(OperationalLifecycle::class)->transition($this->retailers[1]->user, $b, ['status' => 'completed']);
        $this->assertSame('completed', $sale->fresh()->status->value);
    }

    public function test_parent_aggregation_with_cancelled_refunded_and_mixed_delivery(): void
    {
        $sale = $this->paid();
        $orders = $sale->vendorOrders()->orderBy('id')->get();
        foreach ([['cancelled', 'refunded', 'refunded'], ['cancelled', 'completed', 'completed'], ['cancelled', 'cancelled', 'cancelled'], ['shipped', 'ready_for_pickup', 'ready_for_pickup'], ['refunded', 'shipped', 'partially_refunded']] as [$a, $b, $expected]) {
            $orders[0]->update(['status' => $a]);
            $orders[1]->update(['status' => $b]);
            app(OperationalLifecycle::class)->aggregate($sale);
            $this->assertSame($expected, $sale->fresh()->status->value);
        }
    }

    public function test_illegal_state_matrix_is_rejected(): void
    {
        $order = $this->paid()->vendorOrders()->first();
        $allowed = ['payment_pending' => ['cancelled'], 'paid' => ['preparing', 'cancelled'], 'preparing' => ['shipped', 'cancelled'], 'shipped' => ['completed'], 'ready_for_pickup' => ['completed'], 'completed' => [], 'cancelled' => [], 'refunded' => []];
        foreach ($allowed as $from => $targets) {
            foreach (['payment_pending', 'paid', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled', 'refunded', 'partially_refunded', 'unknown'] as $to) {
                if (in_array($to, $targets)) {
                    continue;
                }
                $order->update(['status' => $from, 'fulfillment_status' => $from === 'refunded' ? 'paid' : $from]);
                $this->invalid(fn () => $this->move($order, $to));
            }
        }
    }

    public function test_partial_full_refund_stock_ledger_commission_audit_and_notifications_are_idempotent(): void
    {
        config(['orders.stock_rule' => 'restore_on_full_refund']);
        $sale = $this->paid();
        $this->assertSame(8000, $this->products[0]->fresh()->quantity_milliunits);
        $this->refund(750);
        $this->assertSame(8000, $this->products[0]->fresh()->quantity_milliunits);
        $events = LifecycleEvent::count();
        $notifications = DB::table('notifications')->count();
        $this->refund(750);
        $this->assertSame($events, LifecycleEvent::count());
        $this->assertSame($notifications, DB::table('notifications')->count());
        $this->refund(1250, 'r2');
        $this->assertSame(10000, $this->products[0]->fresh()->quantity_milliunits);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($this->retailers[0]));
        $this->assertSame('partially_refunded', $sale->fresh()->status->value);
        $this->assertSame('paid', $sale->vendorOrders()->where('retailer_id', $this->retailers[1]->id)->sole()->status);
        $this->refund(2000, 'r3', 'line-1');
        $this->assertSame('refunded', $sale->fresh()->status->value);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($this->retailers[1]));
        $this->invalid(fn () => $this->refund(1, 'over'));
    }

    public function test_cancel_paid_vendor_reverses_credit_and_only_its_stock(): void
    {
        config(['orders.stock_rule' => 'restore_on_full_refund']);
        $sale = $this->paid();
        $a = $sale->vendorOrders()->where('retailer_id', $this->retailers[0]->id)->sole();
        $this->move($a, 'cancelled');
        $this->assertSame('cancelled', $a->status);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($this->retailers[0]));
        $this->assertSame(10000, $this->products[0]->fresh()->quantity_milliunits);
        $this->assertSame(8000, $this->products[1]->fresh()->quantity_milliunits);
        $this->assertSame('paid', $sale->fresh()->status->value);
        $this->invalid(fn () => $this->move($a, 'preparing'));
    }

    public function test_full_refund_restores_zero_value_lines_with_the_same_vendor(): void
    {
        config(['orders.stock_rule' => 'restore_on_full_refund']);
        $gift = InventoryItem::factory()->create(['retailer_id' => $this->retailers[0]->id, 'quantity' => '3']);
        $this->payload['items'][] = ['external_line_id' => 'gift', 'inventory_item_id' => $gift->id, 'retailer_id' => $this->retailers[0]->id, 'quantity' => '1', 'unit_price_cents' => 0];
        $this->paid();
        $this->assertSame(2000, $gift->fresh()->quantity_milliunits);
        $this->refund(2000);
        $this->assertSame(3000, $gift->fresh()->quantity_milliunits);
        $this->refund(2000);
        $this->assertSame(3000, $gift->fresh()->quantity_milliunits);
    }

    public function test_pending_cancellation_cannot_be_paid_and_stock_remains_external_by_default(): void
    {
        $sale = app(SaleAccounting::class)->recordPending($this->payload);
        $this->assertSame('payment_pending', $sale->status->value);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($this->retailers[0]));
        $this->move($sale->vendorOrders()->first(), 'cancelled');
        $this->invalid(fn () => $this->paid());
        $this->assertSame(10000, $this->products[0]->fresh()->quantity_milliunits);
    }

    public function test_pending_vendor_can_cancel_while_the_other_vendor_is_paid(): void
    {
        $sale = app(SaleAccounting::class)->recordPending($this->payload);
        $cancelled = $sale->vendorOrders()->where('retailer_id', $this->retailers[0]->id)->sole();
        $this->move($cancelled, 'cancelled');
        $this->payload['items'] = [$this->payload['items'][1]];
        $this->paid();
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame('paid', $sale->fresh()->status->value);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($this->retailers[0]));
        $this->assertGreaterThan(0, app(WalletBalance::class)->availableCents($this->retailers[1]));
    }

    public function test_pending_to_paid_and_pickup_then_refund_after_completion(): void
    {
        $this->payload['items'][0]['delivery_method'] = 'pickup';
        app(SaleAccounting::class)->recordPending($this->payload);
        $sale = $this->paid();
        $a = $sale->vendorOrders()->first();
        $this->move($a, 'preparing');
        $this->invalid(fn () => $this->move($a, 'shipped'));
        $this->move($a, 'ready_for_pickup');
        $this->assertNotNull($a->ready_for_pickup_at);
        $this->move($a, 'completed');
        $this->refund(2000);
        $this->assertSame('refunded', $a->fresh()->status);
        $this->assertSame('completed', $a->fresh()->fulfillment_status);
        $this->assertSame(10000, $this->products[0]->fresh()->quantity_milliunits);
    }

    public function test_stock_shortage_rolls_back_every_vendor_and_financial_effect(): void
    {
        config(['orders.stock_rule' => 'deduct_only']);
        $this->products[1]->update(['quantity' => '1']);
        $this->invalid(fn () => $this->paid());
        $this->assertSame(10000, $this->products[0]->fresh()->quantity_milliunits);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_partial_refund_can_be_disabled_and_local_deduct_only_does_not_restore(): void
    {
        config(['orders.stock_rule' => 'deduct_only', 'orders.allow_partial_refunds' => false]);
        $this->paid();
        $this->invalid(fn () => $this->refund(100));
        $this->refund(2000);
        $this->assertSame(8000, $this->products[0]->fresh()->quantity_milliunits);
    }

    public function test_exchange_payment_confirms_without_completing_delivery(): void
    {
        $exchange = Exchange::create(['retailer_id' => $this->retailers[0]->id, 'counterparty_retailer_id' => $this->retailers[1]->id, 'provider' => 'fake', 'external_exchange_id' => 'exchange-1']);
        $service = app(OperationalLifecycle::class);
        $service->confirmExchangePayment($exchange, 'pay-1');
        $exchange->refresh();
        $this->assertSame('confirmed', $exchange->status);
        $this->assertNull($exchange->completed_at);
        $this->assertDatabaseCount('notifications', 2);
        $service->confirmExchangePayment($exchange, 'pay-1');
        $this->assertDatabaseCount('notifications', 2);
        $this->invalid(fn () => $service->confirmExchangePayment($exchange, 'different'));
        $this->actingAs($this->retailers[1]->user)->patchJson(route('retailer.exchanges.fulfillment', $exchange), ['status' => 'preparing'])->assertForbidden();
        foreach (['preparing', 'shipped', 'completed'] as $status) {
            $service->transition($this->retailers[0]->user, $exchange, ['status' => $status]);
        }
        $this->assertNotNull($exchange->fresh()->completed_at);
        $this->invalid(fn () => $service->transition($this->retailers[0]->user, $exchange, ['status' => 'preparing']));
    }
}
