<?php

use App\Jobs\ProcessStorePublication;
use App\Models\BillingSubscription;
use App\Models\IntegrationEvent;
use App\Models\StorePublication;
use App\Services\Integrations\IntegrationQueue;
use App\Services\Integrations\WebhookInbox;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('store:sync', function () {
    StorePublication::whereIn('status', ['pending', 'failed'])->orWhere(fn ($q) => $q->where('status', 'processing')->where('processing_at', '<=', now()->subMinutes(5)))->pluck('id')->each(function ($id) {
        if (config('store.driver') === 'fake') {
            ProcessStorePublication::dispatchSync($id);
        } else {
            ProcessStorePublication::dispatch($id)->onConnection(config('integrations.queue_connection'))->onQueue(config('integrations.queue'));
        }
    });
    $this->info('Operazioni negozio elaborate o accodate.');
});

Artisan::command('integrations:retry {--event= : ID locale opzionale} {--force : Riavvia anche eventi falliti dopo il limite}', function () {
    $query = IntegrationEvent::where(fn ($q) => $q->where('event_type', 'like', 'stripe.%')->orWhere('event_type', 'like', 'store.%'))->where('status', '!=', 'processed');
    if ($this->option('event')) {
        $query->whereKey($this->option('event'));
    }
    if (! $this->option('force')) {
        $query->where('attempts', '<', config('integrations.max_attempts'))->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }
    $query->where(fn ($q) => $q->where('status', '!=', 'processing')->orWhereNull('processing_at')->orWhere('processing_at', '<=', now()->subSeconds(config('integrations.lease_seconds'))));
    $count = 0;
    $query->orderBy('id')->limit(1000)->get()->each(function ($event) use (&$count) {
        if ($this->option('force')) {
            $event->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null]);
        }
        app(IntegrationQueue::class)->dispatch($event->id);
        $count++;
    });
    $this->info("Eventi accodati: $count");
});

Artisan::command('billing:sync', function () {
    $count = 0;
    BillingSubscription::whereNotIn('status', ['canceled', 'incomplete_expired'])->orderBy('id')->chunkById(100, function ($records) use (&$count) {
        foreach ($records as $record) {
            $created = now()->getTimestamp();
            $id = 'reconcile:'.$record->id.':'.intdiv($created, 3600);
            $existing = IntegrationEvent::where('provider', 'stripe')->where('external_event_id', $id)->first();
            if ($existing) {
                continue;
            }
            $payload = ['id' => $id, 'type' => 'customer.subscription.updated', 'created' => $created, 'data' => ['object' => ['id' => $record->external_subscription_id]]];
            app(WebhookInbox::class)->receive('stripe', $id, 'stripe.customer.subscription.updated', $payload, hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
            $count++;
        }
    });
    $this->info("Riconciliazioni accodate: $count");
});

Schedule::command('store:sync')->everyMinute()->withoutOverlapping();
Schedule::command('integrations:retry')->everyMinute()->withoutOverlapping();
Schedule::command('billing:sync')->hourly()->withoutOverlapping();
