<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CustomerPromotionalBonus;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\Payments\StripeConnectMarketplaceGateway;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplacePaymentSimulationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function seller(): Retailer
    {
        $this->seed(PlanSeeder::class);
        $seller = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $seller->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);

        return $seller;
    }

    private function product(bool $provisional = false): InventoryItem
    {
        return InventoryItem::factory()->published()->create(['retailer_id' => $this->seller()->id, 'pickup_available' => true, 'quantity' => '5', 'zero_price_cents' => 10000, 'vat_treatment' => $provisional ? null : 'not_applicable']);
    }

    private function order(?User $buyer = null, bool $provisional = false, bool $start = true, bool $bonus = false, bool $multiVendor = false): Order
    {
        $buyer ??= User::factory()->create(['role' => 'customer']);
        $product = $this->product($provisional);
        app(MarketplaceCart::class)->save($buyer, $product, '1', 'pickup');
        if ($multiVendor) {
            app(MarketplaceCart::class)->save($buyer, $this->product(), '1', 'pickup');
        }
        $quotes = app(MarketplaceQuote::class);
        $order = app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($buyer, [])), $bonus);
        if ($start) {
            app(StartMarketplacePayment::class)->handle($order);
        }

        return $order->fresh()->load('payment', 'vendorOrders.items');
    }

    private function counts(): array
    {
        return collect(['wallet_transactions', 'notifications', 'marketplace_notice_outbox', 'marketplace_payment_events', 'customer_bonus_entries', 'retailer_credit_holds'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }

    public function test_success_uses_database_values_and_only_internal_service_for_all_vendors(): void
    {
        $order = $this->order(multiVendor: true);
        $this->mock(StripeConnectMarketplaceGateway::class, function ($mock) {
            $mock->shouldNotReceive('createIntent');
            $mock->shouldNotReceive('createDifferenceIntent');
        });
        $response = $this->actingAs($order->user)->post(route('orders.payment-simulate', $order));
        $response->assertRedirect(route('orders.show', $order))->assertSessionHas('status', 'Pagamento simulato riuscito. Nessun addebito reale.');
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('paid', $order->payment()->sole()->status);
        $this->assertSame(2, $order->vendorOrders()->where('status', 'paid')->count());
        $this->assertSame(2, $order->stockReservations()->where('status', 'consumed')->count());
        foreach ($order->vendorOrders as $vendor) {
            $this->assertSame(4000, InventoryItem::findOrFail($vendor->items->sole()->inventory_item_id)->quantity_milliunits);
            $this->assertSame(10000, (int) WalletTransaction::where('vendor_order_id', $vendor->id)->where('operation_kind', 'pending_credit')->sum('amount_cents'));
        }
        $event = DB::table('marketplace_payment_events')->sole();
        $this->assertTrue(Str::isUuid(Str::after($event->event_id, 'order-simulation:')));
        $this->assertSame('fake_marketplace', $event->provider);
        $this->assertTrue($order->payment()->sole()->simulated);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Stato: Pagato')->assertSee('Pagamento simulato: nessun addebito reale.')->assertDontSee('Simula pagamento riuscito');
    }

    public function test_repeated_request_preserves_stock_ledger_bonus_and_notifications(): void
    {
        $buyer = User::factory()->create(['role' => 'customer']);
        app(CustomerPromotionalBonus::class)->grant(User::factory()->create(['role' => 'admin']), $buyer, 2000, 'test', 'grant-one');
        $order = $this->order($buyer, bonus: true);
        $this->assertSame(1000, $order->payment->bonus_cents);
        $this->actingAs($buyer)->post(route('orders.payment-simulate', $order))->assertSessionHas('status', 'Pagamento simulato riuscito. Nessun addebito reale.');
        $counts = $this->counts();
        $this->post(route('orders.payment-simulate', $order))->assertRedirect(route('orders.show', $order))->assertSessionHas('status', 'Il pagamento simulato risulta già completato.');
        $this->assertSame($counts, $this->counts());
        $this->assertSame(4000, InventoryItem::sole()->quantity_milliunits);
        $this->assertSame(1000, app(CustomerPromotionalBonus::class)->available($buyer));
    }

    public function test_partial_retailer_credit_is_consumed_only_once(): void
    {
        $buyer = $this->seller();
        WalletTransaction::create(['retailer_id' => $buyer->id, 'idempotency_key' => 'opening-credit', 'type' => 'adjustment', 'amount_cents' => 2000, 'currency' => 'EUR', 'entry_bucket' => 'available', 'simulated' => true, 'available_at' => now()->subMinute(), 'description' => 'Test credit']);
        $order = $this->order($buyer->user);
        $this->assertSame(2000, $order->payment->retailer_credit_cents);
        $this->actingAs($buyer->user)->post(route('orders.payment-simulate', $order))->assertRedirect();
        $this->assertSame('spent', DB::table('retailer_credit_holds')->sole()->status);
        $counts = $this->counts();
        $this->post(route('orders.payment-simulate', $order))->assertSessionHas('status', 'Il pagamento simulato risulta già completato.');
        $this->assertSame($counts, $this->counts());
    }

    public static function forbiddenModes(): array
    {
        return [['stripe_connect'], ['disabled'], ['invalid']];
    }

    #[DataProvider('forbiddenModes')]
    public function test_non_simulation_mode_is_denied_even_for_a_fake_payment(string $mode): void
    {
        $order = $this->order();
        config(['marketplace.economic_rules.execution_mode' => $mode]);
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()->assertDontSee('Simula pagamento riuscito');
        $this->post(route('orders.payment-simulate', $order))->assertForbidden();
        $this->assertSame('payment_pending', $order->fresh()->status->value);
        $this->assertDatabaseCount('marketplace_payment_events', 0);
    }

    public static function invalidPayments(): array
    {
        return [
            'stripe' => [['provider' => 'stripe_connect'], false],
            'not simulated' => [['simulated' => false], false],
            'null reference' => [['reference' => null], false],
            'blank reference' => [['reference' => ''], false],
            'whitespace reference' => [['reference' => '  '], false],
            'missing payment' => [[], true],
        ];
    }

    #[DataProvider('invalidPayments')]
    public function test_invalid_payment_is_rejected_and_simulation_is_hidden(array $changes, bool $missing): void
    {
        $order = $this->order();
        if ($missing) {
            $order->payment()->delete();
        } else {
            $order->payment->update($changes);
        }
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()->assertDontSee('Simula pagamento riuscito');
        $this->post(route('orders.payment-simulate', $order))->assertStatus(409);
        $this->assertSame('payment_pending', $order->fresh()->status->value);
        $this->assertSame(5000, InventoryItem::sole()->quantity_milliunits);
        $this->assertDatabaseCount('marketplace_payment_events', 0);
    }

    public function test_provisional_tax_cannot_consume_stock_even_with_direct_post(): void
    {
        $order = $this->order(provisional: true);
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()->assertDontSee('Simula pagamento riuscito');
        $this->post(route('orders.payment-simulate', $order))->assertSessionHas('status', "Pagamento non completato: la configurazione IVA dell'ordine non è ancora determinata.");
        $this->assertSame('payment_pending', $order->fresh()->status->value);
        $this->assertSame('payment_pending', $order->payment()->sole()->status);
        $this->assertSame('active', $order->stockReservations()->sole()->status->value);
        $this->assertSame(5000, InventoryItem::sole()->quantity_milliunits);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_expired_stock_uses_reconciliation_outcome_and_cancelled_order_is_rejected(): void
    {
        $order = $this->order();
        $this->travel(31)->minutes();
        $this->actingAs($order->user)->post(route('orders.payment-simulate', $order))->assertSessionHas('status', "Pagamento non completato: la prenotazione o lo stato dell'ordine richiedono una verifica.");
        $this->assertSame('cancelled', $order->fresh()->status->value);
        $this->assertSame(5000, InventoryItem::sole()->quantity_milliunits);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->post(route('orders.payment-simulate', $order))->assertStatus(409);
    }

    public function test_financial_input_is_rejected_instead_of_being_used(): void
    {
        $order = $this->order();
        $fields = ['amount', 'amount_cents', 'currency', 'reference', 'provider', 'status', 'retailer_id', 'total_cents', 'event_id', 'payment_id', 'simulated'];
        $this->actingAs($order->user)->postJson(route('orders.payment-simulate', $order), array_fill_keys($fields, 'attacker-value'))->assertUnprocessable()->assertJsonValidationErrors($fields);
        $this->assertSame('payment_pending', $order->fresh()->status->value);
        $this->assertDatabaseCount('marketplace_payment_events', 0);
    }

    public function test_ownership_authentication_post_only_and_csrf_are_required(): void
    {
        $order = $this->order();
        $this->post(route('orders.payment-simulate', $order))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'customer']))->post(route('orders.payment-simulate', $order))->assertForbidden();
        $this->actingAs($order->user)->get(route('orders.payment-simulate', $order))->assertStatus(405);
        $this->app['env'] = 'local';
        $this->post(route('orders.payment-simulate', $order))->assertStatus(419);
        $this->assertDatabaseCount('marketplace_payment_events', 0);
    }

    public function test_ui_moves_from_retry_to_simulation_then_to_paid(): void
    {
        $order = $this->order(start: false);
        $this->actingAs($order->user)->get(route('orders.show', $order))->assertOk()->assertSee('Riprova avvio pagamento')->assertDontSee('Simula pagamento riuscito');
        $this->post(route('orders.payment-retry', $order))->assertRedirect(route('orders.show', $order));
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('Riprova avvio pagamento')->assertSee('Simula pagamento riuscito')->assertSee('Modalità simulazione: nessun addebito reale verrà effettuato.')->assertSee('name="_token"', false);
        $this->post(route('orders.payment-simulate', $order))->assertRedirect(route('orders.show', $order));
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Stato: Pagato')->assertDontSee('Simula pagamento riuscito')->assertDontSee('Riprova avvio pagamento');
    }

    public function test_simulation_route_is_rate_limited(): void
    {
        $order = $this->order();
        config(['marketplace.economic_rules.execution_mode' => 'disabled']);
        $this->actingAs($order->user);
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('orders.payment-simulate', $order))->assertForbidden();
        }
        $this->post(route('orders.payment-simulate', $order))->assertTooManyRequests();
    }
}
