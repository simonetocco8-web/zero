<?php

namespace Tests\Feature;

use App\Models\BillingCheckout;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BillingConcurrencyTest extends TestCase
{
    use DatabaseMigrations {runDatabaseMigrations as private runMysqlMigrations; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->runMysqlMigrations();
        }
    }

    public function test_mysql_simultaneous_checkouts_share_one_intent_and_idempotency_key(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Row lock concurrency requires MySQL.');
        }
        $this->seed(PlanSeeder::class);
        $retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);
        $script = tempnam(sys_get_temp_dir(), 'zero-checkout-race-');
        $gate = $script.'.go';
        $processes = [];
        file_put_contents($script, <<<'CODE'
<?php
require getcwd().'/vendor/autoload.php';$app=require getcwd().'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['stripe.secret'=>'unused-test-configuration','stripe.prices.monthly'=>'price_monthly']);
$app->instance(App\Contracts\StripeBillingGateway::class,new class implements App\Contracts\StripeBillingGateway {
 public function createCheckout(App\Models\BillingCheckout $checkout):array {return ['id'=>'cs_'.$checkout->id,'url'=>'https://checkout.stripe.com/c/pay/'.$checkout->id];}
 public function subscription(string $id):App\Data\StripeSubscriptionData {throw new RuntimeException('Not used.');}
});
$until=microtime(true)+10;while(!file_exists($argv[2])&&microtime(true)<$until)usleep(10000);
app(App\Services\Billing\StartStripeCheckout::class)->handle(App\Models\User::findOrFail($argv[1]),'monthly');
CODE);
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, $script, (string) $retailer->user_id, $gate], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            touch($gate);
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $this->assertSame(1, BillingCheckout::count());
            $checkout = BillingCheckout::sole();
            $this->assertSame('open', $checkout->status);
            $this->assertSame('cs_'.$checkout->id, $checkout->checkout_session_id);
            $this->assertSame(6900, $checkout->price_cents);
            $this->assertSame('free', $retailer->activeSubscription()->with('plan')->sole()->plan->code);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }@unlink($gate);
            @unlink($script);
        }
    }
}
