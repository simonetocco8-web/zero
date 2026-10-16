<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class MarketplaceCheckoutConcurrencyTest extends TestCase
{
    use AdditiveDatabaseMigrations {runDatabaseMigrations as private migrateIsolated; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->migrateIsolated();
        }
    }

    public function test_concurrent_confirmations_create_one_order_and_one_fake_intent(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Checkout concurrency requires MySQL.');
        }
        $this->seed(PlanSeeder::class);
        $u = User::factory()->create(['role' => 'customer']);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        $p = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '10', 'pickup_available' => true]);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $quotes = app(MarketplaceQuote::class);
        $hash = $quotes->hash($quotes->build($u, []));
        $key = (string) Str::uuid();
        $script = tempnam(sys_get_temp_dir(), 'market-confirm-');
        $gate = $script.'.go';
        file_put_contents($script, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(require getcwd()."/tests/Fixtures/marketplace-rules.php");
$until=microtime(true)+10;while(!file_exists($argv[4])&&microtime(true)<$until)usleep(10000);
$order=app(App\Actions\CreateMarketplaceOrder::class)->handle(App\Models\User::findOrFail($argv[1]),$argv[2],[],null,$argv[3]);app(App\Services\Payments\StartMarketplacePayment::class)->handle($order);
PHP);
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, $script, (string) $u->id, $key, $hash, $gate], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }touch($gate);
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }$this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('vendor_orders', 1);
            $this->assertDatabaseCount('marketplace_payments', 1);
            $this->assertNotNull(Order::sole()->payment->reference);
            $this->assertSame(10000, $p->fresh()->quantity_milliunits);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }unlink($script);
            if (file_exists($gate)) {
                unlink($gate);
            }
        }
    }
}
