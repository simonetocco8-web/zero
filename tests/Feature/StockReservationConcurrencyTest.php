<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\StockReservation;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartMarketplacePayment;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class StockReservationConcurrencyTest extends TestCase
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
            $this->markTestSkipped('Real parallel stock locking requires MySQL.');
        }
    }

    private function product(string $quantity): InventoryItem
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id, 'starts_at' => now()->subDay()]);

        return InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'quantity' => $quantity, 'pickup_available' => true, 'vat_treatment' => 'not_applicable']);
    }

    /** Synchronized independent PHP processes and independent database connections. */
    private function race(string $body, array $arguments): array
    {
        $script = tempnam(sys_get_temp_dir(), 'stock-race-');
        $gate = $script.'.go';
        $processes = [];
        file_put_contents($script, '<?php require getcwd()."/vendor/autoload.php"; $app=require getcwd()."/bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();config(require getcwd()."/tests/Fixtures/marketplace-rules.php"); $until=microtime(true)+10; while(!file_exists($argv[1]) && microtime(true)<$until)usleep(10000); '.$body);
        try {
            foreach ($arguments as $args) {
                $process = new Process([PHP_BINARY, $script, $gate, ...$args], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            touch($gate);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            unlink($script);
            if (file_exists($gate)) {
                unlink($gate);
            }
        }
    }

    private function raceCheckouts(string $onHand): array
    {
        $p = $this->product($onHand);
        $quotes = app(MarketplaceQuote::class);
        $args = [];
        foreach (range(1, 2) as $i) {
            $u = User::factory()->create(['role' => 'customer']);
            app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
            $args[] = [(string) $u->id, (string) Str::uuid(), $quotes->hash($quotes->build($u, []))];
        }
        $results = $this->race('try { $order=app(App\\Actions\\CreateMarketplaceOrder::class)->handle(App\\Models\\User::findOrFail($argv[2]),$argv[3],[],null,$argv[4]); echo "reserved"; } catch(Illuminate\\Validation\\ValidationException $e) { echo "unavailable"; }', $args);

        return [$p, $results];
    }

    public function test_two_buyers_racing_for_last_unit_only_one_checkout_succeeds(): void
    {
        [$p, $results] = $this->raceCheckouts('1');
        sort($results);
        $this->assertSame(['reserved', 'unavailable'], $results);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        $this->assertSame(0, $p->fresh()->available_quantity_milliunits);
    }

    public function test_simultaneous_reservations_with_sufficient_stock_remain_valid_and_retries_create_no_duplicates(): void
    {
        // Synchronized checkouts review availability before either reservation exists. The loser must
        // re-review its changed availability snapshot, rather than accept an obsolete review hash.
        [$p, $results] = $this->raceCheckouts('2');
        $this->assertContains('reserved', $results);
        foreach (User::where('role', 'customer')->get() as $u) {
            if (Order::where('user_id', $u->id)->exists()) {
                continue;
            }
            $quotes = app(MarketplaceQuote::class);
            app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($u, [])));
        }
        $this->assertSame(2, StockReservation::where('status', 'active')->count());
        $this->assertSame(0, $p->fresh()->available_quantity_milliunits);
        $this->assertSame(2000, $p->fresh()->quantity_milliunits);
    }

    public function test_parallel_duplicate_success_events_and_payment_retries_consume_stock_once(): void
    {
        $p = $this->product('1');
        $u = User::factory()->create(['role' => 'customer']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $q = app(MarketplaceQuote::class);
        $order = app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $q->hash($q->build($u, [])));
        $this->race('app(App\\Services\\Payments\\StartMarketplacePayment::class)->handle(App\\Models\\Order::findOrFail($argv[2])); echo "started";', [[(string) $order->id], [(string) $order->id]]);
        $results = $this->race('$o=App\\Models\\Order::findOrFail($argv[2]); echo app(App\\Services\\Payments\\ApplyMarketplacePaymentResult::class)->handle($o,"same-event",$o->payment->reference,"paid",$o->total_cents,$o->currency);', [[(string) $order->id], [(string) $order->id]]);
        $this->assertSame(['consumed', 'consumed'], $results);
        $this->assertDatabaseCount('marketplace_payments', 1);
        $this->assertDatabaseCount('marketplace_payment_events', 1);
        $this->assertSame(0, $p->fresh()->quantity_milliunits);
        $this->assertSame(1, $p->fresh()->stock_version);
        $this->assertSame('paid', $order->fresh()->status->value);
    }

    public function test_success_and_release_race_never_reopens_reservation_or_decrements_twice(): void
    {
        $p = $this->product('1');
        $u = User::factory()->create(['role' => 'customer']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $q = app(MarketplaceQuote::class);
        $order = app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $q->hash($q->build($u, [])));
        app(StartMarketplacePayment::class)->handle($order);
        $this->race('$o=App\\Models\\Order::findOrFail($argv[2]); if($argv[3]==="release"){app(App\\Services\\StockReservations::class)->release($o);} else {app(App\\Services\\Payments\\ApplyMarketplacePaymentResult::class)->handle($o,"success",$o->payment->reference,"paid",$o->total_cents,$o->currency);} echo "ok";', [[(string) $order->id, 'release'], [(string) $order->id, 'success']]);
        $status = StockReservation::sole()->status->value;
        $this->assertContains($status, ['consumed', 'released']);
        $this->assertSame($status === 'consumed' ? 0 : 1000, $p->fresh()->quantity_milliunits);
        $this->assertSame($status === 'consumed' ? 'paid' : 'cancelled', $order->fresh()->status->value);
    }

    public function test_concurrent_refund_retries_restore_stock_and_reverse_commission_only_once(): void
    {
        $p = $this->product('1');
        $u = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        app(MarketplaceCart::class)->save($u, $p, '1', 'pickup');
        $q = app(MarketplaceQuote::class);
        $o = app(CreateMarketplaceOrder::class)->handle($u, (string) Str::uuid(), [], null, $q->hash($q->build($u, [])));
        app(StartMarketplacePayment::class)->handle($o);
        app(ApplyMarketplacePaymentResult::class)->handle($o, 'initial-paid', $o->payment->reference, 'paid', $o->total_cents, 'EUR');
        $v = $o->vendorOrders()->sole();
        $results = $this->race('$v=App\\Models\\VendorOrder::findOrFail($argv[2]);$a=App\\Models\\User::findOrFail($argv[3]);app(App\\Services\\VendorOrderWorkflow::class)->refund($a,$v,"Concurrent refund");echo "refunded";', [[(string) $v->id, (string) $admin->id], [(string) $v->id, (string) $admin->id]]);
        $this->assertSame(['refunded', 'refunded'], $results);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
        $this->assertDatabaseCount('marketplace_refunds', 1);
        $this->assertDatabaseCount('marketplace_stock_returns', 1);
        $this->assertSame(1, WalletTransaction::where('operation_kind', 'commission_reversal')->count());
        $this->assertSame('ignored_after_refund', app(ApplyMarketplacePaymentResult::class)->handle($o, 'retry-after-refund', $o->payment->reference, 'paid', $o->total_cents, 'EUR'));
        $this->assertSame('refunded', $o->fresh()->status->value);
        $this->assertSame(1000, $p->fresh()->quantity_milliunits);
    }
}
