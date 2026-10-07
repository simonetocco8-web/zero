<?php

namespace App\Providers;

use App\Contracts\StoreGatewayInterface;
use App\Contracts\StripeBillingGateway;
use App\Services\Billing\StripeSdkGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StoreGatewayInterface::class, function () {
            $driver = config('store.driver');
            $class = config("store.drivers.$driver.gateway");
            if (! is_string($class) || ! is_subclass_of($class, StoreGatewayInterface::class)) {
                throw new \RuntimeException('Store driver is not configured.');
            }

            return $this->app->make($class);
        });
        $this->app->bind(StripeBillingGateway::class, StripeSdkGateway::class);
        $this->app->bind(StripeClient::class, function () {
            $secret = config('stripe.secret');
            if (! is_string($secret) || $secret === '') {
                throw new \RuntimeException('Stripe is not configured.');
            }
            ApiRequestor::setHttpClient((new CurlClient)->setConnectTimeout(5)->setTimeout(15));

            return new StripeClient(['api_key' => $secret, 'max_network_retries' => 1]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('pagination.accessible');
        RateLimiter::for('availability', fn (Request $request) => [Limit::perMinute(5)->by('availability-minute:'.$request->ip()), Limit::perHour(20)->by('availability-hour:'.$request->ip())]);
    }
}
