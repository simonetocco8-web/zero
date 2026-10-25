<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\SaveCustomerAddress;
use App\Enums\MarketplaceNoticeKind as Kind;
use App\Jobs\DeliverMarketplaceNotice;
use App\Mail\MarketplaceNoticeMail;
use App\Models\InventoryItem;
use App\Models\MarketplaceNoticeOutbox;
use App\Models\MarketplaceRefund;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminMarketplaceRecords;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceNotices;
use App\Services\MarketplaceQuote;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\SimulateExchangeDifferencePayment;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\StockReservations;
use App\Services\VendorOrderWorkflow;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplacePrivateAreasTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function retailer(string $plan = 'free'): Retailer
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', $plan)->sole()->id, 'starts_at' => now()->subDay()]);

        return $r;
    }

    private function product(Retailer $r, string $name = 'Prodotto riservato'): InventoryItem
    {
        return InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'name' => $name, 'quantity' => '10', 'zero_price_cents' => 1000, 'pickup_available' => true, 'shipping_available' => true, 'shipping_cost_cents' => 0, 'exchange_available' => true, 'vat_treatment' => 'not_applicable']);
    }

    private function order(User $u, array $products, string $mode = 'pickup', bool $paid = false): Order
    {
        foreach ($products as $p) {
            app(MarketplaceCart::class)->save($u, $p, '1', $mode);
        }
        $address = $mode === 'shipping' ? app(SaveCustomerAddress::class)->handle($u, $this->addressData()) : null;
        $quotes = app(MarketplaceQuote::class);
        $o = app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], $address?->id, $quotes->hash($quotes->build($u, [], $address)));
        if ($paid) {
            app(StartMarketplacePayment::class)->handle($o);
            app(ApplyMarketplacePaymentResult::class)->handle($o, 'paid:'.$o->id, $o->payment->reference, 'paid', $o->total_cents, 'EUR');
        }

        return $o->fresh();
    }

    private function addressData(): array
    {
        return ['label' => 'Casa', 'recipient_name' => 'Ada Rossi', 'address_line_1' => 'Via Riservata 17', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country_code' => 'IT'];
    }

    private function buyer(): User
    {
        return User::factory()->create(['role' => 'customer']);
    }

    private function recipients(Kind $kind): array
    {
        return MarketplaceNoticeOutbox::where('kind', $kind->value)->orderBy('user_id')->pluck('user_id')->unique()->all();
    }

    public function test_customer_orders_payments_deliveries_and_details_are_scoped_to_owner(): void
    {
        $r = $this->retailer();
        $u = $this->buyer();
        $other = $this->buyer();
        $ours = $this->order($u, [$this->product($r, 'Solo mio')]);
        $theirs = $this->order($other, [$this->product($r, 'Segreto altro cliente')]);
        $this->actingAs($u)->get(route('orders.index'))->assertOk()->assertSee('Ordine #'.$ours->id)->assertDontSee('Ordine #'.$theirs->id);
        $this->get(route('orders.show', $theirs))->assertForbidden();
        $this->get(route('customer.deliveries.show', $theirs->vendorOrders()->sole()))->assertForbidden();
        $this->get(route('customer.payments'))->assertOk()->assertSee('Ordine #'.$ours->id)->assertDontSee('Ordine #'.$theirs->id);
        $this->get(route('customer.deliveries.index'))->assertOk()->assertSee('Consegna #'.$ours->vendorOrders()->sole()->id)->assertDontSee('Consegna #'.$theirs->vendorOrders()->sole()->id);
    }

    public function test_retailer_received_order_detail_never_contains_other_vendors_items_or_totals(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $u = $this->buyer();
        $o = $this->order($u, [$this->product($a, 'Solo venditore A'), $this->product($b, 'Segreto venditore B')]);
        $va = $o->vendorOrders()->where('retailer_id', $a->id)->sole();
        $vb = $o->vendorOrders()->where('retailer_id', $b->id)->sole();
        $this->actingAs($a->user)->get(route('retailer.sales.index'))->assertOk()->assertSee(route('retailer.sales.show', $va))->assertDontSee(route('retailer.sales.show', $vb))->assertDontSee('Segreto venditore B');
        $this->get(route('retailer.sales.show', $va))->assertOk()->assertSee('Solo venditore A')->assertDontSee('Segreto venditore B');
        $this->get(route('retailer.sales.show', $vb))->assertForbidden();
        $this->patch(route('retailer.sales.update', $vb), ['status' => 'preparing'])->assertForbidden();
    }

    public function test_retailer_buyer_keeps_role_and_does_not_gain_seller_view_of_purchased_stock(): void
    {
        $buyer = $this->retailer('pro');
        $seller = $this->retailer();
        $o = $this->order($buyer->user, [$this->product($seller)]);
        $v = $o->vendorOrders()->sole();
        $this->actingAs($buyer->user)->get(route('orders.show', $o))->assertOk();
        $this->get(route('customer.deliveries.show', $v))->assertOk();
        $this->get(route('retailer.sales.show', $v))->assertForbidden();
        $this->assertSame('retailer', $buyer->user->fresh()->role->value);
    }

    public function test_order_received_and_payment_confirmed_recipients_and_replay_are_correct(): void
    {
        Queue::fake();
        $a = $this->retailer();
        $b = $this->retailer();
        $u = $this->buyer();
        $o = $this->order($u, [$this->product($a), $this->product($b)], 'pickup', true);
        $expected = [$a->user_id, $b->user_id];
        sort($expected);
        $this->assertSame($expected, $this->recipients(Kind::OrderReceived));
        $expected[] = $u->id;
        sort($expected);
        $this->assertSame($expected, $this->recipients(Kind::PaymentConfirmed));
        $before = MarketplaceNoticeOutbox::count();
        app(ApplyMarketplacePaymentResult::class)->handle($o, 'paid:'.$o->id, $o->payment->reference, 'paid', $o->total_cents, 'EUR');
        $this->assertSame($before, MarketplaceNoticeOutbox::count());
        Queue::assertPushed(DeliverMarketplaceNotice::class, fn ($job) => $job->connection === 'database' && $job->queue === 'marketplace-notifications' && $job->afterCommit === true);
    }

    public function test_database_notification_is_immediate_and_mail_is_queued_idempotently_without_bank_details(): void
    {
        Mail::fake();
        Queue::fake();
        $r = $this->retailer();
        $r->update(['iban' => 'IT60X0542811101000000123456']);
        $o = $this->order($this->buyer(), [$this->product($r)]);
        $n = MarketplaceNoticeOutbox::where('user_id', $r->user_id)->sole();
        $this->assertDatabaseHas('notifications', ['id' => $n->notification_uuid, 'notifiable_id' => $r->user_id]);
        Mail::assertNothingSent();
        (new DeliverMarketplaceNotice($n->id))->handle();
        (new DeliverMarketplaceNotice($n->id))->handle();
        Mail::assertSent(MarketplaceNoticeMail::class, 1);
        Mail::assertSent(MarketplaceNoticeMail::class, function ($mail) use ($r) {
            $this->assertStringNotContainsString($r->iban, $mail->render());

            return $mail->hasTo($r->user->email);
        });
        $this->assertNotNull($n->fresh()->completed_at);
        $this->assertSame(1, $r->user->notifications()->count());
    }

    public function test_transaction_rollback_removes_notices_and_database_inbox_records(): void
    {
        $u = $this->buyer();
        try {
            DB::transaction(function () use ($u) {
                app(MarketplaceNotices::class)->publish($u->id, 'rollback', Kind::Cancelled, '/notifiche', 1);
                throw new \RuntimeException('Roll back domain');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('marketplace_notice_outbox', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_email_opt_out_after_enqueue_is_respected_and_does_not_remove_internal_notice(): void
    {
        Mail::fake();
        Queue::fake();
        $u = $this->buyer();
        $n = app(MarketplaceNotices::class)->publish($u->id, 'opt-out', Kind::Cancelled, '/notifiche', 1);
        $u->update(['marketplace_email_notifications' => false]);
        (new DeliverMarketplaceNotice($n->id))->handle();
        Mail::assertNothingSent();
        $this->assertNotNull($n->fresh()->completed_at);
        $this->assertSame(1, $u->notifications()->count());
    }

    public function test_notification_inbox_read_and_preferences_are_owned(): void
    {
        $u = $this->buyer();
        $other = $this->buyer();
        $n = app(MarketplaceNotices::class)->publish($u->id, 'owned', Kind::Cancelled, '/notifiche', 1);
        $this->actingAs($other)->get(route('notifications.index'))->assertOk()->assertDontSee('Aggiornamento relativo alla pratica #1.');
        $this->patch(route('notifications.read', $n->notification_uuid))->assertNotFound();
        $this->actingAs($u)->patch(route('notifications.read', $n->notification_uuid))->assertRedirect();
        $this->assertNotNull($u->notifications()->sole()->read_at);
        $this->post(route('notifications.preferences'), ['email_notifications' => 0, 'user_id' => $other->id])->assertRedirect();
        $this->assertFalse($u->fresh()->marketplace_email_notifications);
        $this->assertTrue($other->fresh()->marketplace_email_notifications);
    }

    public function test_fulfilment_requires_paid_order_and_correct_delivery_mode_and_emits_scoped_notifications(): void
    {
        $r = $this->retailer();
        $u = $this->buyer();
        $o = $this->order($u, [$this->product($r)], 'shipping');
        $v = $o->vendorOrders()->sole();
        $this->actingAs($r->user)->patch(route('retailer.sales.update', $v), ['status' => 'preparing'])->assertSessionHasErrors('status');
        app(StartMarketplacePayment::class)->handle($o);
        app(ApplyMarketplacePaymentResult::class)->handle($o, 'paid', $o->payment->reference, 'paid', $o->total_cents, 'EUR');
        $this->patch(route('retailer.sales.update', $v), ['status' => 'preparing'])->assertSessionHasNoErrors();
        $this->patch(route('retailer.sales.update', $v), ['status' => 'ready_for_pickup'])->assertSessionHasErrors('status');
        $this->patch(route('retailer.sales.update', $v), ['status' => 'shipped', 'carrier' => 'Corriere', 'tracking_reference' => 'TRACK-123'])->assertSessionHasNoErrors();
        $expected = [$r->user_id, $u->id];
        sort($expected);
        $this->assertSame($expected, $this->recipients(Kind::OrderShipped));
        $this->actingAs($u)->get(route('customer.deliveries.show', $v))->assertSee('TRACK-123');
        $this->post(route('customer.deliveries.received', $v))->assertSessionHasNoErrors();
        $this->post(route('customer.deliveries.received', $v))->assertSessionHasNoErrors();
        $this->assertSame('completed', $o->fresh()->status->value);
        $this->assertSame($expected, $this->recipients(Kind::DeliveryCompleted));
    }

    public function test_pickup_ready_notification_and_mixed_vendor_parent_complete_only_when_all_done(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $u = $this->buyer();
        $o = $this->order($u, [$this->product($a), $this->product($b)], 'pickup', true);
        $flow = app(VendorOrderWorkflow::class);
        foreach ($o->vendorOrders as $v) {
            $seller = Retailer::findOrFail($v->retailer_id)->user;
            $flow->advance($seller, $v, 'preparing');
            $flow->advance($seller, $v, 'ready_for_pickup');
        }
        $this->assertSame('ready_for_pickup', $o->fresh()->status->value);
        $rows = $o->vendorOrders;
        $flow->receive($u, $rows[0]);
        $this->assertNotSame('completed', $o->fresh()->status->value);
        $flow->receive($u, $rows[1]);
        $this->assertSame('completed', $o->fresh()->status->value);
        $this->assertSame(4, MarketplaceNoticeOutbox::where('kind', Kind::ReadyForPickup->value)->count());
    }

    public function test_admin_refund_is_snapshot_full_amount_idempotent_and_restores_stock_without_notifying_other_vendor(): void
    {
        $a = $this->retailer();
        $b = $this->retailer();
        $u = $this->buyer();
        $p = $this->product($a);
        $o = $this->order($u, [$p, $this->product($b)], 'pickup', true);
        $v = $o->vendorOrders()->where('retailer_id', $a->id)->sole();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.marketplace.refunds.store', $v), ['reason' => 'Rimborso concordato', 'amount_cents' => 1])->assertSessionHasErrors('amount_cents');
        $this->post(route('admin.marketplace.refunds.store', $v), ['reason' => 'Rimborso concordato'])->assertSessionHasNoErrors();
        $this->post(route('admin.marketplace.refunds.store', $v), ['reason' => 'Ripetizione'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('marketplace_refunds', 1);
        $this->assertSame($v->total_cents, MarketplaceRefund::sole()->amount_cents);
        $this->assertSame(10000, $p->fresh()->quantity_milliunits);
        $this->assertSame('paid', $o->fresh()->payment->status);
        $this->assertSame('paid', $o->fresh()->status->value);
        $expected = [$a->user_id, $u->id];
        sort($expected);
        $this->assertSame($expected, $this->recipients(Kind::Refunded));
        $this->actingAs($a->user)->post(route('admin.marketplace.refunds.store', $v), ['reason' => 'No'])->assertForbidden();
    }

    public function test_customer_address_crud_defaults_and_profile_cannot_change_role_or_foreign_address(): void
    {
        $u = $this->buyer();
        $other = $this->buyer();
        $foreign = app(SaveCustomerAddress::class)->handle($other, $this->addressData());
        $this->actingAs($u)->post(route('customer.addresses.store'), $this->addressData() + ['is_default_shipping' => 1, 'user_id' => $other->id])->assertSessionHasNoErrors();
        $own = $u->customerAddresses()->sole();
        $this->assertSame($u->id, $own->user_id);
        $this->get(route('customer.addresses.edit', $foreign))->assertForbidden();
        $this->put(route('customer.addresses.update', $foreign), $this->addressData())->assertForbidden();
        $this->delete(route('customer.addresses.destroy', $foreign))->assertForbidden();
        $this->put(route('customer.profile.update'), ['first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => $u->email, 'role' => 'admin'])->assertSessionHasErrors('role');
        $this->put(route('customer.profile.update'), ['first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => $u->email, 'phone' => '123'])->assertSessionHasNoErrors();
        $this->assertSame('customer', $u->fresh()->role->value);
        $this->delete(route('customer.addresses.destroy', $own))->assertRedirect();
        $this->assertDatabaseCount('customer_addresses', 1);
    }

    public function test_admin_records_filters_pagination_and_detail_are_forbidden_to_non_admin(): void
    {
        $r = $this->retailer();
        $u = $this->buyer();
        $o = $this->order($u, [$this->product($r)], 'pickup', true);
        $v = $o->vendorOrders()->sole();
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (array_keys(AdminMarketplaceRecords::TITLES) as $resource) {
            $this->actingAs($admin)->get(route('admin.marketplace.index', ['resource' => $resource, 'retailer_id' => $r->id]))->assertOk();
            $this->actingAs($u)->get(route('admin.marketplace.index', ['resource' => $resource]))->assertForbidden();
        }
        $this->actingAs($admin)->get(route('admin.marketplace.orders.show', $o))->assertOk();
        $this->get(route('admin.marketplace.vendors.show', $v))->assertOk();
        $this->get(route('admin.marketplace.reservations.show', $o->stockReservations()->sole()))->assertOk();
        $this->get(route('admin.marketplace.index', ['resource' => 'orders', 'id' => 99999]))->assertSee('Nessun risultato.');
    }

    public function test_exchange_notices_use_participants_current_version_payer_and_payee(): void
    {
        $a = $this->retailer('pro');
        $b = $this->retailer('pro');
        $pa = $this->product($a);
        $pb = $this->product($b);
        $input = ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '1', 'value' => '10']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '1', 'value' => '20']]], 'delivery' => ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']]];
        $flow = app(ExchangeWorkflow::class);
        $e = $flow->create($a->user, $pb, $input);
        $this->assertSame([$b->user_id], $this->recipients(Kind::ExchangeProposed));
        $v = $e->currentVersion;
        $counter = $input;
        $counter['key'] = (string) Str::uuid();
        $counter += ['base_version_id' => $v->id, 'base_summary_hash' => $v->summary_hash];
        $e = $flow->counter($b->user, $e, $counter);
        $this->assertSame([$a->user_id], $this->recipients(Kind::ExchangeCountered));
        $v = $e->currentVersion;
        $flow->accept($a->user, $e, $v->id, $v->summary_hash);
        $flow->accept($b->user, $e, $v->id, $v->summary_hash);
        $expected = [$a->user_id, $b->user_id];
        sort($expected);
        $this->assertSame($expected, $this->recipients(Kind::ExchangeAccepted));
        $this->assertSame([$a->user_id], $this->recipients(Kind::DifferenceDue));
        app(SimulateExchangeDifferencePayment::class)->handle($a->user, $e, $v->id, $v->summary_hash);
        $this->assertSame([$b->user_id], $this->recipients(Kind::DifferenceReceived));
        $this->assertSame([$a->user_id], $this->recipients(Kind::PaymentConfirmed));
        $this->actingAs($a->user)->get(route('retailer.differences.index'))->assertOk()->assertSee('Scambio #'.$e->id);
        $this->actingAs($this->retailer('pro')->user)->get(route('retailer.differences.index'))->assertOk()->assertSee('Nessun conguaglio.');
    }

    public function test_cancel_and_exchange_expire_reject_notices_are_persisted_once(): void
    {
        $r = $this->retailer();
        $u = $this->buyer();
        $o = $this->order($u, [$this->product($r)]);
        app(StockReservations::class)->release($o);
        app(StockReservations::class)->release($o);
        $this->assertSame(2, MarketplaceNoticeOutbox::where('kind', Kind::Cancelled->value)->count());
        $a = $this->retailer('pro');
        $b = $this->retailer('pro');
        $pa = $this->product($a);
        $pb = $this->product($b);
        $input = ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '1', 'value' => '10']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '1', 'value' => '20']]], 'delivery' => ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']]];
        $flow = app(ExchangeWorkflow::class);
        $e = $flow->create($a->user, $pb, $input);
        $v = $e->currentVersion;
        $flow->stop($b->user, $e, $v->id, $v->summary_hash, 'rejected');
        $this->assertSame(2, MarketplaceNoticeOutbox::where('kind', Kind::ExchangeRejected->value)->count());
        $input['key'] = (string) Str::uuid();
        $e = $flow->create($a->user, $pb, $input);
        $this->travel(49)->hours();
        $flow->expire();
        $flow->expire();
        $this->assertSame(2, MarketplaceNoticeOutbox::where('kind', Kind::ExchangeExpired->value)->count());
    }
}
