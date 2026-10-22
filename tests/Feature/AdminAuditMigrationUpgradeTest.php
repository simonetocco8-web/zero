<?php

namespace Tests\Feature;

use App\Actions\RecordAdministrativeAction;
use App\Enums\AdministrativeAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class AdminAuditMigrationUpgradeTest extends TestCase
{
    public function test_upgrade_preserves_existing_audit_rows_indexes_triggers_and_enum_values(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires real MySQL or MariaDB through the mysql connection.');
        }
        $previousConnection = DB::getDefaultConnection();
        $configuration = config('database.connections.'.$previousConnection);
        $database = 'zero_audit_upgrade_'.bin2hex(random_bytes(8));
        $pdo = new PDO("mysql:host={$configuration['host']};port={$configuration['port']}", $configuration['username'], $configuration['password']);
        $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.audit_upgrade' => [...$configuration, 'url' => null, 'database' => $database]]);
        DB::setDefaultConnection('audit_upgrade');

        try {
            $migrationPath = 'database/migrations/2026_10_22_000001_allow_retailer_password_reset_audit.php';
            // These original migrations define audit_logs, its dependencies and triggers.
            // No intervening migration changes that audit schema before the upgrade.
            $previousPaths = array_values(array_filter(
                array_map(fn ($file) => str_replace(base_path().'/', '', $file), glob(database_path('migrations/*.php'))),
                fn ($file) => $file <= 'database/migrations/2026_10_06_000005_add_domain_constraints.php',
            ));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'audit_upgrade', '--path' => $previousPaths, '--force' => true]));

            $admin = User::factory()->create(['role' => UserRole::Admin]);
            $retailer = Retailer::factory()->create();
            foreach ([AdministrativeAction::RetailerApproved, AdministrativeAction::RetailerRejected, AdministrativeAction::RetailerSuspended] as $action) {
                app(RecordAdministrativeAction::class)->handle($admin, $action, $retailer, ['status' => 'pending'], ['status' => 'approved']);
            }
            $originalValues = ['retailer_approved', 'retailer_rejected', 'retailer_suspended', 'inventory_approved', 'inventory_rejected', 'inventory_published', 'payout_paid', 'payout_rejected'];
            $enumType = fn ($values) => "enum('".implode("','", $values)."')";
            $columnType = fn () => DB::selectOne("SHOW COLUMNS FROM audit_logs LIKE 'action'")->Type;
            $rows = fn () => DB::table('audit_logs')->orderBy('id')->get()->toJson();
            $indexes = fn () => collect(Schema::getIndexes('audit_logs'))->sortBy('name')->values()->all();
            $triggers = fn () => collect(DB::select("SHOW TRIGGERS WHERE `Table` = 'audit_logs'"))
                ->mapWithKeys(fn ($trigger) => [$trigger->Trigger => ['event' => $trigger->Event, 'timing' => $trigger->Timing, 'statement' => $trigger->Statement, 'definer' => $trigger->Definer]])
                ->sortKeys()->all();
            $this->assertSame($enumType($originalValues), $columnType());
            $originalRows = $rows();
            $originalIndexes = $indexes();
            $originalTriggers = $triggers();
            $this->assertCount(3, json_decode($originalRows));
            $this->assertNotEmpty($originalIndexes);
            $this->assertArrayHasKey('audit_logs_immutable_UPDATE', $originalTriggers);
            $this->assertArrayHasKey('audit_logs_immutable_DELETE', $originalTriggers);
            try {
                app(RecordAdministrativeAction::class)->handle($admin, AdministrativeAction::RetailerPasswordReset, $retailer, [], ['password_reset' => true]);
                $this->fail('The previous ENUM must reject the new action.');
            } catch (QueryException) {
                $this->assertSame($originalRows, $rows());
            }

            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'audit_upgrade', '--path' => [$migrationPath], '--force' => true]));
            $this->assertSame($originalRows, $rows());
            $this->assertSame($originalIndexes, $indexes());
            $this->assertSame($originalTriggers, $triggers());
            $this->assertSame($enumType([...$originalValues, 'retailer_password_reset']), $columnType());

            $resetAudit = app(RecordAdministrativeAction::class)->handle($admin, AdministrativeAction::RetailerPasswordReset, $retailer, ['password_reset' => false], ['password_reset' => true]);
            $this->assertSame(AdministrativeAction::RetailerPasswordReset, $resetAudit->action);
            $this->assertSame(4, AuditLog::count());
            $rowsAfterInsert = $rows();
            foreach (['update', 'delete'] as $operation) {
                try {
                    $query = DB::table('audit_logs')->where('id', 1);
                    $operation === 'update' ? $query->update(['subject_id' => 999]) : $query->delete();
                    $this->fail('The '.$operation.' append-only trigger must still reject writes.');
                } catch (QueryException) {
                    $this->assertSame($rowsAfterInsert, $rows());
                }
            }
        } finally {
            DB::setDefaultConnection($previousConnection);
            DB::purge('audit_upgrade');
            // Retain the newly created disposable database; never reset existing data.
        }
    }
}
