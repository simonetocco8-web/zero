<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryImport;
use App\Models\InventoryImportRow;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Services\InventoryImport\ImportWorkflow;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveDatabaseMigrations;
use Tests\TestCase;

class InventoryImportConcurrencyTest extends TestCase
{
    use AdditiveDatabaseMigrations { runDatabaseMigrations as private migrateIsolated; }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $this->migrateIsolated();
        }
    }

    private function import(Retailer $retailer, string $sku): InventoryImport
    {
        $workflow = app(ImportWorkflow::class);
        $import = $workflow->upload($retailer->user, UploadedFile::fake()->createWithContent('stock.csv', "Codice;Nome\n$sku;Articolo $sku\n"), ['delimiter' => ';', 'enclosure' => '"', 'encoding' => 'UTF-8', 'has_headers' => true, 'number_format' => 'italian']);
        $workflow->configure($retailer->user, $import, 'mapping', ['sources' => ['sku' => '0', 'name' => '1', 'description' => 'fixed', 'quantity' => 'fixed', 'zero_price' => 'fixed', 'unit' => 'fixed', 'condition' => 'fixed', 'province' => 'fixed', 'pickup_available' => 'fixed'], 'fixed' => ['description' => 'Descrizione', 'quantity' => '1', 'zero_price' => '1', 'unit' => 'piece', 'condition' => 'new', 'province' => 'VV', 'pickup_available' => 'sì']]);
        $workflow->configure($retailer->user, $import, 'configuration', ['category_mode' => 'single', 'category_id' => Category::where('slug', 'edilizia')->sole()->id, 'mode' => 'new', 'number_format' => 'italian']);
        $workflow->preview($retailer->user, $import);
        $workflow->confirm($retailer->user, $import->fresh(), $import->fresh()->preview_token, 'draft');

        return $import->fresh();
    }

    public static function races(): array
    {
        return ['same job twice' => [true], 'two different imports for final free slot' => [false]];
    }

    #[DataProvider('races')]
    public function test_mysql_parallel_imports_preserve_quota_and_exactly_once_rows(bool $same): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires real MySQL row locks.');
        }
        Queue::fake();
        Cache::put('inventory-import-worker:database:inventory-imports', now()->getTimestamp(), 180);
        $this->seed(PlanSeeder::class);
        $retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        InventoryItem::factory()->count(4)->create(['retailer_id' => $retailer->id]);
        $first = $this->import($retailer, 'one');
        $second = $same ? $first : $this->import($retailer, 'two');
        $script = tempnam(sys_get_temp_dir(), 'zero-import-race-');
        $gate = $script.'.go';
        file_put_contents($script, <<<'CODE'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$until = microtime(true) + 10;
while (!file_exists($argv[2]) && microtime(true) < $until) { usleep(10000); }
app(App\Services\InventoryImport\ImportWorkflow::class)->process((int) $argv[1]);
CODE);
        $processes = [];
        try {
            foreach ([$first, $second] as $import) {
                $process = new Process([PHP_BINARY, $script, (string) $import->id, $gate], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database')]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            touch($gate);
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }
            $this->assertSame(5, $retailer->inventoryItems()->count());
            $this->assertSame(1, InventoryImportRow::whereNotNull('inventory_item_id')->count());
            if (! $same) {
                $this->assertSame(1, InventoryImportRow::where('status', 'error')->count());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            @unlink($script);
            @unlink($gate);
            foreach ([$first, $second] as $import) {
                Storage::disk('local')->delete($import->path);
            }
        }
    }
}
