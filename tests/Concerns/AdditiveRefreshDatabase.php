<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait AdditiveRefreshDatabase
{
    use RefreshDatabase;

    protected function migrateDatabases()
    {
        $this->artisan('migrate', ['--force' => true]);
    }
}
