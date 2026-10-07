<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Sale;
use App\Models\Subscription;
use App\Services\SaleAccounting;
use App\Services\WalletBalance;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FinanceConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private runMysqlMigrations; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->runMysqlMigrations();
        }
    }

    public static function operations(): array
    {
        return ['payout' => ['payout'], 'sale' => ['sale'], 'refund' => ['refund']];
    }

    #[DataProvider('operations')]
    public function test_mysql_simultaneous_operations_are_idempotent(string $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real row-lock concurrency requires MySQL.');
        }
        $this->seed(PlanSeeder::class);
        $retailer = Retailer::factory()->approved()->create(['iban' => 'IT60X0542811101000000123456']);
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);
        $product = InventoryItem::factory()->create(['retailer_id' => $retailer->id]);
        $payload = ['provider' => 'fake', 'external_order_id' => 'race-order', 'timestamp' => now()->toIso8601String(), 'items' => [['external_line_id' => '1', 'inventory_item_id' => $product->id, 'retailer_id' => $retailer->id, 'quantity' => '1', 'unit_price_cents' => 10000]]];
        if ($operation !== 'sale') {
            app(SaleAccounting::class)->recordSale($payload);
        }
        if ($operation === 'refund') {
            $payload = ['provider' => 'fake', 'external_order_id' => 'race-order', 'external_refund_id' => 'race-refund', 'timestamp' => now()->toIso8601String(), 'items' => [['external_line_id' => '1', 'amount_cents' => 10000]]];
        }
        $script = tempnam(sys_get_temp_dir(), 'zero-payout-race-');
        $gate = $script.'.go';
        $processes = [];
        $input = $script.'.json';
        file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR));
        file_put_contents($script, <<<'CODE'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$until=microtime(true)+10;while(!file_exists($argv[2])&&microtime(true)<$until)usleep(10000);
try {
 if($argv[3]==='payout') app(App\Actions\RequestPayout::class)->handle(App\Models\User::findOrFail($argv[1]));
 else { $data=json_decode(file_get_contents($argv[4]),true,512,JSON_THROW_ON_ERROR); $service=app(App\Services\SaleAccounting::class); if($argv[3]==='sale') $service->recordSale($data); else $service->refund($data); }
 exit(0);
}
catch(Illuminate\Validation\ValidationException $e){exit(isset($e->errors()['payout'])?3:4);}
CODE);
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, $script, (string) $retailer->user_id, $gate, $operation, $input], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            touch($gate);
            $codes = [];
            foreach ($processes as $process) {
                $process->wait();
                $codes[] = $process->getExitCode();
                $this->assertContains($process->getExitCode(), [0, 3], $process->getErrorOutput());
            }
            sort($codes);
            $this->assertSame($operation === 'payout' ? [0, 3] : [0, 0], $codes);
            $this->assertSame(1, Sale::count());
            $this->assertSame($operation === 'payout' ? 1 : 0, $retailer->payoutRequests()->count());
            $this->assertSame($operation === 'refund' ? 4 : ($operation === 'payout' ? 3 : 2), $retailer->walletTransactions()->count());
            $this->assertSame($operation === 'sale' ? 9800 : 0, app(WalletBalance::class)->availableCents($retailer));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }@unlink($gate);
            @unlink($script);
            @unlink($input);
        }
    }
}
