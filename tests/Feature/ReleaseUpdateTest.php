<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReleaseUpdateTest extends TestCase
{
    private string $directory;

    private string $database;

    private array $environment;

    private ?PDO $admin = null;

    private array $legacy = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Production updater requires an isolated MySQL database.');
        }
        $this->directory = sys_get_temp_dir().'/zero-update-'.bin2hex(random_bytes(6));
        $this->database = 'zero_update_'.bin2hex(random_bytes(6));
        $db = config('database.connections.mysql');
        $this->admin = new PDO('mysql:host='.$db['host'].';port='.$db['port'], $db['username'], $db['password']);
        $this->admin->exec('CREATE DATABASE `'.$this->database.'`');
        foreach (['bootstrap/cache', 'storage/app', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'scripts', 'deployment', 'database/migrations'] as $path) {
            File::ensureDirectoryExists($this->directory.'/'.$path);
        }
        foreach (['app', 'config', 'resources', 'routes', 'vendor'] as $path) {
            symlink(base_path($path), $this->directory.'/'.$path);
        }
        foreach (['bootstrap/app.php', 'artisan', 'composer.json', 'scripts/update-release.php'] as $path) {
            copy(base_path($path), $this->directory.'/'.$path);
        }
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (basename($path) < '2026_10_13_000001_add_marketplace_foundations.php') {
                copy($path, $this->directory.'/database/migrations/'.basename($path));
            }
        }
        $this->environment = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'https://example.test', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $this->database, 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'file', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null'];
        $this->command(['artisan', 'migrate', '--force', '--no-interaction'])->mustRun();
        $this->admin->exec('CREATE TABLE `'.$this->database.'`.audit_sentinel (value VARCHAR(40))');
        $this->admin->exec("INSERT INTO `{$this->database}`.audit_sentinel VALUES ('preserve-existing-data')");
        $this->admin->exec('USE `'.$this->database.'`');
        $this->admin->exec("INSERT INTO users (name,email,password,role) VALUES ('Storico','legacy-audit@example.test','hash','retailer')");
        $this->admin->exec("INSERT INTO retailers (user_id,company_name,vat_number,city,status,approved_at) VALUES (1,'Storica','12345678901','Roma','approved','2026-10-16 00:00:00')");
        $this->admin->exec("INSERT INTO inventory_items (retailer_id,name,category,quantity_milliunits,zero_price_cents,province) VALUES (1,'Storico','Categoria storica',1250,12345,'Roma')");
        $this->admin->exec("INSERT INTO sales (provider,external_order_id,status,total_cents) VALUES ('fake','legacy','paid',100)");
        $this->admin->exec("INSERT INTO sale_items (sale_id,inventory_item_id,retailer_id,external_line_id,name,quantity_milliunits,unit_price_cents,line_total_cents,commission_basis_points,commission_cents,net_cents) VALUES (1,1,1,'legacy-line','Storico',1000,100,100,200,2,98)");
        $this->admin->exec("INSERT INTO wallet_transactions (retailer_id,sale_item_id,idempotency_key,type,amount_cents) VALUES (1,1,'legacy-credit','sale_credit',100)");
        foreach (['users', 'retailers', 'inventory_items', 'sales', 'sale_items', 'wallet_transactions'] as $table) {
            $columns = array_column($this->admin->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $query = 'SELECT `'.implode('`,`', $columns).'` FROM `'.$table.'` ORDER BY id';
            $this->legacy[$table] = ['query' => $query, 'rows' => $this->admin->query($query)->fetchAll(PDO::FETCH_ASSOC)];
        }

        foreach (glob(database_path('migrations/*.php')) as $path) {
            copy($path, $this->directory.'/database/migrations/'.basename($path));
        }
        $this->manifest();
    }

    protected function tearDown(): void
    {
        if ($this->admin) {
            // New isolated DB retained: no reset/drop/rollback of any database.
            foreach (['app', 'config', 'resources', 'routes', 'vendor'] as $path) {
                @unlink($this->directory.'/'.$path);
            }
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function command(array $arguments): Process
    {
        return new Process([PHP_BINARY, ...$arguments], $this->directory, $this->environment, timeout: 60);
    }

    private function manifest(array $overrides = []): void
    {
        $hashes = [];
        foreach (glob($this->directory.'/database/migrations/*.php') as $path) {
            $hashes[pathinfo($path, PATHINFO_FILENAME)] = hash_file('sha256', $path);
        }
        file_put_contents($this->directory.'/deployment/release.json', json_encode(array_replace(['version' => 1, 'commit' => str_repeat('a', 40), 'source_dirty' => false, 'migrations' => $hashes, 'approved_additive' => json_decode(file_get_contents(base_path('deployment/additive-migrations.json')), true)], $overrides), JSON_THROW_ON_ERROR));
    }

    private function update(bool $run = false): Process
    {
        $p = $this->command(['scripts/update-release.php', '--commit='.str_repeat('a', 40), '--backup=verified-backup-20261016', ...($run ? ['--run'] : [])]);
        $p->run();
        $this->assertStringNotContainsString($this->environment['APP_KEY'], $p->getOutput().$p->getErrorOutput());
        $this->assertStringNotContainsString('Stack trace', $p->getOutput().$p->getErrorOutput());
        $this->assertSame('preserve-existing-data', $this->admin->query('SELECT value FROM `'.$this->database.'`.audit_sentinel')->fetchColumn());

        foreach ($this->legacy as $snapshot) {
            $this->assertSame($snapshot['rows'], $this->admin->query($snapshot['query'])->fetchAll(PDO::FETCH_ASSOC));
        }

        return $p;
    }

    public function test_additive_update_preserves_data_caches_records_release_and_refuses_replay(): void
    {
        $this->assertTrue($this->update()->isSuccessful());
        $this->command(['artisan', 'down'])->mustRun();
        $this->assertTrue($this->update(true)->isSuccessful());
        $receipt = json_decode(file_get_contents($this->directory.'/storage/app/release-updates/'.str_repeat('a', 40).'.json'), true);
        $this->assertSame('completed', $receipt['status']);
        foreach (['config.php', 'routes-v7.php', 'events.php'] as $cache) {
            $this->assertFileExists($this->directory.'/bootstrap/cache/'.$cache);
        }
        $this->assertNotEmpty(glob($this->directory.'/storage/framework/views/*.php'));
        $this->assertFalse($this->update(true)->isSuccessful());
    }

    public function test_non_additive_or_unreviewed_migrations_and_modified_files_fail_closed(): void
    {
        $this->manifest(['approved_additive' => []]);
        $this->assertFalse($this->update()->isSuccessful());
        $this->manifest();
        file_put_contents($this->directory.'/database/migrations/2026_10_16_000004_add_late_capture_reconciliation.php', "\n// modified after packaging", FILE_APPEND);
        $this->assertFalse($this->update()->isSuccessful());
        $this->assertFileDoesNotExist($this->directory.'/storage/app/release-updates/'.str_repeat('a', 40).'.json');
    }

    public function test_dirty_release_or_absent_backup_and_maintenance_are_rejected(): void
    {
        $this->manifest(['source_dirty' => true]);
        $this->assertFalse($this->update()->isSuccessful());
        $this->manifest();
        $missing = $this->command(['scripts/update-release.php', '--commit='.str_repeat('a', 40), '--run']);
        $missing->run();
        $this->assertFalse($missing->isSuccessful());
        $this->assertFalse($this->update(true)->isSuccessful());
        $this->assertFileDoesNotExist($this->directory.'/storage/app/release-updates/'.str_repeat('a', 40).'.json');
    }

    public function test_exclusive_lock_blocks_concurrent_execution_without_consuming_release(): void
    {
        $path = $this->directory.'/storage/app/release-updates';
        File::ensureDirectoryExists($path);
        $lock = fopen($path.'/update.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertFalse($this->update()->isSuccessful());
            $this->assertFileDoesNotExist($path.'/'.str_repeat('a', 40).'.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertTrue($this->update()->isSuccessful());
    }

    public function test_failed_attempt_is_recorded_redacted_and_cannot_be_retried(): void
    {
        $name = '2026_10_16_999999_add_failure_fixture';
        file_put_contents($this->directory.'/database/migrations/'.$name.'.php', '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void { throw new RuntimeException("private-sensitive-test-value"); } public function down(): void {} };');
        $this->manifest(['approved_additive' => [...json_decode(file_get_contents(base_path('deployment/additive-migrations.json')), true), $name]]);
        $this->command(['artisan', 'down'])->mustRun();
        $result = $this->update(true);
        $this->assertFalse($result->isSuccessful());
        $this->assertStringNotContainsString('private-sensitive-test-value', $result->getOutput().$result->getErrorOutput());
        $receipt = json_decode(file_get_contents($this->directory.'/storage/app/release-updates/'.str_repeat('a', 40).'.json'), true);
        $this->assertSame('failed', $receipt['status']);
        $this->assertSame('migrate', $receipt['failed_phase']);
        $this->assertFalse($this->update(true)->isSuccessful());
    }
}
