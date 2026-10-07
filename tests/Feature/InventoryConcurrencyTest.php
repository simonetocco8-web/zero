<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations { runDatabaseMigrations as private runMysqlMigrations; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->runMysqlMigrations();
        }
    }

    public static function limits(): array
    {
        return ['article count' => [4, 100, 5], 'inventory value' => [1, 999900, 2]];
    }

    #[DataProvider('limits')]
    public function test_mysql_concurrent_creations_cannot_exceed_limits(int $count, int $price, int $expected): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row lock concurrency requires MySQL.');
        }
        $this->seed(PlanSeeder::class);
        $retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        InventoryItem::factory()->count($count)->create(['retailer_id' => $retailer->id, 'quantity' => '1', 'zero_price_cents' => $price]);
        $script = tempnam(sys_get_temp_dir(), 'zero-race-');
        $gate = $script.'.go';
        file_put_contents($script, <<<'CODE'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$until=microtime(true)+10; while(!file_exists($argv[2]) && microtime(true)<$until) usleep(10000);
try {
 app(App\Actions\SaveInventoryItem::class)->handle(App\Models\User::findOrFail($argv[1]),['name'=>'Concurrent','category'=>'Materiali','quantity'=>'1','zero_price'=>'1','condition'=>'new','province'=>'Roma','description'=>'Test','pickup_available'=>true,'intent'=>'pending']);
 exit(0);
} catch (Illuminate\Validation\ValidationException $e) { exit((isset($e->errors()['plan']) || isset($e->errors()['quantity'])) ? 3:4); }
CODE);
        $processes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, $script, (string) $retailer->user_id, $gate], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
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
            $this->assertSame([0, 3], $codes);
            $this->assertSame($expected, $retailer->inventoryItems()->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            } @unlink($gate);
            @unlink($script);
        }
    }
}
