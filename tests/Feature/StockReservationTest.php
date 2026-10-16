<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\ReviewInventoryItem;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\StockReservation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InventoryRules;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceCatalog;
use App\Services\MarketplaceQuote;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\StockReservations;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function product(array $data = []): InventoryItem
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);

        return InventoryItem::factory()->published()->create(array_replace(['retailer_id' => $r->id, 'quantity' => '1', 'pickup_available' => true, 'vat_treatment' => 'not_applicable'], $data));
    }

    private function checkout(array $products, string $quantity = '1', ?User $user = null): Order
    {
        $user ??= User::factory()->create(['role' => 'customer']);
        foreach ($products as $p) {
            app(MarketplaceCart::class)->save($user, $p, $quantity, 'pickup');
        }
        $quotes = app(MarketplaceQuote::class);
        $order = app(CreateMarketplaceOrder::class)->handle($user, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($user, [])));
        app(StartMarketplacePayment::class)->handle($order);

        return $order->fresh();
    }

    private function event(Order $order, string $event = 'event-1', string $status = 'paid'): string
    {
        return app(ApplyMarketplacePaymentResult::class)->handle($order, $event, $order->payment->reference, $status, $order->total_cents, $order->currency);
    }

    public function test_last_unit_is_reserved_without_decrement_and_unavailable_to_another_buyer(): void
    {
        $p = $this->product();
        $order = $this->checkout([$p]);
        $r = StockReservation::sole();
        $this->assertSame('active', $r->status->value);
        $this->assertSame($order->id, $r->order_id);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        $this->assertSame(0, $p->fresh()->available_quantity_milliunits);
        $this->assertSame(0, app(MarketplaceCatalog::class)->query()->find($p->id)->available_quantity_milliunits);
        $this->get(route('marketplace.show', $p))->assertOk()->assertSee('0.000')->assertSee('Quantità attualmente esaurita');
        $this->expectException(ValidationException::class);
        app(MarketplaceCart::class)->save(User::factory()->create(['role' => 'customer']), $p, '1', 'pickup');
    }

    public function test_failed_payment_releases_all_vendors_and_is_idempotent(): void
    {
        $a = $this->product();
        $b = $this->product();
        $order = $this->checkout([$a, $b]);
        $this->assertSame('released', $this->event($order, 'failure', 'failed'));
        $this->assertSame('released', $this->event($order, 'failure', 'failed'));
        $this->assertSame(2, StockReservation::where('status', 'released')->count());
        $this->assertSame(1000, $a->fresh()->available_quantity_milliunits);
        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame(2, $order->vendorOrders()->where('status', 'cancelled')->count());
        $this->assertDatabaseCount('marketplace_payment_events', 1);
    }

    public function test_consumption_is_atomic_and_duplicate_or_different_success_events_never_decrement_twice(): void
    {
        $p = $this->product();
        $order = $this->checkout([$p]);
        $this->assertSame('consumed', $this->event($order));
        $this->assertSame('consumed', $this->event($order));
        $this->assertSame('already_paid', $this->event($order, 'event-2'));
        $this->assertSame('ignored_after_paid', $this->event($order, 'late-failure', 'failed'));
        $this->assertSame(0, $p->fresh()->quantity_milliunits);
        $this->assertSame(1, $p->fresh()->stock_version);
        $this->assertSame('consumed', StockReservation::sole()->status->value);
        $this->assertNotNull(StockReservation::sole()->consumed_at);
        $this->assertSame('paid', $order->fresh()->status->value);
        app(StockReservations::class)->release($order);
        $this->assertSame('paid', $order->fresh()->status->value);
    }

    public function test_partial_milliunits_keep_other_reservations_available_and_nonnegative(): void
    {
        $p = $this->product();
        $a = $this->checkout([$p], '0.400');
        $b = $this->checkout([$p], '0.600');
        $this->event($a);
        $this->event($b, 'other-buyer');
        $this->assertSame(0, $p->fresh()->quantity_milliunits);
        $this->assertSame(2, StockReservation::where('status', 'consumed')->count());
    }

    public function test_expired_stock_is_available_even_before_command_and_late_success_cannot_steal_new_reservation(): void
    {
        config(['marketplace.reservation_minutes' => 2]);
        $p = $this->product();
        $old = $this->checkout([$p]);
        $this->travel(3)->minutes();
        $this->assertSame(1000, $p->fresh()->available_quantity_milliunits);
        $new = $this->checkout([$p]);
        $this->assertSame('reconciliation_required', $this->event($old, 'late-paid'));
        $this->assertSame('cancelled', $old->fresh()->status->value);
        $this->assertSame('expired', $old->stockReservations()->sole()->status->value);
        $this->assertSame('consumed', $this->event($new, 'new-paid'));
        $this->assertSame(0, $p->fresh()->quantity_milliunits);
    }

    public function test_expiry_command_is_idempotent_and_cancels_parent_suborders_and_intent(): void
    {
        config(['marketplace.reservation_minutes' => 1]);
        $p = $this->product();
        $order = $this->checkout([$p]);
        $this->travel(61)->seconds();
        $this->artisan('marketplace:expire-reservations')->assertExitCode(0);
        $this->artisan('marketplace:expire-reservations')->assertExitCode(0);
        $this->assertSame('expired', StockReservation::sole()->status->value);
        $this->assertNotNull(StockReservation::sole()->released_at);
        $this->assertSame('expired', $order->fresh()->payment->status);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        $this->expectException(ValidationException::class);
        app(StartMarketplacePayment::class)->handle($order);
    }

    public function test_payment_retries_and_checkout_replays_keep_original_reservation_expiry_and_key(): void
    {
        $p = $this->product();
        $order = $this->checkout([$p]);
        $r = StockReservation::sole();
        $this->travel(1)->minutes();
        app(StartMarketplacePayment::class)->handle($order);
        $replayed = app(CreateMarketplaceOrder::class)->handle($order->user, $order->checkout_key, [], null, 'not-needed-for-existing-order');
        $this->assertSame($order->id, $replayed->id);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame($r->expires_at->toISOString(), $r->fresh()->expires_at->toISOString());
        $this->assertSame($r->idempotency_key, $r->fresh()->idempotency_key);
        $this->assertSame($order->payment->reference, $order->fresh()->payment->reference);
    }

    public function test_provisional_tax_does_not_simulate_capture_or_consume_stock(): void
    {
        $p = $this->product(['vat_treatment' => null]);
        $order = $this->checkout([$p]);
        $this->assertSame('tax_unresolved', $this->event($order));
        $this->assertSame('payment_pending', $order->fresh()->status->value);
        $this->assertSame('active', StockReservation::sole()->status->value);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
    }

    public function test_only_owner_can_cancel_order_and_release_stock(): void
    {
        $p = $this->product();
        $order = $this->checkout([$p]);
        $this->actingAs(User::factory()->create(['role' => 'customer']))->post(route('orders.cancel', $order))->assertForbidden();
        $this->assertSame(0, $p->fresh()->available_quantity_milliunits);
        $this->actingAs($order->user)->post(route('orders.cancel', $order))->assertRedirect(route('orders.show', $order));
        $this->assertSame('released', StockReservation::sole()->status->value);
        $this->post(route('orders.cancel', $order))->assertRedirect();
        $this->assertSame(1000, $p->fresh()->available_quantity_milliunits);
    }

    public function test_invalid_reservation_duration_rolls_back_order_and_preserves_cart(): void
    {
        $p = $this->product();
        $u = User::factory()->create(['role' => 'customer']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        config(['marketplace.reservation_minutes' => '1e1']);
        $quotes = app(MarketplaceQuote::class);
        try {
            app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($u, [])));
            $this->fail('Invalid duration accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('economic_rules', $exception->errors());
            $this->assertDatabaseCount('orders', 0);
        }
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_multi_vendor_consumption_rolls_back_every_line_on_inconsistent_physical_stock(): void
    {
        $a = $this->product();
        $b = $this->product();
        $order = $this->checkout([$a, $b]);
        // Deliberately emulate an out-of-domain database write; the payment must fail atomically.
        DB::table('inventory_items')->where('id', $b->id)->update(['quantity_milliunits' => 0]);
        try {
            $this->event($order);
            $this->fail('Invalid physical stock consumed');
        } catch (\LogicException) {
            $this->assertSame(1000, $a->fresh()->quantity_milliunits);
        }
        $this->assertSame(0, $a->fresh()->stock_version);
        $this->assertSame(2, StockReservation::where('status', 'active')->count());
        $this->assertSame('payment_pending', $order->fresh()->status->value);
        $this->assertDatabaseCount('marketplace_payment_events', 0);
    }

    public function test_late_paid_event_for_provisional_order_releases_expired_reservations(): void
    {
        $order = $this->checkout([$this->product(['vat_treatment' => null])]);
        $this->travel(31)->minutes();
        $this->assertSame('reconciliation_required', $this->event($order));
        $this->assertSame('expired', StockReservation::sole()->status->value);
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_event_reuse_with_modified_payload_is_rejected(): void
    {
        $order = $this->checkout([$this->product()]);
        $this->event($order);
        $this->expectException(\LogicException::class);
        $this->event($order, 'event-1', 'failed');
    }

    public function test_wrong_payment_amount_is_rejected_without_any_stock_change(): void
    {
        $p = $this->product();
        $order = $this->checkout([$p]);
        try {
            app(ApplyMarketplacePaymentResult::class)->handle($order, 'tampered', $order->payment->reference, 'paid', 1, 'EUR');
            $this->fail('Wrong amount accepted');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        }
        $this->assertDatabaseCount('marketplace_payment_events', 0);
    }

    public function test_stock_reduction_below_active_reservations_is_rejected(): void
    {
        $p = $this->product(['quantity' => '2']);
        $this->checkout([$p]);
        $data = $p->fresh()->getAttributes();
        $data['quantity_milliunits'] = 500;
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(InventoryRules::class)->validate($p->retailer, $data, $p->fresh()));
    }

    public function test_stale_inventory_proposal_cannot_restore_stock_after_consumption(): void
    {
        $p = $this->product(['quantity' => '2']);
        $p->proposed_data = ['name' => 'Updated', 'quantity_milliunits' => 2000];
        $p->proposed_stock_version = 0;
        $p->status = 'change_pending';
        $p->save();
        $order = $this->checkout([$p]);
        $this->event($order);
        $this->expectException(ValidationException::class);
        app(ReviewInventoryItem::class)->handle(User::factory()->create(['role' => 'admin']), $p->fresh(), 'published', null);
    }

    public function test_database_rejects_negative_stock_and_nonpositive_reservation_quantities(): void
    {
        $p = $this->product();
        $this->checkout([$p]);
        foreach ([fn () => DB::table('inventory_items')->where('id', $p->id)->update(['quantity_milliunits' => -1]), fn () => DB::table('stock_reservations')->update(['quantity_milliunits' => 0])] as $invalid) {
            try {
                DB::transaction($invalid);
                $this->fail('Database accepted invalid quantity');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        $this->assertSame(1000, StockReservation::sole()->quantity_milliunits);
    }
}
