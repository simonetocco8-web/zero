<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CustomerPromotionalBonus;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceLedger;
use App\Services\MarketplaceQuote;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\ReceiveMarketplaceStripeEvent;
use App\Services\Payments\RefundMarketplaceVendor;
use App\Services\Payments\StartExchangeDifferencePayment;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\VendorOrderWorkflow;
use App\Support\ProportionalAllocation;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceStripeConnectTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private object $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplace.commission_tax.treatment' => 'not_applicable']);
        $this->http = new class implements ClientInterface
        {
            public array $requests = [];

            public bool $fail = false;

            public string $refundStatus = 'succeeded';

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = compact('method', 'absUrl', 'headers', 'params');
                if ($this->fail) {
                    throw new \RuntimeException('Ambiguous network timeout');
                }
                if (str_contains($absUrl, '/refunds')) {
                    $body = ['id' => 're_test_1', 'object' => 'refund', 'status' => $this->refundStatus, 'amount' => $params['amount'] ?? 9000, 'payment_intent' => $params['payment_intent'] ?? 'pi_test_1', 'currency' => 'eur'];
                } else {
                    $body = ['id' => 'cs_test_'.$params['client_reference_id'], 'object' => 'checkout.session', 'livemode' => false, 'url' => 'https://checkout.stripe.com/c/pay/test_session'];
                }

                return [json_encode($body), 200, []];
            }
        };
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(new CurlClient);
        parent::tearDown();
    }

    private function activate(): void
    {
        config(['marketplace.economic_rules.execution_mode' => 'stripe_connect', 'marketplace.economic_rules.funds_flow' => 'stripe_connect_separate_charges_transfers', 'marketplace.stripe.secret' => 'sk_test_marketplace_fixture_only', 'marketplace.stripe.webhook_secret' => 'whsec_marketplace_fixture_only', 'marketplace.stripe.activation_verified' => true, 'marketplace.stripe.tax_verified' => true, 'marketplace.stripe.live_enabled' => false]);
    }

    private function order(bool $bonus = false, string $plan = 'free', int $vendors = 1, bool $shortBonus = false): Order
    {
        $this->seed(PlanSeeder::class);
        $u = User::factory()->create(['role' => 'customer']);
        if ($bonus) {
            $present = now();
            if ($shortBonus) {
                $this->travelTo($present->subYear()->addMinute());
            }
            app(CustomerPromotionalBonus::class)->grant(User::factory()->create(['role' => 'admin']), $u, 20000, 'launch', 'campaign:'.$u->id);
            if ($shortBonus) {
                $this->travelBack();
            }
        }
        for ($i = 0; $i < $vendors; $i++) {
            $r = Retailer::factory()->approved()->create();
            Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', $plan)->sole()->id, 'starts_at' => now()->subDay()]);
            DB::table('marketplace_connected_accounts')->insert(['retailer_id' => $r->id, 'stripe_account_id' => 'acct_test_'.$r->id, 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true, 'transfers_active' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $p = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '1', 'zero_price_cents' => 10000, 'vat_treatment' => 'not_applicable', 'pickup_available' => true]);
            app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        }
        $q = app(MarketplaceQuote::class);

        return app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $q->hash($q->build($u, [])), $bonus);
    }

    private function body(Order $o, string $id = 'evt_marketplace_1', string $type = 'checkout.session.completed', array $overrides = []): string
    {
        $payment = $o->payment()->first();

        return json_encode(['id' => $id, 'object' => 'event', 'livemode' => false, 'type' => $type, 'data' => ['object' => array_replace(['id' => $payment->reference, 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_1', 'amount_total' => $payment->card_cents ?? $payment->amount_cents, 'currency' => 'eur', 'metadata' => ['marketplace_kind' => 'order', 'marketplace_id' => (string) $o->id, 'marketplace_key' => hash('sha256', 'marketplace-order:'.$o->checkout_key)]], $overrides)]], JSON_THROW_ON_ERROR);
    }

    private function signature(string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_marketplace_fixture_only');
    }

    private function receive(string $body): string
    {
        return app(ReceiveMarketplaceStripeEvent::class)->handle($body, $this->signature($body));
    }

    public function test_card_payment_is_one_platform_charge_for_multiple_vendors_and_snapshot_free_fees(): void
    {
        $o = $this->order(false, 'free', 2);
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        app(StartMarketplacePayment::class)->handle($o);
        $this->assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        $this->assertSame(20000, $request['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('zm_order_'.$o->id, $request['params']['payment_intent_data']['transfer_group']);
        $this->assertArrayNotHasKey('transfer_data', $request['params']['payment_intent_data']);
        $this->assertArrayNotHasKey('application_fee_amount', $request['params']['payment_intent_data']);
        $this->assertStringContainsString('sk_test_marketplace_fixture_only', implode(' ', $request['headers']));
        $this->assertSame('consumed', $this->receive($this->body($o)));
        $this->assertSame('paid', $o->fresh()->status->value);
        foreach ($o->vendorOrders as $v) {
            $this->assertSame(200, $v->items->first()->commission_basis_points);
            $this->assertSame(200, $v->items->first()->commission_cents);
            $this->assertSame(['pending' => 9800, 'available' => 0, 'reserved' => 0], app(MarketplaceLedger::class)->balances($v->retailer, false));
        }
        $this->assertSame(0, (int) InventoryItem::sum('quantity_milliunits'));
        $this->assertDatabaseCount('marketplace_stripe_receipts', 1);
    }

    public function test_pro_commission_snapshot_and_duplicate_webhook_have_no_double_stock_or_ledger_effect(): void
    {
        $o = $this->order(false, 'pro');
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $body = $this->body($o);
        $this->assertSame('consumed', $this->receive($body));
        $this->assertSame('consumed', $this->receive($body));
        $this->assertSame('already_paid', $this->receive($this->body($o, 'evt_second_paid')));
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(50, $o->vendorOrders->first()->items->first()->commission_cents);
        $this->assertSame(0, (int) InventoryItem::sum('quantity_milliunits'));
    }

    public function test_bonus_and_card_consume_only_on_success_and_seller_receives_full_price(): void
    {
        $o = $this->order(true);
        $u = User::find($o->user_id);
        $this->assertSame(1000, $o->payment->bonus_cents);
        $this->assertSame(9000, $o->payment->card_cents);
        $this->assertSame(19000, app(CustomerPromotionalBonus::class)->available($u));
        $this->assertSame(0, DB::table('customer_bonus_entries')->where('bucket', 'spent')->sum('amount_cents'));
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $this->assertSame(9000, $this->http->requests[0]['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('consumed', $this->receive($this->body($o)));
        $this->assertSame(1000, (int) DB::table('customer_bonus_entries')->where('bucket', 'spent')->sum('amount_cents'));
        $this->assertSame(9800, app(MarketplaceLedger::class)->balances($o->vendorOrders->first()->retailer, false)['pending']);
    }

    public function test_expired_session_releases_bonus_and_stock_and_late_payment_requires_reconciliation(): void
    {
        $o = $this->order(true);
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $this->assertSame('released', $this->receive($this->body($o, 'evt_expired', 'checkout.session.expired')));
        $this->assertSame(20000, app(CustomerPromotionalBonus::class)->available(User::find($o->user_id)));
        $this->assertSame('reconciliation_required', $this->receive($this->body($o, 'evt_late')));
        $this->assertSame(1000, (int) InventoryItem::sum('quantity_milliunits'));
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_no_bonus_available_still_charges_card_and_retailer_cannot_spend_wallet(): void
    {
        $o = $this->order();
        $this->assertSame(0, $o->payment->bonus_cents);
        $this->assertSame(10000, $o->payment->card_cents);
        $this->assertFalse(config('marketplace.credit.retailer_spending_enabled'));
        $this->assertSame(0, app(CustomerPromotionalBonus::class)->available(User::factory()->create(['role' => 'retailer'])));
    }

    public function test_invalid_signature_wrong_amount_and_unsigned_fake_adapter_cannot_settle_real_payment(): void
    {
        $o = $this->order();
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $body = $this->body($o);
        $this->call('POST', '/webhooks/marketplace/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'bad', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
        $wrong = $this->body($o, 'evt_bad_amount', overrides: ['amount_total' => 1]);
        $this->call('POST', '/webhooks/marketplace/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $this->signature($wrong), 'CONTENT_TYPE' => 'application/json'], $wrong)->assertStatus(400);
        try {
            app(ApplyMarketplacePaymentResult::class)->handle($o, 'fake_attempt', $o->payment()->first()->reference, 'paid', $o->total_cents, 'EUR');
            $this->fail('Unsigned fake boundary settled real payment.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('payment_pending', $o->fresh()->status->value);
        }
        $this->assertDatabaseCount('marketplace_stripe_receipts', 0);
    }

    public function test_missing_connected_account_or_fiscal_verification_fails_before_provider_request(): void
    {
        $o = $this->order();
        $this->activate();
        config(['marketplace.stripe.tax_verified' => false]);
        try {
            app(StartMarketplacePayment::class)->handle($o);
            $this->fail('Missing tax verification accepted.');
        } catch (ValidationException) {
            $this->assertCount(0, $this->http->requests);
        }
        config(['marketplace.stripe.tax_verified' => true]);
        DB::table('marketplace_connected_accounts')->update(['transfers_active' => false]);
        try {
            app(StartMarketplacePayment::class)->handle($o);
            $this->fail('Unverified beneficiary accepted.');
        } catch (ValidationException) {
            $this->assertCount(0, $this->http->requests);
        }
    }

    public function test_ambiguous_gateway_failure_retries_same_idempotency_key_without_double_reservation(): void
    {
        $o = $this->order(true);
        $this->activate();
        $this->http->fail = true;
        try {
            app(StartMarketplacePayment::class)->handle($o);
            $this->fail('Expected provider timeout.');
        } catch (\RuntimeException) {
            $this->assertNull($o->payment()->first()->reference);
        }
        $this->http->fail = false;
        app(StartMarketplacePayment::class)->handle($o);
        $this->assertSame($this->http->requests[0]['params'], $this->http->requests[1]['params']);
        $this->assertSame($this->http->requests[0]['headers'], $this->http->requests[1]['headers']);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame(1000, (int) DB::table('customer_bonus_entries')->where('bucket', 'reserved')->sum('amount_cents'));
    }

    public function test_real_refund_restores_bonus_stock_and_commission_idempotently(): void
    {
        $o = $this->order(true);
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $this->receive($this->body($o));
        $v = $o->vendorOrders->first();
        $admin = User::factory()->create(['role' => 'admin']);
        $case = app(VendorOrderWorkflow::class)->refund($admin, $v, 'Annullamento prima del ritiro');
        $this->assertSame('refunded', $case->status);
        $this->assertSame('refunded', $v->fresh()->status->value);
        $this->assertSame(20000, app(CustomerPromotionalBonus::class)->available(User::find($o->user_id)));
        $this->assertSame(1000, (int) InventoryItem::sum('quantity_milliunits'));
        $this->assertSame(0, app(MarketplaceLedger::class)->balances($v->retailer, false)['pending']);
        app(RefundMarketplaceVendor::class)->handle($admin, $v, 'Retry');
        $this->assertDatabaseCount('marketplace_provider_refunds', 1);
        $this->assertSame(9000, $this->http->requests[1]['params']['amount']);
        $this->assertCount(2, $this->http->requests);
    }

    public function test_pending_provider_refund_blocks_handover_and_restores_nothing_until_success(): void
    {
        $o = $this->order(true);
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $this->receive($this->body($o));
        $v = $o->vendorOrders->first();
        $flow = app(VendorOrderWorkflow::class);
        $flow->advance($v->retailer->user, $v, 'preparing');
        $flow->advance($v->retailer->user, $v, 'ready_for_pickup');
        $this->http->refundStatus = 'pending';
        $case = $flow->refund(User::factory()->create(['role' => 'admin']), $v, 'Prima del ritiro');
        $this->assertSame('approved', $case->status);
        $this->assertSame(0, (int) InventoryItem::sum('quantity_milliunits'));
        $this->assertSame(19000, app(CustomerPromotionalBonus::class)->available(User::find($o->user_id)));
        try {
            $flow->receive(User::find($o->user_id), $v);
            $this->fail('Handover allowed during PSP refund.');
        } catch (ValidationException) {
            $this->assertSame('ready_for_pickup', $v->fresh()->status->value);
        }
        $this->http->refundStatus = 'succeeded';
        $this->artisan('marketplace:reconcile-payments')->assertSuccessful();
        $this->assertSame('refunded', $v->fresh()->status->value);
        $this->assertSame(20000, app(CustomerPromotionalBonus::class)->available(User::find($o->user_id)));
        $this->assertSame(1000, (int) InventoryItem::sum('quantity_milliunits'));
    }

    public function test_bonus_expires_and_history_cannot_be_mutated(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $u = User::factory()->create(['role' => 'customer']);
        $bonus = app(CustomerPromotionalBonus::class);
        $bonus->grant($admin, $u, 500, 'campaign', 'grant_once');
        $bonus->grant($admin, $u, 500, 'campaign', 'grant_once');
        $this->assertSame(500, $bonus->available($u));
        $this->travel(13)->months();
        $this->assertSame(0, $bonus->available($u));
        $this->expectException(QueryException::class);
        DB::table('customer_bonus_entries')->update(['amount_cents' => 999]);
    }

    public function test_exchange_difference_uses_card_and_a_separate_payee_ledger_credit(): void
    {
        $this->seed(PlanSeeder::class);
        $parties = [];
        foreach (['a', 'b'] as $side) {
            $r = Retailer::factory()->approved()->create();
            Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()->subDay()]);
            $p = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '1', 'vat_treatment' => 'not_applicable', 'exchange_available' => true, 'pickup_available' => true]);
            DB::table('marketplace_connected_accounts')->insert(['retailer_id' => $r->id, 'stripe_account_id' => 'acct_exchange_'.$r->id, 'details_submitted' => true, 'charges_enabled' => true, 'payouts_enabled' => true, 'transfers_active' => true, 'verified_at' => now()]);
            $parties[$side] = [$r, $p];
        }
        [$a, $pa] = $parties['a'];
        [$b, $pb] = $parties['b'];
        $flow = app(ExchangeWorkflow::class);
        $e = $flow->create($a->user, $pb, ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '1', 'value' => '1000']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '1', 'value' => '1300']]], 'delivery' => ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']]]);
        $v = $e->currentVersion;
        $flow->accept($a->user, $e, $v->id, $v->summary_hash);
        $flow->accept($b->user, $e, $v->id, $v->summary_hash);
        $this->activate();
        app(StartExchangeDifferencePayment::class)->handle($v);
        $p = $v->payment()->first();
        $key = 'exchange:'.$e->uuid.':version:'.$v->id;
        $body = json_encode(['id' => 'evt_exchange_paid', 'object' => 'event', 'livemode' => false, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $p->reference, 'mode' => 'payment', 'payment_status' => 'paid', 'payment_intent' => 'pi_exchange_test', 'amount_total' => 30000, 'currency' => 'eur', 'metadata' => ['marketplace_kind' => 'exchange', 'marketplace_id' => (string) $v->id, 'marketplace_key' => hash('sha256', $key)]]]]);
        $this->assertSame('confirmed', $this->receive($body));
        $this->assertSame('confirmed', $this->receive($body));
        $this->assertSame(29850, app(MarketplaceLedger::class)->balances($b, false)['pending']);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customer_bonus_entries', 0);
        $this->assertSame(0, (int) InventoryItem::sum('quantity_milliunits'));
    }

    public function test_service_vat_is_explicit_and_separate_from_the_commission_rate(): void
    {
        config(['marketplace.commission_tax.treatment' => 'excluded', 'marketplace.commission_tax.rate_basis_points' => 1000]);
        $o = $this->order(false, 'pro');
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $this->receive($this->body($o));
        $v = $o->vendorOrders->first();
        $this->assertSame(50, $v->items->first()->commission_cents);
        $this->assertSame(5, $v->funds_flow_snapshot['commission_tax']['tax_cents']);
        $this->assertSame(9945, app(MarketplaceLedger::class)->balances($v->retailer, false)['pending']);
    }

    public function test_late_capture_reconciliation_refunds_only_card_without_reconsuming_bonus_or_stock(): void
    {
        $o = $this->order(true);
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $this->receive($this->body($o, 'evt_expire_before_late', 'checkout.session.expired'));
        $this->receive($this->body($o, 'evt_after_expire'));
        $this->artisan('marketplace:reconcile-payments')->assertSuccessful();
        $this->artisan('marketplace:reconcile-payments')->assertSuccessful();
        $this->assertSame(9000, $this->http->requests[1]['params']['amount']);
        $this->assertDatabaseHas('marketplace_late_capture_refunds', ['order_id' => $o->id, 'status' => 'succeeded', 'amount_cents' => 9000]);
        $this->assertSame(20000, app(CustomerPromotionalBonus::class)->available(User::find($o->user_id)));
        $this->assertSame(1000, (int) InventoryItem::sum('quantity_milliunits'));
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertCount(2, $this->http->requests);
    }

    public function test_seller_suspended_during_payment_releases_stock_and_requires_refund(): void
    {
        $o = $this->order(true);
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $o->vendorOrders->first()->retailer->update(['status' => 'suspended']);
        $this->assertSame('reconciliation_required', $this->receive($this->body($o)));
        $this->assertSame('cancelled', $o->fresh()->status->value);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(20000, app(CustomerPromotionalBonus::class)->available(User::find($o->user_id)));
    }

    public function test_bonus_expiry_during_checkout_does_not_consume_an_expired_campaign_grant(): void
    {
        $o = $this->order(true, shortBonus: true);
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $this->travel(2)->minutes();
        $this->assertSame('reconciliation_required', $this->receive($this->body($o)));
        $this->assertSame('cancelled', $o->fresh()->status->value);
        $this->assertSame(0, (int) DB::table('customer_bonus_entries')->where('bucket', 'spent')->sum('amount_cents'));
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_stale_signature_cannot_create_a_receipt(): void
    {
        $o = $this->order();
        $this->activate();
        app(StartMarketplacePayment::class)->handle($o);
        $body = $this->body($o);
        $this->call('POST', '/webhooks/marketplace/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $this->signature($body, time() - 600), 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(400);
        $this->assertDatabaseCount('marketplace_stripe_receipts', 0);
    }

    public function test_proportional_allocation_never_loses_cents(): void
    {
        $this->assertSame([1 => 4, 2 => 3, 3 => 3], ProportionalAllocation::split(10, [3 => 1, 2 => 1, 1 => 1]));
        $this->assertSame([1 => 33, 2 => 67], ProportionalAllocation::split(100, [1 => 1, 2 => 2]));
    }
}
