<?php

use App\Http\Middleware\PrivatePageHeaders;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->group(base_path('routes/integrations.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [PrivatePageHeaders::class]);
        $middleware->trimStrings(except: ['password', 'password_confirmation']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception) {
            if (! app()->environment('production')) {
                return null;
            }
            // SQL and driver messages may contain contact details or reset tokens.
            Log::error('Database operation failed.', [
                'sql_state' => (string) $exception->getCode(),
                'driver_code' => $exception->errorInfo[1] ?? null,
                'connection' => $exception->getConnectionName(),
            ]);

            return false;
        });
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'iban', 'token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('webhooks/*') || $request->expectsJson(),
        );
    })->create();
