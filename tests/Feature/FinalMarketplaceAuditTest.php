<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class FinalMarketplaceAuditTest extends TestCase
{
    use AdditiveRefreshDatabase;

    public function test_customer_browser_mutations_require_real_csrf(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->postJson(route('checkout.confirm'), ['key' => (string) Str::uuid()])->assertStatus(419);
            $this->postJson(route('notifications.preferences'), ['email_notifications' => false])->assertStatus(419);
            $this->postJson(route('login'), ['email' => 'audit@example.test', 'password' => 'invalid'])->assertStatus(419);
        } finally {
            $this->app['env'] = $env;
        }
        $this->assertDatabaseCount('orders', 0);
    }

    private function queries(string $route): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route($route))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_order_dashboard_and_notification_pagination_do_not_add_queries_per_record(): void
    {
        $this->seed(PlanSeeder::class);
        $retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()->subDay()]);
        $product = InventoryItem::factory()->published()->create(['retailer_id' => $retailer->id, 'quantity' => '100', 'pickup_available' => true, 'vat_treatment' => 'not_applicable']);
        $buyer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($buyer);
        $first = [];
        foreach (range(1, 20) as $index) {
            app(MarketplaceCart::class)->save($buyer, $product, '1', 'pickup');
            $quotes = app(MarketplaceQuote::class);
            app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($buyer, [])));
            $buyer->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'audit', 'data' => ['title' => 'Audit', 'message' => 'Notifica sintetica']]);
            if ($index === 1) {
                foreach (['orders.index', 'customer.dashboard', 'notifications.index'] as $route) {
                    $first[$route] = $this->queries($route);
                }
            }
        }
        foreach ($first as $route => $count) {
            $this->assertLessThanOrEqual($count + 1, $this->queries($route), $route.' N+1 regression');
        }
        $this->get(route('orders.index'))->assertViewHas('orders', fn ($rows) => $rows->total() === 20 && $rows->count() === 15);
        $this->get(route('notifications.index'))->assertViewHas('notices', fn ($rows) => $rows->total() === 20 && $rows->count() === 20);
    }
}
