<?php

namespace Tests\Feature;

use App\Jobs\ProcessIntegrationEvent;
use App\Models\IntegrationEvent;
use App\Models\InventoryItem;
use App\Models\PayoutRequest;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InventoryPhotos;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SecurityUxAuditTest extends TestCase
{
    use RefreshDatabase;

    private function retailer(): Retailer
    {
        $this->seed(PlanSeeder::class);
        $retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);

        return $retailer;
    }

    public function test_private_pages_are_not_cacheable_and_escape_product_names(): void
    {
        $retailer = $this->retailer();
        InventoryItem::factory()->create(['retailer_id' => $retailer->id, 'name' => '<script>alert(1)</script>']);
        $this->actingAs($retailer->user)->get(route('retailer.stock'))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->get(route('retailer.profile'))->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_photo_reference_cannot_escape_private_inventory_directory(): void
    {
        Storage::fake('local');
        Storage::put('protected.txt', 'private');
        $retailer = $this->retailer();
        $item = InventoryItem::factory()->create(['retailer_id' => $retailer->id]);
        $image = $item->images()->create(['disk' => 'local', 'path' => 'inventory/../protected.txt', 'position' => 0]);
        $this->actingAs($retailer->user)->get(route('inventory.image', [$item, 0]))->assertNotFound();
        app(InventoryPhotos::class)->delete([$image->only(['disk', 'path'])]);
        Storage::assertExists('protected.txt');
        $path = 'inventory/'.Str::uuid().'.jpg';
        Storage::put($path, 'test-photo');
        $image->update(['path' => $path]);
        $this->get(route('inventory.image', [$item, 0]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get(route('inventory.image', [$item, 0]))->assertForbidden();
    }

    public function test_inventory_identifiers_are_validated_before_database_insert(): void
    {
        $retailer = $this->retailer();
        $this->actingAs($retailer->user)->post(route('retailer.stock.store'), [
            'name' => 'Prodotto', 'category' => 'Edilizia', 'quantity' => '1', 'zero_price' => '1.00', 'condition' => 'new',
            'province' => 'MI', 'description' => 'Descrizione', 'pickup_available' => '1', 'intent' => 'pending',
            'sku' => str_repeat('s', 192), 'ean' => str_repeat('1', 192),
        ])->assertSessionHasErrors(['sku', 'ean']);
        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_confirmation_restores_only_the_failed_modal_and_associates_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payout = PayoutRequest::factory()->create();
        $response = $this->actingAs($admin)->from(route('admin.payouts'))->patch(route('admin.payouts.review', $payout), [
            'decision' => 'paid', 'payment_reference' => str_repeat('r', 192), 'confirmation_id' => 'payout-paid-'.$payout->id,
        ]);
        $response->assertRedirect(route('admin.payouts'));
        $page = $this->get(route('admin.payouts'));
        $page->assertOk()->assertSee('aria-invalid="true"', false)->assertSee('x-init="open()"', false)->assertSee('riferimento disposizione bancaria')->assertDontSee('Il campo payment_reference');
        $this->assertSame('pending', $payout->fresh()->status->value);
    }

    public function test_password_confirmation_is_rate_limited(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 6; $i++) {
            $this->post('/confirm-password', ['password' => 'incorrect'])->assertSessionHasErrors('password');
        }
        $this->post('/confirm-password', ['password' => 'incorrect'])->assertTooManyRequests();
    }

    public function test_retry_recovers_an_orphaned_processing_receipt(): void
    {
        Queue::fake();
        $event = IntegrationEvent::factory()->create(['event_type' => 'store.order.paid', 'status' => 'processing', 'processing_at' => null]);
        $this->artisan('integrations:retry')->assertSuccessful();
        Queue::assertPushed(ProcessIntegrationEvent::class, fn ($job) => $job->eventId === $event->id);
    }

    public function test_photo_service_also_enforces_source_size(): void
    {
        $this->expectException(ValidationException::class);
        app(InventoryPhotos::class)->store(UploadedFile::fake()->create('large.jpg', 15361, 'image/jpeg'));
    }

    public function test_edit_errors_preserve_an_explicitly_unchecked_delivery_option(): void
    {
        $retailer = $this->retailer();
        $item = InventoryItem::factory()->create(['retailer_id' => $retailer->id, 'shipping_available' => true]);
        $this->actingAs($retailer->user)->from(route('retailer.stock.edit', $item))->put(route('retailer.stock.update', $item), [
            'name' => 'Nome conservato', 'category' => 'Edilizia', 'quantity' => '1', 'zero_price' => '1.00',
            'condition' => 'new', 'province' => 'MI', 'description' => '', 'pickup_available' => '1',
            'shipping_available' => '0', 'intent' => 'pending',
        ])->assertRedirect(route('retailer.stock.edit', $item));
        $page = $this->get(route('retailer.stock.edit', $item));
        $page->assertOk()->assertSee('value="Nome conservato"', false)->assertSee('description-error', false);
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $shipping = (new \DOMXPath($document))->query('//input[@id="shipping_available"]')->item(0);
        $this->assertNotNull($shipping);
        $this->assertFalse($shipping->hasAttribute('checked'));
        $this->assertTrue($item->fresh()->shipping_available);
    }

    public function test_admin_date_filters_include_the_whole_last_day(): void
    {
        foreach (['2026-01-03 00:00:00' => 'Primo', '2026-01-03 23:59:59' => 'Ultimo', '2026-01-04 00:00:00' => 'Escluso'] as $date => $name) {
            Retailer::factory()->create(['company_name' => $name, 'created_at' => $date]);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.retailers', ['from' => '2026-01-03', 'to' => '2026-01-03']))
            ->assertOk()->assertSee('Primo')->assertSee('Ultimo')->assertDontSee('Escluso');
    }

    public function test_admin_lists_use_the_latest_subscription_after_plan_history_changes(): void
    {
        $retailer = $this->retailer();
        $retailer->subscriptions()->update(['status' => 'cancelled']);
        Subscription::factory()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.retailers'))
            ->assertOk()->assertSee('FREE')->assertSee('Non richiesto (gratuito)')->assertDontSee('Pagamento non ricevuto');
    }

    public function test_production_database_errors_do_not_log_sql_or_sensitive_bindings(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Log::shouldReceive('error')->once()->with('Database operation failed.', [
            'sql_state' => '23000', 'driver_code' => 1062, 'connection' => 'mysql',
        ]);
        $driver = new \PDOException('Duplicate sensitive-person@example.test', 23000);
        $driver->errorInfo = ['23000', 1062, 'Duplicate sensitive-person@example.test'];
        report(new QueryException('mysql', 'insert into users (email) values (?)', ['sensitive-person@example.test'], $driver));
    }
}
