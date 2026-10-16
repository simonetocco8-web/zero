<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\StockReservation;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ExchangeWorkflow;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Payments\StartExchangeDifferencePayment;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class ExchangeConcurrencyTest extends TestCase
{
    use AdditiveDatabaseMigrations { runDatabaseMigrations as private migrateIsolated; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->migrateIsolated();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exchange stock races require MySQL with separate database connections.');
        }
    }

    private function fixture(): array
    {
        $this->seed(PlanSeeder::class);
        $products = [];
        $retailers = [];
        foreach (range(1, 2) as $i) {
            $r = Retailer::factory()->approved()->create();
            Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()->subDay()]);
            $retailers[] = $r;
            $products[] = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => '1', 'pickup_available' => true, 'exchange_available' => true, 'vat_treatment' => 'not_applicable']);
        }
        [$a,$b] = $retailers;
        [$pa,$pb] = $products;
        $input = ['key' => (string) Str::uuid(), 'lines' => ['a' => [['inventory_item_id' => $pa->id, 'quantity' => '1', 'value' => '10']], 'b' => [['inventory_item_id' => $pb->id, 'quantity' => '1', 'value' => '13']]], 'delivery' => ['a' => ['mode' => 'pickup', 'cost' => '0'], 'b' => ['mode' => 'pickup', 'cost' => '0']]];
        $e = app(ExchangeWorkflow::class)->create($a->user, $pb, $input);

        return [$a, $b, $pa, $pb, $e, $input];
    }

    private function race(string $body, array $arguments): array
    {
        $script = tempnam(sys_get_temp_dir(), 'exchange-race-');
        $gate = $script.'.go';
        $processes = [];
        file_put_contents($script, '<?php require getcwd()."/vendor/autoload.php";$app=require getcwd()."/bootstrap/app.php";$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();config(require getcwd()."/tests/Fixtures/marketplace-rules.php");$until=microtime(true)+10;while(!file_exists($argv[1])&&microtime(true)<$until)usleep(10000);'.$body);
        try {
            foreach ($arguments as $args) {
                $p = new Process([PHP_BINARY, $script, $gate, ...$args], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $p->setTimeout(30);
                $p->start();
                $processes[] = $p;
            }
            touch($gate);
            $results = [];
            foreach ($processes as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $results[] = trim($p->getOutput());
            }

            return $results;
        } finally {
            foreach ($processes as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }unlink($script);
            if (file_exists($gate)) {
                unlink($gate);
            }
        }
    }

    public function test_exchange_double_acceptance_and_normal_retailer_purchase_compete_for_same_last_unit(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $v = $e->currentVersion;
        app(ExchangeWorkflow::class)->accept($a->user, $e, $v->id, $v->summary_hash);
        // Retailer A acts as buyer too: this also probes the user/retailer lock interaction.
        app(MarketplaceCart::class)->save($a->user, $pb, '1', 'pickup');
        $q = app(MarketplaceQuote::class);
        $hash = $q->hash($q->build($a->user, []));
        $results = $this->race('try{if($argv[2]==="exchange"){app(App\\Services\\ExchangeWorkflow::class)->accept(App\\Models\\User::findOrFail($argv[3]),App\\Models\\Exchange::findOrFail($argv[4]),(int)$argv[5],$argv[6]);echo "exchange";}else{app(App\\Actions\\CreateMarketplaceOrder::class)->handle(App\\Models\\User::findOrFail($argv[3]),$argv[4],[],null,$argv[5]);echo "purchase";}}catch(Illuminate\\Validation\\ValidationException $e){echo "unavailable";}', [
            ['exchange', (string) $b->user_id, (string) $e->id, (string) $v->id, $v->summary_hash], ['purchase', (string) $a->user_id, (string) Str::uuid(), $hash, 'unused'],
        ]);
        $this->assertContains('unavailable', $results);
        $this->assertSame(1, count(array_filter($results, fn ($x) => $x !== 'unavailable')));
        $this->assertSame(1000, (int) StockReservation::where('inventory_item_id', $pb->id)->where('status', 'active')->sum('quantity_milliunits'));
        $this->assertSame(1000, $pb->fresh()->quantity_milliunits);
        $this->assertSame(0, $pb->fresh()->available_quantity_milliunits);
        if (in_array('exchange', $results, true)) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('stock_reservations', 2);
            $this->assertSame('awaiting_difference_payment', $e->fresh()->status->value);
        } else {
            $this->assertDatabaseCount('orders', 1);
            $this->assertDatabaseCount('stock_reservations', 1);
            $this->assertSame('awaiting_acceptance', $e->fresh()->status->value);
            $this->assertSame(1, $v->acceptances()->count());
        }
    }

    public function test_simultaneous_acceptances_and_retries_create_one_hold_per_product(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $v = $e->currentVersion;
        $body = '$e=App\\Models\\Exchange::findOrFail($argv[3]);app(App\\Services\\ExchangeWorkflow::class)->accept(App\\Models\\User::findOrFail($argv[2]),$e,(int)$argv[4],$argv[5]);echo "ok";';
        $this->race($body, [[(string) $a->user_id, (string) $e->id, (string) $v->id, $v->summary_hash], [(string) $b->user_id, (string) $e->id, (string) $v->id, $v->summary_hash], [(string) $a->user_id, (string) $e->id, (string) $v->id, $v->summary_hash]]);
        $this->assertDatabaseCount('exchange_acceptances', 2);
        $this->assertDatabaseCount('stock_reservations', 2);
        $this->assertDatabaseCount('exchange_difference_payments', 1);
        $this->assertSame(0, $pa->fresh()->available_quantity_milliunits);
    }

    public function test_parallel_duplicate_difference_events_consume_each_side_once(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $v = $e->currentVersion;
        app(ExchangeWorkflow::class)->accept($a->user, $e, $v->id, $v->summary_hash);
        app(ExchangeWorkflow::class)->accept($b->user, $e, $v->id, $v->summary_hash);
        app(StartExchangeDifferencePayment::class)->handle($v);
        $results = $this->race('$v=App\\Models\\ExchangeVersion::findOrFail($argv[2]);echo app(App\\Services\\Payments\\ApplyExchangePaymentResult::class)->handle($v,"same-event",$v->payment->reference,"paid",$v->difference_cents,$v->currency);', [[(string) $v->id], [(string) $v->id]]);
        $this->assertSame(['confirmed', 'confirmed'], $results);
        $this->assertDatabaseCount('exchange_payment_events', 1);
        $this->assertSame(0, $pa->fresh()->quantity_milliunits);
        $this->assertSame(0, $pb->fresh()->quantity_milliunits);
        $this->assertSame(1, $pb->fresh()->stock_version);
        $this->assertSame(2, StockReservation::where('status', 'consumed')->count());
    }

    public function test_expiry_and_late_payment_race_never_consume_expired_holds(): void
    {
        [$a,$b,$pa,$pb,$e] = $this->fixture();
        $v = $e->currentVersion;
        app(ExchangeWorkflow::class)->accept($a->user, $e, $v->id, $v->summary_hash);
        app(ExchangeWorkflow::class)->accept($b->user, $e, $v->id, $v->summary_hash);
        app(StartExchangeDifferencePayment::class)->handle($v);
        $this->race('Illuminate\\Support\\Carbon::setTestNow(now()->addMinutes(1441));if($argv[2]==="expiry"){app(App\\Services\\ExchangeWorkflow::class)->expire();echo "expired";}else{$v=App\\Models\\ExchangeVersion::findOrFail($argv[3]);echo app(App\\Services\\Payments\\ApplyExchangePaymentResult::class)->handle($v,"late-event",$v->payment->reference,"paid",$v->difference_cents,$v->currency);}', [['expiry', (string) $v->id], ['payment', (string) $v->id]]);
        $this->assertSame('expired', $e->fresh()->status->value);
        $this->assertSame(1000, $pa->fresh()->quantity_milliunits);
        $this->assertSame(1000, $pb->fresh()->available_quantity_milliunits);
        $this->assertSame(2, StockReservation::where('status', 'expired')->count());
        $this->assertSame(1, $e->events()->where('action', 'expired')->count());
    }
}
