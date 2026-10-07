<?php

use App\Jobs\ProcessStorePublication;
use App\Models\StorePublication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('store:sync', function () {
    StorePublication::whereIn('status', ['pending', 'failed'])->orWhere(fn ($q) => $q->where('status', 'processing')->where('processing_at', '<=', now()->subMinutes(5)))->pluck('id')->each(fn ($id) => ProcessStorePublication::dispatchSync($id));
    $this->info('Simulazioni negozio elaborate.');
});
