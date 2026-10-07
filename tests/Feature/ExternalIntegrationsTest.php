<?php

namespace Tests\Feature;

use App\Contracts\StoreGatewayInterface;
use App\Contracts\StripeBillingGateway;
use App\Data\StripeSubscriptionData;
use App\Jobs\ProcessIntegrationEvent;
use App\Models\BillingCheckout;
use App\Models\BillingSubscription;
use App\Models\IntegrationEvent;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Integrations\IntegrationQueue;
use App\Services\Store\FakeStoreGateway;
use App\Services\WalletBalance;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExternalIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private Retailer $retailer;

    private BillingCheckout $checkout;

    private string $stripeSecret;

    private string $storeSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(PlanSeeder::class);
        Queue::fake();
        $this->stripeSecret = bin2hex(random_bytes(32));
        $this->storeSecret = bin2hex(random_bytes(32));
        config(['stripe.secret' => 'unused-test-configuration', 'stripe.webhook_secret' => $this->stripeSecret, 'stripe.prices.monthly' => 'price_monthly', 'stripe.prices.yearly' => 'price_yearly', 'store.drivers.fake.webhook_secret' => $this->storeSecret]);
        $this->retailer = Retailer::factory()->create();
        Subscription::factory()->active()->create(['retailer_id' => $this->retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);
        $this->checkout = BillingCheckout::create(['id' => (string) Str::uuid(), 'retailer_id' => $this->retailer->id, 'billing_interval' => 'monthly', 'price_id' => 'price_monthly', 'price_cents' => 6900, 'status' => 'open', 'checkout_session_id' => 'cs_test', 'checkout_url' => 'https://checkout.stripe.com/c/pay/test', 'expires_at' => now()->addHour()]);
    }

    private function stripeEvent(string $type = 'customer.subscription.updated', string $id = 'evt_test', array $object = [], ?int $created = null): array
    {
        $base = match ($type) {
            'checkout.session.completed' => ['id' => 'cs_test', 'object' => 'checkout.session', 'mode' => 'subscription', 'subscription' => 'sub_test', 'client_reference_id' => $this->checkout->id, 'payment_status' => 'paid'],'invoice.payment_failed','invoice.paid' => ['id' => 'in_test', 'object' => 'invoice', 'parent' => ['subscription_details' => ['subscription' => 'sub_test']]],default => ['id' => 'sub_test', 'object' => 'subscription']
        };

        return ['id' => $id, 'object' => 'event', 'type' => $type, 'created' => $created ?? now()->getTimestamp(), 'data' => ['object' => array_replace_recursive($base, $object)]];
    }

    private function postStripe(array $payload, ?string $secret = null, ?int $signedAt = null)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = $signedAt ?? time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->stripeSecret);

        return $this->call('POST', route('webhooks.stripe'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body);
    }

    private function remote(string $status = 'active', bool $paid = true, string $interval = 'monthly', array $changes = []): StripeSubscriptionData
    {
        return StripeSubscriptionData::fromArray(array_replace_recursive(['id' => 'sub_test', 'customer' => 'cus_test', 'metadata' => ['zero_checkout_id' => $this->checkout->id, 'zero_retailer_id' => (string) $this->retailer->id], 'status' => $status, 'cancel_at_period_end' => false, 'latest_invoice' => ['status' => $paid ? 'paid' : 'open'], 'items' => ['data' => [['quantity' => 1, 'current_period_end' => now()->addMonth()->getTimestamp(), 'price' => ['id' => $interval === 'monthly' ? 'price_monthly' : 'price_yearly', 'unit_amount' => $interval === 'monthly' ? 6900 : 49900, 'currency' => 'eur', 'recurring' => ['interval' => $interval === 'monthly' ? 'month' : 'year', 'interval_count' => 1]]]]]], $changes));
    }

    private function gateway(StripeSubscriptionData $remote): void
    {
        $this->mock(StripeBillingGateway::class)->shouldReceive('subscription')->with($remote->id)->andReturn($remote);
    }

    private function process(string $id = 'evt_test'): void
    {
        (new ProcessIntegrationEvent(IntegrationEvent::where('provider', 'stripe')->where('external_event_id', $id)->sole()->id))->handle();
    }

    private function storePayload(string $type = 'order.paid', string $id = 'store_1'): array
    {
        $product = InventoryItem::factory()->create(['retailer_id' => $this->retailer->id]);

        return ['event_id' => $id, 'type' => $type, 'data' => ['external_order_id' => 'shop_order', 'timestamp' => now()->toIso8601String(), 'items' => [['external_line_id' => 'line_1', 'inventory_item_id' => $product->id, 'retailer_id' => $this->retailer->id, 'quantity' => '2.000', 'unit_price_cents' => 10000, 'discount_cents' => 1000]]]];
    }

    private function postStore(array $payload, ?string $secret = null, ?int $timestamp = null, string $driver = 'fake')
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = $timestamp ?? time();
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->storeSecret);

        return $this->call('POST', route('webhooks.store', $driver), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ZERO_TIMESTAMP' => (string) $timestamp, 'HTTP_X_ZERO_SIGNATURE' => $signature], $body);
    }

    public function test_stripe_signature_and_timestamp_are_verified_before_persistence(): void
    {
        $this->postStripe($this->stripeEvent(), 'wrong')->assertStatus(400);
        $this->postStripe($this->stripeEvent(), null, time() - 600)->assertStatus(400);
        $this->assertDatabaseCount('integration_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_stripe_intake_is_fast_durable_queued_and_idempotent(): void
    {
        $payload = $this->stripeEvent();
        $this->postStripe($payload)->assertAccepted();
        $this->postStripe($payload)->assertAccepted();
        $this->assertDatabaseCount('integration_events', 1);
        $event = IntegrationEvent::sole();
        $this->assertSame('pending', $event->status->value);
        $this->assertSame(0, $event->attempts);
        Queue::assertPushed(ProcessIntegrationEvent::class, fn ($job) => $job->eventId === $event->id && $job->connection === 'database' && $job->queue === 'integrations');
        $payload['data']['object']['status'] = 'changed';
        $this->postStripe($payload)->assertConflict();
        $this->assertStringNotContainsString('sub_test', DB::table('integration_events')->value('payload'));
    }

    public function test_checkout_completed_activates_pro_but_never_approves_pending_retailer(): void
    {
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent('checkout.session.completed'))->assertAccepted();
        $this->process();
        $this->assertSame('pro', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
        $this->assertSame('pending', $this->retailer->fresh()->status->value);
        $this->assertSame('active', BillingSubscription::sole()->status);
        $this->assertTrue(BillingSubscription::sole()->invoice_paid);
        $count = Subscription::count();
        $this->process();
        $this->assertSame($count, Subscription::count());
        $this->actingAs($this->retailer->user)->get(route('retailer.sell'))->assertForbidden();
    }

    public function test_unpaid_checkout_never_grants_pro(): void
    {
        $this->gateway($this->remote('incomplete', false));
        $this->postStripe($this->stripeEvent('checkout.session.completed', object: ['payment_status' => 'unpaid']))->assertAccepted();
        $this->process();
        $this->assertSame('free', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
    }

    public function test_updated_subscription_changes_interval_without_losing_history(): void
    {
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent())->assertAccepted();
        $this->process();
        $original = $this->retailer->activeSubscription()->sole();
        $this->travel(1)->days();
        $this->gateway($this->remote(interval: 'yearly'));
        $this->postStripe($this->stripeEvent(id: 'evt_yearly'))->assertAccepted();
        $this->process('evt_yearly');
        $current = $this->retailer->activeSubscription()->sole();
        $this->assertSame('yearly', $current->billing_interval->value);
        $this->assertSame(49900, $current->price_cents);
        $this->assertSame('cancelled', $original->fresh()->status->value);
        $this->assertNotNull($original->fresh()->ends_at);
    }

    public function test_cancel_at_period_end_preserves_paid_pro_then_deleted_restores_free(): void
    {
        $this->gateway($this->remote(changes: ['cancel_at_period_end' => true]));
        $this->postStripe($this->stripeEvent())->assertAccepted();
        $this->process();
        $this->assertSame('pro', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
        $this->travel(1)->days();
        $this->gateway($this->remote('canceled', changes: ['ended_at' => now()->getTimestamp()]));
        $this->postStripe($this->stripeEvent('customer.subscription.deleted', 'evt_cancel'))->assertAccepted();
        $this->process('evt_cancel');
        $this->assertSame('free', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
        $this->assertSame('canceled', BillingSubscription::sole()->status);
        $this->assertSame('pending', $this->retailer->fresh()->status->value);
    }

    public function test_payment_failure_suspends_pro_and_paid_invoice_restores_it(): void
    {
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent())->assertAccepted();
        $this->process();
        $this->travel(1)->hours();
        $this->gateway($this->remote('past_due', false));
        $this->postStripe($this->stripeEvent('invoice.payment_failed', 'evt_failed'))->assertAccepted();
        $this->process('evt_failed');
        $this->assertSame('free', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
        $this->travel(1)->hours();
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent('invoice.paid', 'evt_paid'))->assertAccepted();
        $this->process('evt_paid');
        $this->assertSame('pro', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
        $this->assertSame(1, BillingSubscription::count());
    }

    public function test_old_event_cannot_regress_latest_subscription_state(): void
    {
        $old = now()->subHour()->getTimestamp();
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent())->assertAccepted();
        $this->process();
        $this->gateway($this->remote('canceled'));
        $this->postStripe($this->stripeEvent('customer.subscription.deleted', 'evt_old', created: $old))->assertAccepted();
        $this->process('evt_old');
        $this->assertSame('pro', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
        $this->assertSame('active', BillingSubscription::sole()->status);
    }

    public function test_processing_error_is_redacted_durable_and_retriable(): void
    {
        $this->mock(StripeBillingGateway::class)->shouldReceive('subscription')->andThrow(new \RuntimeException('Secret payload that must never appear'));
        $this->postStripe($this->stripeEvent())->assertAccepted();
        try {
            $this->process();
            $this->fail('Expected redacted exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('Secret', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $event = IntegrationEvent::sole();
        $this->assertSame('failed', $event->status->value);
        $this->assertSame('integration_processing_failed', $event->error_code);
        $this->assertNotNull($event->next_attempt_at);
        $this->gateway($this->remote());
        $this->process();
        $this->assertSame('processed', $event->fresh()->status->value);
        $this->assertSame(2, $event->fresh()->attempts);
    }

    public function test_mismatched_checkout_owner_or_price_is_not_applied(): void
    {
        foreach ([['metadata' => ['zero_retailer_id' => (string) Retailer::factory()->create()->id]], ['items' => ['data' => [['price' => ['unit_amount' => 1]]]]]] as $i => $changes) {
            $this->gateway($this->remote(changes: $changes));
            $id = 'evt_bad_'.$i;
            $this->postStripe($this->stripeEvent(id: $id))->assertAccepted();
            try {
                $this->process($id);
                $this->fail('Expected invalid mapping.');
            } catch (\RuntimeException) {
                $this->assertSame('free', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
            }
        }
        $this->assertDatabaseCount('billing_subscriptions', 0);
    }

    public function test_unknown_stripe_event_is_safely_acknowledged_without_network(): void
    {
        $this->mock(StripeBillingGateway::class)->shouldNotReceive('subscription');
        $this->postStripe($this->stripeEvent('customer.created'))->assertAccepted();
        $this->process();
        $this->assertSame('processed', IntegrationEvent::sole()->status->value);
    }

    public function test_missing_configuration_is_disabled_and_browser_return_does_not_activate_pro(): void
    {
        config(['stripe.secret' => null, 'stripe.webhook_secret' => null]);
        $this->postStripe($this->stripeEvent())->assertStatus(503);
        $this->actingAs($this->retailer->user)->get(route('retailer.billing'))->assertOk()->assertSee('Stripe non è ancora configurato');
        $this->post(route('retailer.billing.checkout'), ['billing_interval' => 'monthly'])->assertSessionHasErrors('checkout');
        $this->get(route('retailer.billing.return', ['result' => 'success']))->assertRedirect(route('retailer.billing'));
        $this->assertSame('free', $this->retailer->activeSubscription()->with('plan')->sole()->plan->code);
    }

    public function test_checkout_is_owned_recurring_and_http_fields_cannot_select_owner_or_price(): void
    {
        $this->checkout->update(['status' => 'expired']);
        $this->mock(StripeBillingGateway::class)->shouldReceive('createCheckout')->once()->withArgs(fn ($checkout) => $checkout->retailer_id === $this->retailer->id && $checkout->billing_interval === 'yearly' && $checkout->price_cents === 49900)->andReturn(['id' => 'cs_yearly', 'url' => 'https://checkout.stripe.com/c/pay/yearly']);
        $this->actingAs($this->retailer->user)->post(route('retailer.billing.checkout'), ['billing_interval' => 'yearly'])->assertRedirect('https://checkout.stripe.com/c/pay/yearly');
        $this->post(route('retailer.billing.checkout'), ['billing_interval' => 'yearly'])->assertRedirect('https://checkout.stripe.com/c/pay/yearly');
        $this->post(route('retailer.billing.checkout'), ['billing_interval' => 'monthly', 'retailer_id' => 999, 'price_cents' => 1])->assertSessionHasErrors(['retailer_id', 'price_cents']);
        $this->assertSame('pending', $this->retailer->fresh()->status->value);
        $this->assertSame(49900, BillingCheckout::where('status', 'open')->sole()->price_cents);
    }

    public function test_existing_stripe_subscription_prevents_double_checkout_and_admin_cannot_purchase(): void
    {
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent())->assertAccepted();
        $this->process();
        $this->actingAs($this->retailer->user)->post(route('retailer.billing.checkout'), ['billing_interval' => 'monthly'])->assertSessionHasErrors('checkout');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('retailer.billing.checkout'), ['billing_interval' => 'monthly'])->assertForbidden();
    }

    public function test_store_signature_replay_window_unknown_provider_and_floats_are_rejected(): void
    {
        $payload = $this->storePayload();
        $this->postStore($payload, 'wrong')->assertUnauthorized();
        $this->postStore($payload, null, time() - 600)->assertUnauthorized();
        $this->postStore($payload, driver: 'not_configured')->assertNotFound();
        $payload['data']['items'][0]['unit_price_cents'] = 1.5;
        $this->postStore($payload)->assertUnprocessable();
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_store_order_and_refund_are_normalized_queued_and_idempotent(): void
    {
        $payload = $this->storePayload();
        $this->postStore($payload)->assertAccepted();
        $event = IntegrationEvent::where('event_type', 'store.order.paid')->sole();
        $this->assertDatabaseCount('sales', 0);
        (new ProcessIntegrationEvent($event->id))->handle();
        (new ProcessIntegrationEvent($event->id))->handle();
        $this->postStore($payload)->assertAccepted();
        $this->assertSame(18620, app(WalletBalance::class)->availableCents($this->retailer));
        $refund = ['event_id' => 'store_refund', 'type' => 'refund', 'data' => ['external_order_id' => 'shop_order', 'external_refund_id' => 'refund_1', 'timestamp' => now()->toIso8601String(), 'items' => [['external_line_id' => 'line_1', 'amount_cents' => 19000]]]];
        $this->postStore($refund)->assertAccepted();
        $event = IntegrationEvent::where('event_type', 'store.refund')->sole();
        (new ProcessIntegrationEvent($event->id))->handle();
        (new ProcessIntegrationEvent($event->id))->handle();
        $this->assertSame(0, app(WalletBalance::class)->availableCents($this->retailer));
        $this->assertSame(4, WalletTransaction::count());
    }

    public function test_store_cannot_spoof_billing_namespace_or_product_ownership(): void
    {
        $payload = $this->storePayload();
        $payload['data']['provider'] = 'stripe';
        $payload['data']['items'][0]['retailer_id'] = Retailer::factory()->create()->id;
        $this->postStore($payload)->assertAccepted();
        $event = IntegrationEvent::sole();
        $this->assertSame('fake', $event->provider);
        try {
            (new ProcessIntegrationEvent($event->id))->handle();
            $this->fail('Expected ownership failure.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('sales', 0);
            $this->assertSame('failed', $event->fresh()->status->value);
        }
    }

    public function test_inbox_recovers_lost_dispatch_but_skips_completed_and_exhausted_events(): void
    {
        $payload = $this->storePayload();
        $this->postStore($payload)->assertAccepted();
        $event = IntegrationEvent::sole();
        Queue::fake();
        $this->artisan('integrations:retry')->assertSuccessful();
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);
        $event->update(['status' => 'failed', 'attempts' => 5]);
        Queue::fake();
        $this->artisan('integrations:retry')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->artisan('integrations:retry', ['--event' => $event->id, '--force' => true])->assertSuccessful();
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);
        $this->assertSame(0, $event->fresh()->attempts);
    }

    public function test_fake_store_gateway_archives_idempotently_and_unknown_driver_fails_closed(): void
    {
        $gateway = app(StoreGatewayInterface::class);
        $this->assertInstanceOf(FakeStoreGateway::class, $gateway);
        $product = $gateway->createProduct('contract', ['name' => 'Test'], 1);
        $gateway->publishProduct($product['external_product_id'], 1);
        $gateway->archiveProduct($product['external_product_id'], 2);
        $this->assertFalse($gateway->archiveProduct($product['external_product_id'], 2)['published']);
        $this->assertFalse($gateway->publishProduct($product['external_product_id'], 1)['published']);
        config(['store.driver' => 'unknown']);
        $this->expectException(\RuntimeException::class);
        app(StoreGatewayInterface::class);
    }

    public function test_late_cancellation_of_old_subscription_cannot_disable_new_paid_subscription(): void
    {
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent(id: 'evt_first'))->assertAccepted();
        $this->process('evt_first');
        $this->travel(1)->days();
        $old = $this->remote('canceled', changes: ['ended_at' => now()->getTimestamp()]);
        $this->gateway($old);
        $this->postStripe($this->stripeEvent('customer.subscription.deleted', 'evt_cancel'))->assertAccepted();
        $this->process('evt_cancel');
        $this->travel(1)->days();
        $this->checkout = BillingCheckout::create(['id' => (string) Str::uuid(), 'retailer_id' => $this->retailer->id, 'billing_interval' => 'monthly', 'price_id' => 'price_monthly', 'price_cents' => 6900, 'status' => 'open', 'checkout_session_id' => 'cs_new', 'expires_at' => now()->addHour()]);
        $new = $this->remote(changes: ['id' => 'sub_new']);
        $this->gateway($new);
        $this->postStripe($this->stripeEvent(id: 'evt_new', object: ['id' => 'sub_new']))->assertAccepted();
        $this->process('evt_new');
        $this->travel(1)->hours();
        $this->gateway($old);
        $this->postStripe($this->stripeEvent('customer.subscription.deleted', 'evt_late'))->assertAccepted();
        $this->process('evt_late');
        $current = $this->retailer->activeSubscription()->with('plan', 'billingSubscription')->sole();
        $this->assertSame('pro', $current->plan->code);
        $this->assertSame('sub_new', $current->billingSubscription->external_subscription_id);
    }

    public function test_checkout_network_retry_reuses_same_server_intent(): void
    {
        $this->checkout->update(['status' => 'expired']);
        $seen = [];
        $this->mock(StripeBillingGateway::class)->shouldReceive('createCheckout')->twice()->andReturnUsing(function ($checkout) use (&$seen) {
            $seen[] = $checkout->id;
            if (count($seen) === 1) {
                throw new \RuntimeException('Network failure');
            }

            return ['id' => 'cs_retry', 'url' => 'https://checkout.stripe.com/c/pay/retry'];
        });
        $this->actingAs($this->retailer->user)->post(route('retailer.billing.checkout'), ['billing_interval' => 'monthly'])->assertSessionHasErrors('checkout');
        $this->post(route('retailer.billing.checkout'), ['billing_interval' => 'monthly'])->assertRedirect('https://checkout.stripe.com/c/pay/retry');
        $this->assertCount(2, $seen);
        $this->assertSame($seen[0], $seen[1]);
    }

    public function test_queue_outage_keeps_a_recoverable_inbox_receipt(): void
    {
        $this->mock(IntegrationQueue::class)->shouldReceive('dispatch')->andThrow(new \RuntimeException('Unavailable queue'));
        $this->postStripe($this->stripeEvent())->assertAccepted();
        $event = IntegrationEvent::sole();
        $this->assertSame('pending', $event->status->value);
        $this->assertSame('integration_dispatch_failed', $event->error_code);
    }

    public function test_payload_limits_and_invalid_signed_schema_do_not_enqueue_jobs(): void
    {
        config(['integrations.max_body_bytes' => 100]);
        $this->postStripe($this->stripeEvent())->assertStatus(413);
        $this->assertDatabaseCount('integration_events', 0);
        config(['integrations.max_body_bytes' => 1048576]);
        $payload = $this->stripeEvent();
        unset($payload['data']);
        $this->postStripe($payload)->assertUnprocessable();
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_archived_fake_product_cannot_be_republished_by_same_old_revision(): void
    {
        $gateway = new FakeStoreGateway;
        $product = $gateway->createProduct('same-revision', ['name' => 'Test'], 1);
        $gateway->publishProduct($product['external_product_id'], 1);
        $gateway->archiveProduct($product['external_product_id'], 1);
        $this->assertFalse($gateway->publishProduct($product['external_product_id'], 1)['published']);
        $gateway->updateProduct($product['external_product_id'], ['name' => 'New approved revision'], 2);
        $this->assertTrue($gateway->publishProduct($product['external_product_id'], 2)['published']);
    }

    public function test_billing_reconciliation_is_queued_without_a_network_call_in_the_command(): void
    {
        $this->gateway($this->remote());
        $this->postStripe($this->stripeEvent())->assertAccepted();
        $this->process();
        Queue::fake();
        $this->artisan('billing:sync')->assertSuccessful();
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);
        $this->artisan('billing:sync')->assertSuccessful();
        Queue::assertPushed(ProcessIntegrationEvent::class, 1);
    }
}
