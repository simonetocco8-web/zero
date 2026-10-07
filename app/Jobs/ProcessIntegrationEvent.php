<?php

namespace App\Jobs;

use App\Data\StoreEventData;
use App\Models\IntegrationEvent;
use App\Services\Billing\SyncStripeSubscription;
use App\Services\SaleAccounting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

class ProcessIntegrationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        $event = DB::transaction(function () {
            $event = IntegrationEvent::whereKey($this->eventId)->lockForUpdate()->firstOrFail();
            if ($event->status->value === 'processed' || $event->attempts >= config('integrations.max_attempts') || ($event->status->value === 'processing' && $event->processing_at > now()->subSeconds(config('integrations.lease_seconds')))) {
                return null;
            }
            $event->update(['status' => 'processing', 'processing_at' => now(), 'attempts' => $event->attempts + 1, 'error_code' => null]);

            return $event;
        }, 5);
        if (! $event) {
            return;
        }
        try {
            if ($event->provider === 'stripe' && str_starts_with($event->event_type, 'stripe.')) {
                app(SyncStripeSubscription::class)->handle($event);
            } elseif (str_starts_with($event->event_type, 'store.')) {
                $dto = StoreEventData::fromArray($event->provider, $event->payload);
                $service = app(SaleAccounting::class);
                if ($dto->type === 'order.paid') {
                    $service->recordSale($dto->financial);
                } else {
                    $service->refund($dto->financial);
                }
            } else {
                throw new \RuntimeException('Unsupported integration type.');
            }
            $event->update(['status' => 'processed', 'processed_at' => now(), 'processing_at' => null, 'next_attempt_at' => null, 'error_code' => null]);
        } catch (\Throwable $e) {
            $code = $e instanceof ApiErrorException ? 'stripe_api_error' : 'integration_processing_failed';
            $delay = $this->backoff()[min(max($event->attempts - 1, 0), 3)];
            $event->update(['status' => 'failed', 'error_code' => $code, 'processing_at' => null, 'next_attempt_at' => now()->addSeconds($delay)]);
            Log::warning('Integration processing failed', ['integration_event_id' => $event->id, 'provider' => $event->provider, 'error_code' => $code]);
            // Queue/failed_jobs must never persist SDK exception messages or raw payloads.
            throw new \RuntimeException('Integration processing failed for event '.$event->id.'.');
        }
    }
}
