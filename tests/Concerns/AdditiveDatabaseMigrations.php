<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

trait AdditiveDatabaseMigrations
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        $config = config('database.connections.mysql');
        $db = 'zero_test_'.bin2hex(random_bytes(8));
        $pdo = new \PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
        $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.mysql.database' => $db]);
        DB::purge('mysql');
        $this->artisan('migrate', ['--force' => true]);
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
        // This newly created disposable DB is retained; no existing DB is reset or dropped.
    }
}
