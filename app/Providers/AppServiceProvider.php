<?php

namespace App\Providers;

use App\Contracts\StoreGatewayInterface;
use App\Services\Store\FakeStoreGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('availability', fn (Request $request) => [Limit::perMinute(5)->by('availability-minute:'.$request->ip()), Limit::perHour(20)->by('availability-hour:'.$request->ip())]);
    }
}
