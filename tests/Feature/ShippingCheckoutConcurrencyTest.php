<?php

namespace Tests\Feature;

use App\Actions\SaveCustomerAddress;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Shipping\ShippingQuoteService;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class ShippingCheckoutConcurrencyTest extends TestCase
{
    use AdditiveDatabaseMigrations {runDatabaseMigrations as private migrateIsolated; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->migrateIsolated();
        }
    }

    public function test_concurrent_dynamic_shipping_confirmation_consumes_one_quote_and_reserves_stock_once(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Checkout concurrency requires MySQL.');
        }
        $this->seed(PlanSeeder::class);
        config(['shipping.enabled' => true, 'shipping.provider' => 'fake', 'shipping.checkout_verified' => true]);
        $u = User::factory()->create(['role' => 'customer']);
        $r = Retailer::factory()->approved()->create(['address' => 'Via Test 1', 'city' => 'Roma', 'province' => 'RM', 'postal_code' => '00100', 'country_code' => 'IT']);
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        $p = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '10', 'pickup_available' => true, 'shipping_available' => true, 'vat_treatment' => 'not_applicable', 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100, 'weight_g' => 1000, 'units_per_parcel_milliunits' => 1000, 'packing_rule' => 'whole_parcels']);
        $destination = ['recipient_name' => 'Test', 'address_line_1' => 'Via Test 2', 'postal_code' => '20100', 'city' => 'Milano', 'province' => 'MI', 'country_code' => 'IT'];
        $rate = app(ShippingQuoteService::class)->quote($u, $r, [['product' => $p, 'quantity_milliunits' => 1000]], $destination)[0];
        $address = app(SaveCustomerAddress::class)->handle($u, $destination + ['label' => 'Casa']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'shipping', true, $rate->id);
        $quotes = app(MarketplaceQuote::class);
        $hash = $quotes->hash($quotes->build($u, [], $address));
        $key = (string) Str::uuid();
        $script = tempnam(sys_get_temp_dir(), 'market-confirm-');
        $gate = $script.'.go';
        file_put_contents($script, <<<'PHP'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(require getcwd()."/tests/Fixtures/marketplace-rules.php");config(["shipping.enabled"=>true,"shipping.provider"=>"fake","shipping.checkout_verified"=>true]);
$until=microtime(true)+10;while(!file_exists($argv[4])&&microtime(true)<$until)usleep(10000);
$order=app(App\Actions\CreateMarketplaceOrder::class)->handle(App\Models\User::findOrFail($argv[1]),$argv[2],[],(int)$argv[5],$argv[3]);app(App\Services\Payments\StartMarketplacePayment::class)->handle($order);
PHP);
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, $script, (string) $u->id, $key, $hash, $gate, (string) $address->id], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
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
            $this->assertSame('used', $rate->fresh()->status);
            $this->assertSame(600, Order::sole()->shipping_cents);
            $this->assertDatabaseCount('stock_reservations', 1);
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
