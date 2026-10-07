<?php

namespace Tests\Feature;

use App\Contracts\StoreGatewayInterface;
use App\Enums\UserRole;
use App\Jobs\ProcessStorePublication;
use App\Models\AuditLog;
use App\Models\FakeStoreProduct;
use App\Models\InventoryItem;
use App\Models\PayoutRequest;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\StorePublication;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Store\FakeStoreGateway;
use App\Services\WalletBalance;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function approved(): Retailer
    {
        $retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);

        return $retailer;
    }

    private function pendingItem(Retailer $retailer): InventoryItem
    {
        return InventoryItem::factory()->create(['retailer_id' => $retailer->id, 'status' => 'pending', 'quantity' => '1', 'zero_price_cents' => 10000]);
    }

    private function credit(Retailer $retailer, int $amount): void
    {
        WalletTransaction::create(['retailer_id' => $retailer->id, 'type' => 'adjustment', 'amount_cents' => $amount, 'currency' => 'EUR', 'available_at' => now(), 'idempotency_key' => uniqid('test-credit-')]);
    }

    private function reserve(PayoutRequest $payout): void
    {
        $payout->walletTransactions()->create(['retailer_id' => $payout->retailer_id, 'currency' => $payout->currency, 'type' => 'payout_reservation', 'amount_cents' => -$payout->amount_cents, 'available_at' => now(), 'idempotency_key' => 'test-reserve-'.$payout->id]);
    }

    public function test_guests_and_retailers_cannot_read_or_execute_any_admin_operation(): void
    {
        $retailer = Retailer::factory()->create();
        $item = $this->pendingItem($retailer);
        $payout = PayoutRequest::factory()->create(['retailer_id' => $retailer->id]);
        $reads = ['admin.dashboard', 'admin.retailers', 'admin.stock', 'admin.payouts', 'admin.settings'];
        $writes = [[route('admin.retailers.review', $retailer), ['decision' => 'approved']], [route('admin.stock.review', $item), ['decision' => 'published']], [route('admin.payouts.review', $payout), ['decision' => 'paid', 'payment_reference' => 'test']]];
        foreach ($reads as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        foreach ($writes as [$url,$data]) {
            $this->patch($url, $data)->assertRedirect(route('login'));
        }
        $this->actingAs($retailer->user);
        foreach ($reads as $route) {
            $this->get(route($route))->assertForbidden();
        }foreach ($writes as [$url,$data]) {
            $this->patch($url, $data)->assertForbidden();
        }
        $this->assertSame('pending', $retailer->fresh()->status->value);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_admin_views_have_confirmation_modals_and_mask_bank_accounts(): void
    {
        $retailer = $this->approved();
        $item = $this->pendingItem($retailer);
        $payout = PayoutRequest::factory()->create(['retailer_id' => $retailer->id, 'iban' => 'IT60X0542811101000000123456']);
        $this->actingAs($this->admin)->get(route('admin.retailers'))->assertOk()->assertSee('SOSPENDI')->assertSee('<dialog', false)->assertSee('Stato pagamento');
        $this->get(route('admin.stock'))->assertOk()->assertSee('APPROVA E PUBBLICA')->assertSee('<dialog', false);
        $this->get(route('admin.payouts'))->assertOk()->assertSee('SEGNA COME PAGATO')->assertSee('3456')->assertDontSee('IT60X0542811101000000123456')->assertSee('Riferimento disposizione bancaria');
    }

    public function test_filters_and_server_pagination_apply_to_all_sections(): void
    {
        $match = $this->approved();
        $match->forceFill(['company_name' => 'Azienda Filtrata', 'created_at' => '2026-01-15 10:00:00'])->save();
        $other = $this->approved();
        $other->update(['company_name' => 'Azienda Esclusa']);
        $this->pendingItem($match)->forceFill(['name' => 'Prodotto Filtrato', 'created_at' => '2026-01-15 10:00:00'])->save();
        $this->pendingItem($other)->update(['name' => 'Prodotto Escluso']);
        PayoutRequest::factory()->create(['retailer_id' => $match->id, 'created_at' => '2026-01-15 10:00:00']);
        PayoutRequest::factory()->create(['retailer_id' => $other->id]);
        $this->actingAs($this->admin);
        foreach (['admin.retailers' => 'approved', 'admin.stock' => 'pending', 'admin.payouts' => 'pending'] as $route => $state) {
            $this->get(route($route, ['company' => 'Filtrata', 'status' => $state, 'from' => '2026-01-01', 'to' => '2026-01-31']))->assertOk()->assertSee('Azienda Filtrata')->assertDontSee('Azienda Esclusa');
            $this->get(route($route, ['to' => '2026-01-31']))->assertOk();
        }
        Retailer::factory()->count(16)->create(['company_name' => 'Paginata']);
        $this->get(route('admin.retailers', ['company' => 'Paginata']))->assertOk()->assertViewHas('retailers', fn ($rows) => $rows->total() === 16 && $rows->count() === 15)->assertSee('company=Paginata', false);
        $this->get(route('admin.retailers', ['status' => 'bad']))->assertSessionHasErrors('status');
        $this->get(route('admin.stock', ['from' => '2026-02-01', 'to' => '2026-01-01']))->assertSessionHasErrors('to');
    }

    public function test_suspension_requires_admin_and_approved_profile_and_is_audited(): void
    {
        $retailer = $this->approved();
        $this->actingAs($this->admin)->patch(route('admin.retailers.review', $retailer), ['decision' => 'suspended', 'reason' => 'Verifica aziendale'])->assertSessionHasNoErrors();
        $this->assertSame('suspended', $retailer->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'retailer_suspended', 'actor_user_id' => $this->admin->id]);
        $this->actingAs($retailer->user)->get(route('retailer.sell'))->assertForbidden();
    }

    public function test_unapproved_retailer_cannot_have_a_product_published(): void
    {
        $retailer = Retailer::factory()->create();
        $item = $this->pendingItem($retailer);
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('store_publications', 0);
        $this->assertDatabaseCount('fake_store_products', 0);
    }

    public function test_fake_gateway_creates_publishes_and_is_idempotent(): void
    {
        $item = $this->pendingItem($this->approved());
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('fake-inventory-'.$item->id, $item->external_product_id);
        $this->assertTrue(FakeStoreProduct::sole()->published);
        $publication = StorePublication::sole();
        $this->assertSame('succeeded', $publication->status);
        $this->assertTrue($publication->result['publication']['simulated']);
        $count = AuditLog::count();
        ProcessStorePublication::dispatchSync($publication->id);
        $this->assertSame($count, AuditLog::count());
        $this->assertDatabaseCount('fake_store_products', 1);
    }

    public function test_approved_changes_update_fake_product_with_same_identifier(): void
    {
        $retailer = $this->approved();
        $item = $this->pendingItem($retailer);
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasNoErrors();
        $originalId = $item->fresh()->external_product_id;
        $this->actingAs($retailer->user)->put(route('retailer.stock.update', $item), ['name' => 'Nome aggiornato', 'category' => 'Materiali', 'quantity' => '1', 'zero_price' => '100', 'condition' => 'new', 'province' => 'Roma', 'description' => 'Nuova descrizione', 'pickup_available' => '1', 'intent' => 'pending'])->assertSessionHasNoErrors();
        $this->assertNotSame('Nome aggiornato', FakeStoreProduct::sole()->payload['name']);
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame($originalId, $item->fresh()->external_product_id);
        $this->assertSame('Nome aggiornato', FakeStoreProduct::sole()->payload['name']);
        $this->assertSame('update', StorePublication::latest('id')->first()->operation);
    }

    public function test_gateway_failure_is_recorded_and_can_retry_without_duplicate_products(): void
    {
        $this->app->instance(StoreGatewayInterface::class, new class implements StoreGatewayInterface
        {
            public function createProduct(string $productKey, array $data, int $revision): array
            {
                return (new FakeStoreGateway)->createProduct($productKey, $data, $revision);
            }

            public function updateProduct(string $externalId, array $data, int $revision): array
            {
                throw new \RuntimeException;
            }

            public function publishProduct(string $externalId, int $revision): array
            {
                throw new \RuntimeException;
            }
        });
        $item = $this->pendingItem($this->approved());
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasNoErrors();
        $publication = StorePublication::sole();
        $this->assertSame('failed', $publication->status);
        $this->assertSame('store_sync_failed', $publication->error_code);
        $this->assertNull($item->fresh()->external_product_id);
        $this->app->instance(StoreGatewayInterface::class, new FakeStoreGateway);
        $this->artisan('store:sync')->assertSuccessful();
        $this->assertSame('succeeded', $publication->fresh()->status);
        $this->assertDatabaseCount('fake_store_products', 1);
    }

    public function test_old_fake_revision_cannot_overwrite_new_product(): void
    {
        $gateway = new FakeStoreGateway;
        $product = $gateway->createProduct('test', ['name' => 'Nuovo'], 2);
        $gateway->updateProduct($product['external_product_id'], ['name' => 'Vecchio'], 1);
        $gateway->publishProduct($product['external_product_id'], 2);
        $this->assertSame('Nuovo', FakeStoreProduct::sole()->payload['name']);
    }

    public function test_paid_payout_consumes_reservation_once_and_records_audit(): void
    {
        $retailer = $this->approved();
        $payout = PayoutRequest::factory()->create(['retailer_id' => $retailer->id, 'amount_cents' => 5000, 'iban' => 'IT60X0542811101000000123456']);
        $this->credit($retailer, 10000);
        $this->reserve($payout);
        $balance = app(WalletBalance::class);
        $this->assertSame(5000, $balance->availableCents($retailer));
        $this->actingAs($this->admin)->patch(route('admin.payouts.review', $payout), ['decision' => 'paid', 'payment_reference' => 'Bank reference test'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $payout->fresh()->status->value);
        $this->assertSame(5000, $balance->availableCents($retailer));
        $this->assertSame(5000, $balance->totalCents($retailer));
        $this->assertSame(0, $balance->reservedCents($retailer));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout_paid', 'actor_user_id' => $this->admin->id]);
        $this->patch(route('admin.payouts.review', $payout), ['decision' => 'paid', 'payment_reference' => 'Duplicate'])->assertSessionHasErrors('decision');
        $this->assertSame(3, $payout->walletTransactions()->count());
    }

    public function test_rejected_payout_releases_reservation_and_requires_reason(): void
    {
        $retailer = $this->approved();
        $payout = PayoutRequest::factory()->create(['retailer_id' => $retailer->id, 'amount_cents' => 5000, 'iban' => 'IT60X0542811101000000123456']);
        $this->credit($retailer, 10000);
        $this->reserve($payout);
        $this->actingAs($this->admin)->patch(route('admin.payouts.review', $payout), ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->patch(route('admin.payouts.review', $payout), ['decision' => 'rejected', 'reason' => 'Coordinate da verificare'])->assertSessionHasNoErrors();
        $this->assertSame(10000, app(WalletBalance::class)->availableCents($retailer));
        $this->assertSame('rejected', $payout->fresh()->status->value);
    }

    public function test_paid_payout_requires_reference_and_valid_reservation(): void
    {
        $payout = PayoutRequest::factory()->create();
        $this->actingAs($this->admin)->patch(route('admin.payouts.review', $payout), ['decision' => 'paid'])->assertSessionHasErrors('payment_reference');
        $this->patch(route('admin.payouts.review', $payout), ['decision' => 'paid', 'payment_reference' => 'External transfer'])->assertSessionHasErrors('decision');
        $this->assertSame('pending', $payout->fresh()->status->value);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
