<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\MarketplaceReturnCase;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceLedger;
use App\Services\MarketplaceQuote;
use App\Services\MarketplaceReturns;
use App\Services\Payments\ApplyExchangePaymentResult;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartExchangeDifferencePayment;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\VendorOrderWorkflow;
use App\Services\WalletBalance;
use App\Support\DisplayMoney;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceFundsFlowAuditTest extends TestCase
{
    use AdditiveRefreshDatabase;

    public function test_deleted_inventory_keeps_order_snapshot_and_can_be_restocked_on_refund(): void
    {
        $r = $this->seller();
        $p = $this->product($r);
        $order = $this->order([$p], true);
        $vendor = $order->vendorOrders()->sole();
        $this->actingAs($r->user)->delete(route('inventory.destroy', $p))->assertRedirect();
        $this->assertSoftDeleted('inventory_items', ['id' => $p->id]);
        $this->assertSame($p->name, $vendor->items()->sole()->name);
        $admin = User::factory()->create(['role' => 'admin']);
        app(VendorOrderWorkflow::class)->refund($admin, $vendor, 'Annullamento prima della consegna');
        $this->assertSame('refunded', $vendor->fresh()->status->value);
        $this->assertSame(10000, InventoryItem::withTrashed()->findOrFail($p->id)->quantity_milliunits);
        $this->assertNull(InventoryItem::find($p->id));
    }

    private function seller(string $plan = 'pro'): Retailer
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', $plan)->sole()->id, 'starts_at' => now()->subDay()]);

        return $r;
    }

    private function product(Retailer $r, array $data = []): InventoryItem
    {
        return InventoryItem::factory()->published()->create(array_replace(['retailer_id' => $r->id, 'quantity' => '10', 'zero_price_cents' => 100000, 'vat_treatment' => 'not_applicable', 'exchange_available' => true, 'pickup_available' => true, 'shipping_available' => true, 'shipping_cost_cents' => 0], $data));
    }

    private function order(array $products, bool $pay = false): Order
    {
        $u = User::factory()->create(['role' => 'customer']);
        foreach ($products as $p) {
            app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        }
        $q = app(MarketplaceQuote::class);
        $o = app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $q->hash($q->build($u, [])));
        if ($pay) {
            app(StartMarketplacePayment::class)->handle($o);
            app(ApplyMarketplacePaymentResult::class)->handle($o, 'audit:paid:'.$o->id, $o->payment->reference, 'paid', $o->total_cents, 'EUR');
        }

        return $o->fresh();
    }

    private function exchange(array $overrides = []): array
    {
        $a = $this->seller();
        $b = $this->seller();
        $pa = $this->product($a, $overrides['a'] ?? []);
        $pb = $this->product($b, $overrides['b'] ?? []);
        $input = ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '1', 'value' => '1000']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '1', 'value' => $overrides['value_b'] ?? '1300']]], 'delivery' => ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']]];
        $input['delivery'] = $overrides['delivery'] ?? $input['delivery'];
        $e = app(ExchangeWorkflow::class)->create($a->user, $pb, $input);

        return [$a, $b, $pa, $pb, $e, $input];
    }

    private function acceptBoth(array $f): void
    {
        [$a,$b,,,$e] = $f;
        $v = $e->currentVersion;
        foreach ([$a, $b] as $r) {
            app(ExchangeWorkflow::class)->accept($r->user, $e, $v->id, $v->summary_hash);
        }
    }

    public function test_multi_vendor_seller_identity_approved_rates_and_future_plan_changes_do_not_change_order(): void
    {
        $free = $this->seller('free');
        $pro = $this->seller();
        Plan::where('code', 'free')->update(['commission_basis_points' => 999]);
        Plan::where('code', 'pro')->update(['commission_basis_points' => 999]);
        $o = $this->order([$this->product($free), $this->product($pro)]);
        $vs = $o->vendorOrders()->with('items')->get()->keyBy('retailer_id');
        $this->assertSame(200, $vs[$free->id]->items->sole()->commission_basis_points);
        $this->assertSame(2000, $vs[$free->id]->items->sole()->commission_cents);
        $this->assertSame(50, $vs[$pro->id]->items->sole()->commission_basis_points);
        $this->assertSame(500, $vs[$pro->id]->items->sole()->commission_cents);
        $this->assertSame($pro->id, $vs[$pro->id]->funds_flow_snapshot['merchandise_seller_id']);
        $this->assertSame('ZeroMagazzino', $vs[$pro->id]->funds_flow_snapshot['commission_tax']['issuer']);
        $this->assertSame(99500, $vs[$pro->id]->funds_flow_snapshot['seller_net_before_psp_and_commission_tax_cents']);
        $this->assertSame(200000, $o->total_cents);
        $this->actingAs($pro->user)->get(route('retailer.dashboard'))->assertOk()->assertSee(DisplayMoney::percent(50))->assertDontSee(DisplayMoney::percent(999));
        $this->assertSame(500, $vs[$pro->id]->fresh()->items->sole()->commission_cents);
    }

    public function test_exchange_fee_only_on_difference_and_receiver_net_matches_approved_example(): void
    {
        $f = $this->exchange();
        [$a,$b,,,$e] = $f;
        $v = $e->currentVersion;
        $this->assertSame(30000, $v->difference_cents);
        $this->assertSame(150, $v->commission_snapshot['amount_cents']);
        $this->assertSame($b->id, $v->commission_snapshot['charged_retailer_id']);
        $this->assertSame(29850, $v->commission_snapshot['recipient_net_cents']);
        $this->assertNull($v->commission_snapshot['service_tax']['tax_cents']);
        $this->acceptBoth($f);
        app(StartExchangeDifferencePayment::class)->handle($v);
        $p = $v->payment;
        app(ApplyExchangePaymentResult::class)->handle($v, 'audit-exchange', $p->reference, 'paid', $p->amount_cents, 'EUR');
        $this->assertSame(29850, app(MarketplaceLedger::class)->balances($b)['pending']);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($b));
    }

    public function test_equal_exchange_has_no_fee_and_no_sale_or_ledger_credit(): void
    {
        $f = $this->exchange(['value_b' => '1000']);
        $this->acceptBoth($f);
        $v = $f[4]->currentVersion;
        $this->assertSame(0, $v->commission_snapshot['amount_cents']);
        $this->assertNull($v->commission_snapshot['charged_retailer_id']);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_two_disposals_are_taxed_separately_and_difference_uses_gross_amounts_due(): void
    {
        config(['marketplace.tax_rates_basis_points.excluded' => 1000]);
        $f = $this->exchange(['a' => ['vat_treatment' => 'excluded']]);
        $v = $f[4]->currentVersion;
        $this->assertSame(20000, $v->difference_cents);
        $this->assertSame(100000, $v->tax_snapshot['disposals']['a']['taxable_cents']);
        $this->assertSame(10000, $v->tax_snapshot['disposals']['a']['tax_cents']);
        $this->assertSame(110000, $v->tax_snapshot['disposals']['a']['gross_cents']);
        $this->assertSame(130000, $v->tax_snapshot['disposals']['b']['gross_cents']);
        $this->assertSame(100, $v->commission_snapshot['amount_cents']);
    }

    public function test_undefined_tax_remains_provisional_and_does_not_simulate_capture(): void
    {
        $f = $this->exchange(['a' => ['vat_treatment' => 'excluded']]);
        $this->acceptBoth($f);
        $v = $f[4]->currentVersion;
        app(StartExchangeDifferencePayment::class)->handle($v);
        $p = $v->payment;
        $this->assertNull($v->tax_snapshot['disposals']['a']['tax_cents']);
        $this->assertNull($v->tax_snapshot['amount_due_b_to_a_cents']);
        $this->assertSame('tax_unresolved', app(ApplyExchangePaymentResult::class)->handle($v, 'undefined-tax', $p->reference, 'paid', $p->amount_cents, 'EUR'));
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_provisional_equal_values_cannot_confirm_an_unknown_actual_difference(): void
    {
        $f = $this->exchange(['value_b' => '1000', 'a' => ['vat_treatment' => 'excluded']]);
        [$a,$b,$pa,$pb,$e] = $f;
        $v = $e->currentVersion;
        $this->assertSame(0, $v->difference_cents);
        $this->actingAs($a->user)->get(route('retailer.exchanges.show', $e))->assertOk()->assertSee('Valori provvisori uguali: conguaglio definitivo da verificare.');
        try {
            app(ExchangeWorkflow::class)->accept($a->user, $e, $v->id, $v->summary_hash);
            $this->fail('Unknown tax confirmed a false zero difference.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tax', $exception->errors());
        }
        $this->assertSame('proposed', $e->fresh()->status->value);
        $this->assertSame(0, $v->acceptances()->count());
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(10000, $pa->fresh()->quantity_milliunits);
        $this->assertSame(10000, $pb->fresh()->quantity_milliunits);
    }

    public function test_receiver_pays_shipping_by_default_and_modified_terms_create_new_acceptance_summary(): void
    {
        $delivery = ['a' => ['mode' => 'shipping', 'cost' => '20', 'destination' => 'Sede B'], 'b' => ['mode' => 'shipping', 'cost' => '10', 'destination' => 'Sede A']];
        $f = $this->exchange(['delivery' => $delivery]);
        [$a,$b,,,$e,$input] = $f;
        $v = $e->currentVersion;
        $this->assertSame($b->id, $v->delivery_a['payer_retailer_id']);
        $this->assertSame($a->id, $v->delivery_b['payer_retailer_id']);
        $this->assertSame(30000, $v->difference_cents);
        app(ExchangeWorkflow::class)->accept($a->user, $e, $v->id, $v->summary_hash);
        $input['key'] = (string) Str::uuid();
        $input += ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $input['delivery']['a']['payer_side'] = 'a';
        $new = app(ExchangeWorkflow::class)->counter($b->user, $e, $input)->currentVersion;
        $this->assertNotSame($v->summary_hash, $new->summary_hash);
        $this->assertSame(0, $new->acceptances()->count());
        $this->assertSame($a->id, $new->delivery_a['payer_retailer_id']);
    }

    public function test_separately_recorded_transport_reimbursement_is_separate_and_included_in_reciprocal_debts(): void
    {
        $f = $this->exchange(['delivery' => ['a' => ['mode' => 'shipping', 'cost' => '20', 'destination' => 'B', 'settlement' => 'sender'], 'b' => ['mode' => 'pickup', 'cost' => '0']]]);
        $v = $f[4]->currentVersion;
        $this->assertSame(2000, $v->tax_snapshot['transport_reimbursement_a_cents']);
        $this->assertSame(100000, $v->value_a_cents);
        $this->assertSame(30000, $v->difference_cents);
        $this->assertTrue($v->payment_provisional);
    }

    public function test_difference_window_is_24_hours_independent_of_30_minute_checkout_reservation(): void
    {
        $this->freezeTime();
        config(['marketplace.reservation_minutes' => 30, 'marketplace.exchange_difference_payment_minutes' => 1440]);
        $f = $this->exchange();
        config(['marketplace.exchange_difference_payment_minutes' => 5]);
        $this->acceptBoth($f);
        $e = $f[4]->fresh();
        $this->assertEquals(now()->addDay()->timestamp, $e->currentVersion->payment->expires_at->timestamp);
        $this->travel(31)->minutes();
        $this->artisan('marketplace:expire-reservations')->assertSuccessful();
        $this->assertSame('awaiting_difference_payment', $e->fresh()->status->value);
        $this->travel(1410)->minutes();
        $this->artisan('marketplace:expire-exchanges')->assertSuccessful();
        $this->assertSame('expired', $e->fresh()->status->value);
        $this->assertSame(2, $e->stockReservations()->where('status', 'expired')->count());
    }

    public function test_pre_dispatch_refund_restores_stock_commission_and_seller_credit_once(): void
    {
        $r = $this->seller('free');
        $p = $this->product($r);
        $o = $this->order([$p], true);
        $v = $o->vendorOrders()->sole();
        $admin = User::factory()->create(['role' => 'admin']);
        $ledger = app(MarketplaceLedger::class);
        $this->assertSame(98000, $ledger->balances($r)['pending']);
        app(VendorOrderWorkflow::class)->refund($admin, $v, 'Annullato prima spedizione');
        app(VendorOrderWorkflow::class)->refund($admin, $v, 'Retry');
        $this->assertSame(0, $ledger->balances($r)['pending']);
        $this->assertSame(10000, $p->fresh()->quantity_milliunits);
        $this->assertDatabaseCount('marketplace_stock_returns', 1);
        $this->assertSame(1, WalletTransaction::where('operation_kind', 'commission_reversal')->count());
        $this->assertSame(2000, MarketplaceReturnCase::sole()->financial_snapshot['commission_reversal_cents']);
        $this->assertSame(3, MarketplaceReturnCase::sole()->events()->count());
    }

    public function test_refund_and_recovery_after_credit_payout_are_distinct_and_allow_negative_balance(): void
    {
        $r = $this->seller('free');
        $o = $this->order([$this->product($r)], true);
        $v = $o->vendorOrders()->sole();
        $ledger = app(MarketplaceLedger::class);
        $ledger->releaseVendor($v);
        $ledger->releaseVendor($v);
        $this->assertSame(98000, $ledger->balances($r)['available']);
        $ledger->append($r, 'audit-payout', 'payout', 'available', -98000, ['vendor_order_id' => $v->id]);
        app(VendorOrderWorkflow::class)->refund(User::factory()->create(['role' => 'admin']), $v, 'Recupero credito');
        $this->assertSame(-98000, $ledger->balances($r)['available']);
        $this->assertSame(1, WalletTransaction::where('operation_kind', 'recovery')->count());
        $this->assertDatabaseCount('marketplace_refunds', 1);
        $this->assertSame(0, app(WalletBalance::class)->availableCents($r));
    }

    public function test_post_dispatch_return_requires_review_and_never_automatically_refunds_or_restocks(): void
    {
        $r = $this->seller();
        $p = $this->product($r);
        $o = $this->order([$p], true);
        $v = $o->vendorOrders()->sole();
        $flow = app(VendorOrderWorkflow::class);
        $flow->advance($r->user, $v, 'preparing');
        $flow->advance($r->user, $v, 'ready_for_pickup');
        $flow->advance($r->user, $v, 'completed');
        $admin = User::factory()->create(['role' => 'admin']);
        $case = $flow->refund($admin, $v, 'Verificare la restituzione');
        $this->assertInstanceOf(MarketplaceReturnCase::class, $case);
        $this->assertSame('requested', $case->status);
        $this->assertNull($case->approved_amount_cents);
        $this->assertSame(9000, $p->fresh()->quantity_milliunits);
        $this->assertDatabaseCount('marketplace_refunds', 0);
        app(MarketplaceReturns::class)->review($admin, $case, true, 50000, 'Approvazione parziale da valutazione');
        $this->assertSame(50000, $case->fresh()->approved_amount_cents);
        $this->assertSame(2, $case->events()->count());
        $this->assertDatabaseCount('marketplace_refunds', 0);
    }

    public function test_ledger_retry_and_database_immutability_and_vendor_owner_constraints(): void
    {
        $r = $this->seller();
        $other = $this->seller();
        $o = $this->order([$this->product($r)], true);
        $v = $o->vendorOrders()->sole();
        $ledger = app(MarketplaceLedger::class);
        $before = WalletTransaction::count();
        $ledger->creditVendor($v);
        $this->assertSame($before, WalletTransaction::count());
        $original = WalletTransaction::firstOrFail();
        try {
            DB::table('wallet_transactions')->where('id', $original->id)->update(['amount_cents' => 123]);
            $this->fail('Ledger mutable');
        } catch (QueryException) {
        }
        try {
            $ledger->append($other, 'foreign-vendor', 'pending_credit', 'pending', 100, ['vendor_order_id' => $v->id]);
            $this->fail('Foreign owner accepted');
        } catch (QueryException) {
        }
        $this->assertSame($before, WalletTransaction::count());
    }
}
