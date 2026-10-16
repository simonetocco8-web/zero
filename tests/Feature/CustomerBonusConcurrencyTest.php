<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CustomerPromotionalBonus;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class CustomerBonusConcurrencyTest extends TestCase
{
    use AdditiveDatabaseMigrations {runDatabaseMigrations as private migrateIsolated; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->migrateIsolated();
        }
    }

    public function test_two_orders_cannot_reserve_the_same_promotional_bonus(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent bonus consumption requires MySQL locks.');
        }
        $this->seed(PlanSeeder::class);
        $u = User::factory()->create(['role' => 'customer']);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        $orders = [];
        for ($i = 0; $i < 2; $i++) {
            $p = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '1', 'zero_price_cents' => 10000, 'pickup_available' => true, 'vat_treatment' => 'not_applicable']);
            app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
            $q = app(MarketplaceQuote::class);
            $orders[] = app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $q->hash($q->build($u, [])));
        }
        app(CustomerPromotionalBonus::class)->grant(User::factory()->create(['role' => 'admin']), $u, 1000, 'race', 'race-grant');
        $script = tempnam(sys_get_temp_dir(), 'bonus-race-');
        $gate = $script.'.go';
        file_put_contents($script, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(require getcwd().'/tests/Fixtures/marketplace-rules.php');
$until=microtime(true)+10;
while(!file_exists($argv[2])&&microtime(true)<$until)usleep(10000);
$o=App\Models\Order::findOrFail($argv[1]);
echo Illuminate\Support\Facades\DB::transaction(fn()=>app(App\Services\CustomerPromotionalBonus::class)->reserve($o,true),5);
PHP);
        $processes = [];
        try {
            foreach ($orders as $o) {
                $p = new Process([PHP_BINARY, $script, (string) $o->id, $gate], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $p->setTimeout(30);
                $p->start();
                $processes[] = $p;
            }
            touch($gate);
            $amounts = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
                $amounts[] = (int) $p->getOutput();
            }
            sort($amounts);
            $this->assertSame([0, 1000], $amounts);
            $this->assertSame(0, app(CustomerPromotionalBonus::class)->available($u));
            $this->assertSame(1000, (int) DB::table('customer_bonus_entries')->where('bucket', 'reserved')->sum('amount_cents'));
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            @unlink($script);
            @unlink($gate);
        }
    }
}
