<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminInventoryController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\AdminRetailerController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\RetailerController;
use App\Http\Middleware\EnsureApprovedRetailer;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('products/{item}/request', [AvailabilityController::class, 'create'])->name('availability.create');
Route::post('products/{item}/request', [AvailabilityController::class, 'store'])->middleware('throttle:availability')->name('availability.store');
Route::view('privacy', 'availability.privacy')->name('privacy');
Route::get('/dashboard', DashboardController::class)->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'can:accessRetailerArea,'.User::class])->prefix('retailer')->name('retailer.')->group(function (): void {
    Route::get('dashboard', [RetailerController::class, 'dashboard'])->name('dashboard');
    Route::middleware(EnsureApprovedRetailer::class)->group(function () {
        Route::get('stock', [InventoryController::class, 'index'])->name('stock');
        Route::get('sell', [InventoryController::class, 'create'])->name('sell');
        Route::post('stock', [InventoryController::class, 'store'])->middleware('throttle:20,1')->name('stock.store');
        Route::get('stock/{item}', [InventoryController::class, 'show'])->name('stock.show');
        Route::get('stock/{item}/edit', [InventoryController::class, 'edit'])->name('stock.edit');
        Route::put('stock/{item}', [InventoryController::class, 'update'])->middleware('throttle:20,1')->name('stock.update');
        Route::patch('stock/{item}/archive', [InventoryController::class, 'archive'])->name('stock.archive');
    });
    Route::get('requests', [AvailabilityController::class, 'index'])->middleware(EnsureApprovedRetailer::class)->name('requests');
    Route::patch('requests/{availabilityRequest}', [AvailabilityController::class, 'update'])->middleware([EnsureApprovedRetailer::class, 'throttle:30,1'])->name('requests.update');
    Route::get('credit', [CreditController::class, 'index'])->middleware(EnsureApprovedRetailer::class)->name('credit');
    Route::post('credit/payout', [CreditController::class, 'store'])->middleware([EnsureApprovedRetailer::class, 'throttle:5,1'])->name('credit.payout');
    Route::get('profile', [RetailerController::class, 'profile'])->name('profile');
    Route::put('profile', [RetailerController::class, 'update'])->middleware('throttle:20,1')->name('profile.update');
});

Route::middleware(['auth', 'can:accessAdministration,'.User::class])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('dashboard', AdminDashboardController::class)->name('dashboard');
    Route::get('retailers', [AdminRetailerController::class, 'index'])->name('retailers');
    Route::patch('retailers/{retailer}/review', [AdminRetailerController::class, 'review'])->middleware('throttle:20,1')->name('retailers.review');
    Route::get('stock', [AdminInventoryController::class, 'index'])->name('stock');
    Route::patch('stock/{item}/review', [AdminInventoryController::class, 'review'])->middleware('throttle:20,1')->name('stock.review');
    Route::get('payouts', [AdminPayoutController::class, 'index'])->name('payouts');
    Route::patch('payouts/{payout}/review', [AdminPayoutController::class, 'review'])->middleware('throttle:20,1')->name('payouts.review');
    Route::view('settings', 'pages.placeholder', ['area' => 'admin', 'title' => 'Impostazioni', 'description' => 'Le preferenze della piattaforma.', 'icon' => 'settings'])->name('settings');
});

Route::get('inventory/{item}/photos/{position}', [InventoryController::class, 'image'])->middleware('auth')->whereNumber('position')->name('inventory.image');

if (app()->environment('local')) {
    Route::view('/design-system', 'pages.design-system')->middleware('auth')->name('design-system');
}

require __DIR__.'/auth.php';
