<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Contracts\ExchangePaymentGatewayInterface;
use App\Contracts\MarketplacePaymentGatewayInterface;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CustomerPromotionalBonus;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceLedger;
use App\Services\MarketplaceQuote;
use App\Services\Payments\ApplyExchangePaymentResult;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartExchangeDifferencePayment;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\RetailerCredit;
use App\Services\StockReservations;
use App\Services\VendorOrderWorkflow;
use App\Services\WalletBalance;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class RetailerCreditTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function retailer(int $credit = 0, bool $simulated = true): Retailer
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()->subDay()]);
        if ($credit) {
            WalletTransaction::create(['retailer_id' => $r->id, 'idempotency_key' => 'opening:'.$r->id, 'type' => 'adjustment', 'amount_cents' => $credit, 'currency' => 'EUR', 'entry_bucket' => 'available', 'simulated' => $simulated, 'available_at' => now()->subMinute(), 'description' => 'Matured test credit']);
        }

        return $r;
    }

    private function product(?Retailer $r = null, int $cents = 50000): InventoryItem
    {
        $r ??= $this->retailer();

        return InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '5', 'zero_price_cents' => $cents, 'vat_treatment' => 'not_applicable', 'pickup_available' => true, 'exchange_available' => true]);
    }

    private function order(Retailer $buyer, array $prices = [50000]): Order
    {
        foreach ($prices as $price) {
            app(MarketplaceCart::class)->save($buyer->user, $this->product(null, $price), '1', 'pickup');
        }
        $q = app(MarketplaceQuote::class);

        return app(CreateMarketplaceOrder::class)->handle($buyer->user, (string) Str::uuid(), [], null, $q->hash($q->build($buyer->user, [])));
    }

    private function settle(Order $order, string $state = 'paid', string $event = 'event'): string
    {
        return app(ApplyMarketplacePaymentResult::class)->handle($order, $event, $order->fresh()->payment->reference, $state, $order->total_cents, 'EUR');
    }

    private function exchange(int $credit): array
    {
        $a = $this->retailer($credit);
        $b = $this->retailer();
        $pa = $this->product($a);
        $pb = $this->product($b);
        $flow = app(ExchangeWorkflow::class);
        $e = $flow->create($a->user, $pb, ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '1', 'value' => '100']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '1', 'value' => '400']]], 'delivery' => ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']]]);
        $v = $e->currentVersion;
        $flow->accept($a->user, $e, $v->id, $v->summary_hash);
        $flow->accept($b->user, $e, $v->id, $v->summary_hash);

        return [$a, $b, $e->fresh()->currentVersion];
    }

    public function test_credit_only_purchase_consumes_once_without_gateway(): void
    {
        $r = $this->retailer(50000);
        $o = $this->order($r);
        $this->assertSame(50000, $o->payment->retailer_credit_cents);
        $this->assertSame(0, $o->payment->card_cents);
        $this->assertSame(0, app(RetailerCredit::class)->available($r));
        $this->mock(MarketplacePaymentGatewayInterface::class)->shouldNotReceive('createIntent');
        app(StartMarketplacePayment::class)->handle($o);
        app(StartMarketplacePayment::class)->handle($o);
        $this->assertSame('paid', $o->fresh()->status->value);
        $this->assertSame(1, WalletTransaction::where('operation_kind', 'purchase_credit_spend')->count());
        $this->assertSame('spent', DB::table('retailer_credit_holds')->sole()->status);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($r)); // simulated never withdrawable
    }

    public function test_credit_plus_card_reserves_200_and_charges_300_of_500(): void
    {
        $r = $this->retailer(20000);
        $o = $this->order($r);
        $this->assertSame(20000, $o->payment->retailer_credit_cents);
        $this->assertSame(30000, $o->payment->card_cents);
        app(StartMarketplacePayment::class)->handle($o);
        $this->assertSame('consumed', $this->settle($o));
        $this->assertSame('consumed', $this->settle($o));
        $this->assertSame('already_paid', $this->settle($o, 'paid', 'retry'));
        $this->assertSame(1, WalletTransaction::where('operation_kind', 'purchase_credit_spend')->count());
    }

    public function test_no_credit_uses_card_and_pending_credit_is_not_spendable(): void
    {
        $r = $this->retailer();
        WalletTransaction::create(['retailer_id' => $r->id, 'idempotency_key' => 'pending', 'type' => 'adjustment', 'amount_cents' => 50000, 'entry_bucket' => 'pending', 'simulated' => true]);
        $o = $this->order($r);
        $this->assertSame(0, $o->payment->retailer_credit_cents);
        $this->assertSame(50000, $o->payment->card_cents);
        $this->assertDatabaseCount('retailer_credit_holds', 0);
    }

    public function test_failure_releases_credit_and_late_success_cannot_spend_it(): void
    {
        $r = $this->retailer(20000);
        $o = $this->order($r);
        app(StartMarketplacePayment::class)->handle($o);
        $this->assertSame('released', $this->settle($o, 'failed'));
        $this->assertSame('released', $this->settle($o, 'failed'));
        $this->assertSame(20000, app(RetailerCredit::class)->available($r));
        $this->assertSame('reconciliation_required', $this->settle($o, 'paid', 'late'));
        $this->assertSame(0, WalletTransaction::where('operation_kind', 'purchase_credit_spend')->count());
        $this->assertSame(5000, InventoryItem::sole()->quantity_milliunits);
    }

    public function test_expiry_releases_credit_and_stock_at_configured_30_minutes(): void
    {
        $this->freezeTime();
        $r = $this->retailer(20000);
        $o = $this->order($r);
        $this->assertSame(now()->addMinutes(30)->timestamp, $o->stockReservations()->sole()->expires_at->timestamp);
        $this->travel(31)->minutes();
        app(StockReservations::class)->expire();
        app(StockReservations::class)->expire();
        $this->assertSame(20000, app(RetailerCredit::class)->available($r));
        $this->assertSame('expired', $o->fresh()->payment->status);
        $this->assertSame(5000, InventoryItem::sole()->available_quantity_milliunits);
    }

    public function test_multi_vendor_allocation_is_deterministic_and_refunds_restore_only_original_credit(): void
    {
        $r = $this->retailer(20001);
        $o = $this->order($r, [10000, 20000, 20000]);
        $p = $o->payment;
        $this->assertSame([4000, 8001, 8000], array_values($p->funding_snapshot['credit_by_vendor']));
        $this->assertSame(50000, $p->card_cents + $p->retailer_credit_cents);
        app(StartMarketplacePayment::class)->handle($o);
        $this->settle($o);
        $admin = User::factory()->create(['role' => 'admin']);
        $v = $o->vendorOrders()->orderBy('id')->first();
        app(VendorOrderWorkflow::class)->refund($admin, $v, 'Full vendor refund');
        app(VendorOrderWorkflow::class)->refund($admin, $v, 'Retry');
        $this->assertSame(4000, app(RetailerCredit::class)->available($r));
        $this->assertSame(1, WalletTransaction::where('operation_kind', 'credit_refund')->count());
        foreach ($o->vendorOrders()->where('id', '<>', $v->id)->get() as $other) {
            app(VendorOrderWorkflow::class)->refund($admin, $other, 'Full refund');
        }
        $this->assertSame(20001, app(RetailerCredit::class)->available($r));
        $this->assertSame('refunded', $o->fresh()->status->value);
    }

    public function test_another_checkout_cannot_consume_reserved_credit(): void
    {
        $r = $this->retailer(20000);
        $one = $this->order($r);
        $two = $this->order($r);
        $this->assertSame(20000, $one->payment->retailer_credit_cents);
        $this->assertSame(0, $two->payment->retailer_credit_cents);
        app(StockReservations::class)->release($one);
        $this->assertSame(20000, app(RetailerCredit::class)->available($r));
    }

    public function test_credit_only_difference_books_full_recipient_amount_and_half_percent_fee_once(): void
    {
        [$a, $b, $v] = $this->exchange(30000);
        $this->mock(ExchangePaymentGatewayInterface::class)->shouldNotReceive('createDifferenceIntent');
        app(StartExchangeDifferencePayment::class)->handle($v);
        app(StartExchangeDifferencePayment::class)->handle($v);
        $this->assertSame(0, $v->fresh()->payment->card_cents);
        $this->assertSame('confirmed', $v->exchange->fresh()->status->value);
        $this->assertSame(150, $v->commission_snapshot['amount_cents']);
        $this->assertSame(29850, app(MarketplaceLedger::class)->balances($b)['pending']);
        $this->assertSame(1, WalletTransaction::where('operation_kind', 'exchange_difference_credit_spend')->count());
        app(ExchangeWorkflow::class)->locked($v->exchange, function () use ($v) {
            app(RetailerCredit::class)->reverse($v, 30000, 'assisted-return');
            app(RetailerCredit::class)->reverse($v, 30000, 'assisted-return');
        });
        $this->assertSame(30000, app(RetailerCredit::class)->available($a));
    }

    public function test_difference_credit_plus_card_and_failed_difference_release(): void
    {
        [$a, $b, $v] = $this->exchange(10000);
        app(StartExchangeDifferencePayment::class)->handle($v);
        $p = $v->fresh()->payment;
        $this->assertSame(10000, $p->retailer_credit_cents);
        $this->assertSame(20000, $p->card_cents);
        $apply = app(ApplyExchangePaymentResult::class);
        $this->assertSame('released', $apply->handle($v, 'failed', $p->reference, 'failed', 30000, 'EUR'));
        $this->assertSame('released', $apply->handle($v, 'failed', $p->reference, 'failed', 30000, 'EUR'));
        $this->assertSame(10000, app(RetailerCredit::class)->available($a));
        $this->assertSame(0, app(MarketplaceLedger::class)->balances($b)['pending']);
        $this->assertSame('reconciliation_required', $apply->handle($v, 'late', $p->reference, 'paid', 30000, 'EUR'));
    }

    public function test_successful_mixed_difference_consumes_credit_once(): void
    {
        [$a, $b, $v] = $this->exchange(10000);
        app(StartExchangeDifferencePayment::class)->handle($v);
        $p = $v->fresh()->payment;
        $apply = app(ApplyExchangePaymentResult::class);
        $this->assertSame('confirmed', $apply->handle($v, 'paid', $p->reference, 'paid', 30000, 'EUR'));
        $this->assertSame('already_paid', $apply->handle($v, 'retry', $p->reference, 'paid', 30000, 'EUR'));
        $this->assertSame(0, app(RetailerCredit::class)->available($a));
        $this->assertSame(29850, app(MarketplaceLedger::class)->balances($b)['pending']);
    }

    public function test_customer_bonus_cannot_be_used_by_retailer_and_real_credit_is_not_used_in_simulation(): void
    {
        $r = $this->retailer(20000, false);
        $this->assertSame(0, app(CustomerPromotionalBonus::class)->available($r->user));
        $this->assertSame(0, app(RetailerCredit::class)->available($r));
        $o = $this->order($r);
        $this->assertSame(0, $o->payment->retailer_credit_cents);
        $this->assertSame(20000, app(WalletBalance::class)->availableCents($r));
        $this->assertDatabaseCount('customer_bonus_entries', 0);
    }

    public function test_funding_snapshot_is_immutable_even_via_query_builder(): void
    {
        $o = $this->order($this->retailer(20000));
        $this->expectException(QueryException::class);
        DB::table('marketplace_payments')->where('order_id', $o->id)->update(['funding_snapshot' => '{}']);
    }

    public function test_credit_hold_cannot_be_reassigned_to_another_retailer(): void
    {
        $this->order($this->retailer(20000));
        $other = $this->retailer();
        $this->expectException(QueryException::class);
        DB::table('retailer_credit_holds')->update(['retailer_id' => $other->id]);
    }

    public function test_credit_reversal_cannot_exceed_the_original_spend(): void
    {
        $o = $this->order($this->retailer(50000));
        app(StartMarketplacePayment::class)->handle($o);
        $this->expectException(ValidationException::class);
        app(StockReservations::class)->locked($o, fn () => app(RetailerCredit::class)->reverse($o, 50001, 'too-large'));
    }

    public function test_same_checkout_key_returns_existing_order_without_new_credit_reservation(): void
    {
        $r = $this->retailer(20000);
        $o = $this->order($r);
        $retry = app(CreateMarketplaceOrder::class)->handle($r->user, $o->checkout_key, [], null, 'already-created');
        $this->assertSame($o->id, $retry->id);
        $this->assertDatabaseCount('retailer_credit_holds', 1);
        $this->assertSame(2, WalletTransaction::where('operation_kind', 'credit_reservation')->count());
    }

    public function test_difference_cancel_and_expiry_release_credit_without_consumption(): void
    {
        [$a, $b, $v] = $this->exchange(10000);
        app(ExchangeWorkflow::class)->stop($a->user, $v->exchange, $v->id, $v->summary_hash, 'cancelled');
        $this->assertSame(10000, app(RetailerCredit::class)->available($a));
        [$a2, $b2, $v2] = $this->exchange(10000);
        $this->travel(1441)->minutes();
        app(StockReservations::class)->expire();
        $this->assertSame(10000, app(RetailerCredit::class)->available($a2));
        $this->assertSame('expired', $v2->exchange->fresh()->status->value);
        $this->assertSame(0, WalletTransaction::where('operation_kind', 'exchange_difference_credit_spend')->count());
    }

    public function test_disabled_execution_and_credit_configuration_fail_closed(): void
    {
        $r = $this->retailer(20000);
        config(['marketplace.credit.retailer_spending_enabled' => false]);
        $o = $this->order($r);
        $this->assertSame(0, $o->payment->retailer_credit_cents);
        config(['marketplace.credit.retailer_spending_enabled' => true, 'marketplace.economic_rules.execution_mode' => 'disabled']);
        $this->expectException(ValidationException::class);
        app(StartMarketplacePayment::class)->handle($o);
    }
}
