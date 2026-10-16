<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\RetailerCredit;
use App\Services\StockReservations;
use App\Services\WalletBalance;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class RetailerCreditConcurrencyTest extends TestCase
{
    use AdditiveDatabaseMigrations {runDatabaseMigrations as private migrateIsolated; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->migrateIsolated();
        }
    }

    public static function competitors(): array
    {
        return ['two purchases' => ['other-order'], 'same purchase retry' => ['same-order'], 'purchase versus payout' => ['payout'], 'two reversals' => ['reverse'], 'same reversal retry' => ['reverse-retry']];
    }

    #[DataProvider('competitors')]
    public function test_real_credit_cannot_be_reserved_or_withdrawn_twice(string $competitor): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real credit concurrency requires MySQL locks.');
        }
        $this->seed(PlanSeeder::class);
        $buyer = Retailer::factory()->approved()->create(['iban' => 'IT60X0542811101000000123456']);
        $seller = Retailer::factory()->approved()->create();
        foreach ([$buyer, $seller] as $r) {
            Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()->subDay()]);
        }
        WalletTransaction::create(['retailer_id' => $buyer->id, 'idempotency_key' => 'race-opening', 'type' => 'adjustment', 'amount_cents' => 20000, 'currency' => 'EUR', 'simulated' => false, 'available_at' => now()->subMinute()]);
        $orders = [];
        for ($i = 0; $i < 2; $i++) {
            $p = InventoryItem::factory()->published()->create(['retailer_id' => $seller->id, 'quantity' => '1', 'zero_price_cents' => 50000, 'pickup_available' => true, 'vat_treatment' => 'not_applicable']);
            app(MarketplaceCart::class)->save($buyer->user, $p, '1', 'pickup');
            $q = app(MarketplaceQuote::class);
            $orders[] = app(CreateMarketplaceOrder::class)->handle($buyer->user, (string) Str::uuid(), [], null, $q->hash($q->build($buyer->user, [])), false, false);
        }
        if (str_starts_with($competitor, 'reverse')) {
            config(['marketplace.economic_rules.execution_mode' => 'stripe_connect', 'marketplace.economic_rules.funds_flow' => 'stripe_connect_separate_charges_transfers', 'marketplace.stripe.activation_verified' => true, 'marketplace.stripe.tax_verified' => true, 'marketplace.stripe.secret' => 'sk_test_fixture_only', 'marketplace.commission_tax.treatment' => 'not_applicable']);
            app(StockReservations::class)->locked($orders[0], function () use ($orders) {
                app(RetailerCredit::class)->reserve($orders[0]);
                app(RetailerCredit::class)->finish($orders[0], true);
            });
        }
        $script = tempnam(sys_get_temp_dir(), 'retailer-credit-race-');
        $gate = $script.'.go';
        file_put_contents($script, <<<'CODE'
<?php
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(require getcwd().'/tests/Fixtures/marketplace-rules.php');
config(['marketplace.economic_rules.execution_mode'=>'stripe_connect','marketplace.economic_rules.funds_flow'=>'stripe_connect_separate_charges_transfers','marketplace.stripe.activation_verified'=>true,'marketplace.stripe.tax_verified'=>true,'marketplace.stripe.secret'=>'sk_test_fixture_only','marketplace.commission_tax.treatment'=>'not_applicable']);
$until=microtime(true)+10;while(!file_exists($argv[2])&&microtime(true)<$until)usleep(10000);
try {
 if($argv[3]==='payout') {
   app(App\Actions\RequestPayout::class)->handle(App\Models\User::findOrFail($argv[4]));
 } else {
   $o=App\Models\Order::findOrFail($argv[1]);
   app(App\Services\StockReservations::class)->locked($o,function() use($o,$argv) {
     if(str_starts_with($argv[3],'reverse')) app(App\Services\RetailerCredit::class)->reverse($o,15000,$argv[3]==='reverse-retry'?'same-key':$argv[5]);
     else app(App\Services\RetailerCredit::class)->reserve($o);
   });
 }
 exit(0);
} catch(Illuminate\Validation\ValidationException $e) {exit(isset($e->errors()['payout'])||isset($e->errors()['credit'])?3:4);}
CODE);
        $processes = [];
        try {
            foreach ([0, 1] as $i) {
                $id = $orders[$competitor === 'same-order' || str_starts_with($competitor, 'reverse') ? 0 : $i]->id;
                $p = new Process([PHP_BINARY, $script, (string) $id, $gate, $i === 1 && $competitor === 'payout' ? 'payout' : (str_starts_with($competitor, 'reverse') ? $competitor : 'purchase'), (string) $buyer->user_id, (string) $i], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $p->setTimeout(30);
                $p->start();
                $processes[] = $p;
            }
            touch($gate);
            foreach ($processes as $p) {
                $p->wait();
                $this->assertContains($p->getExitCode(), [0, 3], $p->getErrorOutput());
            }
            if (str_starts_with($competitor, 'reverse')) {
                $this->assertSame(15000, app(WalletBalance::class)->availableCents($buyer));
                $this->assertSame(1, WalletTransaction::where('operation_kind', 'credit_refund')->count());

                return;
            }
            $reserved = (int) DB::table('retailer_credit_holds')->sum('amount_cents');
            $payout = (int) $buyer->payoutRequests()->sum('amount_cents');
            $this->assertSame(20000, $reserved + $payout);
            $this->assertSame(0, app(WalletBalance::class)->availableCents($buyer));
            $this->assertSame(20000, app(WalletBalance::class)->reservedCents($buyer));
            $this->assertSame($reserved > 0 ? 2 : 0, WalletTransaction::where('operation_kind', 'credit_reservation')->count());
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
