<?php

namespace App\Providers;

use App\Contracts\StoreGatewayInterface;
use App\Services\Store\FakeStoreGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StoreGatewayInterface::class, FakeStoreGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
