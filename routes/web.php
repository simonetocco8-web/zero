<?php

use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminInventoryController;
use App\Http\Controllers\AdminMarketplaceController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\AdminRetailerController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\CustomerAreaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MarketplaceConnectController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\MarketplaceNotificationController;
use App\Http\Controllers\MarketplaceOrderController;
use App\Http\Controllers\RetailerController;
use App\Http\Controllers\RetailerSalesController;
use App\Http\Middleware\EnsureApprovedRetailer;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', [MarketplaceController::class, 'home'])->name('home');
Route::get('marketplace', [MarketplaceController::class, 'index'])->name('marketplace.index');
Route::get('marketplace/categoria/{category:slug}', [MarketplaceController::class, 'index'])->name('marketplace.category');
Route::get('marketplace/prodotto/{item}', [MarketplaceController::class, 'show'])->whereNumber('item')->name('marketplace.show');
Route::get('marketplace/prodotto/{item}/immagini/{position}', [MarketplaceController::class, 'image'])->whereNumber('item')->whereNumber('position')->name('marketplace.image');
Route::get('marketplace/prodotto/{item}/acquista', [MarketplaceController::class, 'purchase'])->middleware(['auth', 'can:purchase,'.User::class])->whereNumber('item')->name('marketplace.purchase');
Route::get('marketplace/prodotto/{item}/scambio', [MarketplaceController::class, 'exchange'])->middleware('auth')->whereNumber('item')->name('marketplace.exchange');
Route::get('products/{item}/request', [AvailabilityController::class, 'create'])->name('availability.create');
Route::post('products/{item}/request', [AvailabilityController::class, 'store'])->middleware('throttle:availability')->name('availability.store');
Route::view('privacy', 'availability.privacy')->name('privacy');
Route::get('/dashboard', DashboardController::class)->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'can:accessRetailerArea,'.User::class])->prefix('retailer')->name('retailer.')->group(function (): void {
    Route::get('pagamenti/connect', [MarketplaceConnectController::class, 'show'])->name('connect.show');
    Route::post('pagamenti/connect', [MarketplaceConnectController::class, 'begin'])->middleware('throttle:10,1')->name('connect.begin');
    Route::get('pagamenti/connect/verifica', [MarketplaceConnectController::class, 'refresh'])->middleware('throttle:10,1')->name('connect.refresh');
    Route::get('dashboard', [RetailerController::class, 'dashboard'])->name('dashboard');
    Route::get('vendite', [RetailerSalesController::class, 'overview'])->name('sales.overview');
    Route::get('vendite/ordini', [RetailerSalesController::class, 'index'])->name('sales.index');
    Route::get('vendite/ordini/{vendorOrder}', [RetailerSalesController::class, 'show'])->name('sales.show');
    Route::patch('vendite/ordini/{vendorOrder}', [RetailerSalesController::class, 'update'])->middleware('throttle:30,1')->name('sales.update');
    Route::get('conguagli', [RetailerSalesController::class, 'differences'])->name('differences.index');

    Route::prefix('scambi')->name('exchanges.')->group(function () {
        Route::get('/', [ExchangeController::class, 'index'])->name('index');
        Route::get('crea/{item}', [ExchangeController::class, 'create'])->name('create');
        Route::post('/', [ExchangeController::class, 'store'])->middleware('throttle:20,1')->name('store');
        Route::get('{exchange}', [ExchangeController::class, 'show'])->name('show');
        Route::get('{exchange}/controproposta', [ExchangeController::class, 'edit'])->name('edit');
        Route::post('{exchange}/controproposta', [ExchangeController::class, 'counter'])->middleware('throttle:20,1')->name('counter');
        foreach (['accept', 'reject', 'cancel', 'complete', 'simulate'] as $action) {
            Route::post('{exchange}/'.$action, [ExchangeController::class, $action])->middleware('throttle:20,1')->name($action);
        }
    });
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
    Route::get('billing', [BillingController::class, 'index'])->name('billing');
    Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:5,1')->name('billing.checkout');
    Route::get('billing/return', [BillingController::class, 'returned'])->name('billing.return');
    Route::get('profile', [RetailerController::class, 'profile'])->name('profile');
    Route::put('profile', [RetailerController::class, 'update'])->middleware('throttle:20,1')->name('profile.update');
});

Route::middleware(['auth', 'can:accessAdministration,'.User::class])->prefix('admin')->name('admin.')->group(function (): void {
    Route::resource('categories', CategoryController::class)->except('show')->middleware('throttle:60,1');
    Route::get('marketplace', [AdminMarketplaceController::class, 'overview'])->name('marketplace.overview');
    Route::get('marketplace/ordini/{order}', [AdminMarketplaceController::class, 'order'])->name('marketplace.orders.show');
    Route::get('marketplace/sottoordini/{vendorOrder}', [AdminMarketplaceController::class, 'vendor'])->name('marketplace.vendors.show');
    Route::patch('marketplace/sottoordini/{vendorOrder}', [AdminMarketplaceController::class, 'update'])->middleware('throttle:30,1')->name('marketplace.vendors.update');
    Route::post('marketplace/sottoordini/{vendorOrder}/rimborso', [AdminMarketplaceController::class, 'refund'])->middleware('throttle:20,1')->name('marketplace.refunds.store');
    Route::get('marketplace/scambi/{exchange}', [AdminMarketplaceController::class, 'exchange'])->name('marketplace.exchanges.show');
    Route::get('marketplace/prenotazioni/{reservation}', [AdminMarketplaceController::class, 'reservation'])->name('marketplace.reservations.show');
    Route::get('marketplace/{resource}', [AdminMarketplaceController::class, 'index'])->whereIn('resource', ['orders', 'vendors', 'exchanges', 'payments', 'refunds', 'commissions', 'reservations'])->name('marketplace.index');

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

Route::get('customer/dashboard', [CustomerAreaController::class, 'index'])->middleware(['auth', 'can:purchase,'.User::class])->name('customer.dashboard');

Route::middleware(['auth', 'can:purchase,'.User::class])->group(function () {
    Route::get('customer/pagamenti', [CustomerAreaController::class, 'payments'])->name('customer.payments');
    Route::get('customer/consegne', [CustomerAreaController::class, 'deliveries'])->name('customer.deliveries.index');
    Route::get('customer/consegne/{vendorOrder}', [CustomerAreaController::class, 'delivery'])->name('customer.deliveries.show');
    Route::post('customer/consegne/{vendorOrder}/ricevuto', [CustomerAreaController::class, 'received'])->middleware('throttle:30,1')->name('customer.deliveries.received');
    Route::get('customer/indirizzi', [CustomerAreaController::class, 'addresses'])->name('customer.addresses.index');
    Route::get('customer/indirizzi/nuovo', [CustomerAreaController::class, 'addressForm'])->name('customer.addresses.create');
    Route::post('customer/indirizzi', [CustomerAreaController::class, 'saveAddress'])->middleware('throttle:20,1')->name('customer.addresses.store');
    Route::get('customer/indirizzi/{address}/modifica', [CustomerAreaController::class, 'addressForm'])->name('customer.addresses.edit');
    Route::put('customer/indirizzi/{address}', [CustomerAreaController::class, 'saveAddress'])->middleware('throttle:20,1')->name('customer.addresses.update');
    Route::delete('customer/indirizzi/{address}', [CustomerAreaController::class, 'deleteAddress'])->middleware('throttle:20,1')->name('customer.addresses.destroy');
    Route::get('customer/profilo', [CustomerAreaController::class, 'profile'])->name('customer.profile');
    Route::put('customer/profilo', [CustomerAreaController::class, 'updateProfile'])->middleware('throttle:20,1')->name('customer.profile.update');

    Route::get('carrello', [CartController::class, 'index'])->name('cart.index');
    Route::post('carrello/prodotti/{item}', [CartController::class, 'store'])->middleware('throttle:60,1')->name('cart.store');
    Route::put('carrello/righe/{cartItem}', [CartController::class, 'update'])->name('cart.update');
    Route::delete('carrello/righe/{cartItem}', [CartController::class, 'destroy'])->name('cart.destroy');
    Route::get('checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('checkout/riepilogo', [CheckoutController::class, 'review'])->name('checkout.review');
    Route::post('checkout/conferma', [CheckoutController::class, 'confirm'])->middleware('throttle:10,1')->name('checkout.confirm');
    Route::get('ordini', [MarketplaceOrderController::class, 'index'])->name('orders.index');
    Route::get('ordini/{order}', [MarketplaceOrderController::class, 'show'])->name('orders.show');
    Route::post('ordini/{order}/annulla', [MarketplaceOrderController::class, 'cancel'])->middleware('throttle:10,1')->name('orders.cancel');
    Route::post('ordini/{order}/simulazione', [MarketplaceOrderController::class, 'retry'])->middleware('throttle:10,1')->name('orders.payment-retry');
});
require __DIR__.'/auth.php';

Route::middleware('auth')->group(function () {
    Route::get('notifiche', [MarketplaceNotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifiche/preferenze', [MarketplaceNotificationController::class, 'preferences'])->middleware('throttle:20,1')->name('notifications.preferences');
    Route::patch('notifiche/{notification}/letta', [MarketplaceNotificationController::class, 'read'])->whereUuid('notification')->middleware('throttle:60,1')->name('notifications.read');
});
