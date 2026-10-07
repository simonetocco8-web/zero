<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:120,1')->prefix('webhooks')->name('webhooks.')->group(function () {
    Route::post('stripe', [WebhookController::class, 'stripe'])->name('stripe');
    Route::post('store/{driver}', [WebhookController::class, 'store'])->where('driver', '[a-zA-Z0-9_-]+')->name('store');
});
