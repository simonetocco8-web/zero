<?php

use App\Http\Controllers\DashboardController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('/dashboard', DashboardController::class)->middleware('auth')->name('dashboard');

Route::middleware(['auth', 'can:accessRetailerArea,'.User::class])->prefix('retailer')->name('retailer.')->group(function (): void {
    Route::view('dashboard', 'pages.placeholder', ['area' => 'retailer', 'title' => 'Dashboard', 'description' => 'Ritrova tutto, in un unico posto.', 'icon' => 'grid'])->name('dashboard');
    Route::view('stock', 'pages.placeholder', ['area' => 'retailer', 'title' => 'Le mie giacenze', 'description' => 'Le tue giacenze, sempre sotto controllo.', 'icon' => 'box'])->name('stock');
    Route::view('sell', 'pages.placeholder', ['area' => 'retailer', 'title' => 'Metti in vendita', 'description' => 'Dai una nuova opportunità ai tuoi materiali.', 'icon' => 'plus'])->name('sell');
    Route::view('requests', 'pages.placeholder', ['area' => 'retailer', 'title' => 'Richieste', 'description' => 'Un filo diretto con chi cerca i tuoi materiali.', 'icon' => 'chat'])->name('requests');
    Route::view('credit', 'pages.placeholder', ['area' => 'retailer', 'title' => 'Credito', 'description' => 'Una visione chiara del tuo credito.', 'icon' => 'wallet'])->name('credit');
    Route::view('profile', 'pages.placeholder', ['area' => 'retailer', 'title' => 'Profilo', 'description' => 'Il tuo spazio su ZeroMagazzino.', 'icon' => 'user'])->name('profile');
});

Route::middleware(['auth', 'can:accessAdministration,'.User::class])->prefix('admin')->name('admin.')->group(function (): void {
    Route::view('dashboard', 'pages.placeholder', ['area' => 'admin', 'title' => 'Dashboard', 'description' => 'La piattaforma, a colpo d’occhio.', 'icon' => 'grid'])->name('dashboard');
    Route::view('retailers', 'pages.placeholder', ['area' => 'admin', 'title' => 'Rivenditori', 'description' => 'Una rete di aziende da seguire.', 'icon' => 'users'])->name('retailers');
    Route::view('stock', 'pages.placeholder', ['area' => 'admin', 'title' => 'Giacenze', 'description' => 'Qualità e controllo prima della pubblicazione.', 'icon' => 'box'])->name('stock');
    Route::view('payouts', 'pages.placeholder', ['area' => 'admin', 'title' => 'Bonifici', 'description' => 'Segui le richieste di pagamento.', 'icon' => 'wallet'])->name('payouts');
    Route::view('settings', 'pages.placeholder', ['area' => 'admin', 'title' => 'Impostazioni', 'description' => 'Le preferenze della piattaforma.', 'icon' => 'settings'])->name('settings');
});

if (app()->environment('local')) {
    Route::view('/design-system', 'pages.design-system')->middleware('auth')->name('design-system');
}

require __DIR__.'/auth.php';
