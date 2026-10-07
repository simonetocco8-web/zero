<?php

namespace App\Services\Integrations;

use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookInbox
{
    public function receive(string $provider, string $eventId, string $type, array $payload, string $hash): IntegrationEvent
    {
        abort_unless(strlen($eventId) <= 191 && $eventId !== '' && strlen($type) <= 120, 422, 'Identificativo non valido.');
        $event = DB::transaction(function () use ($provider, $eventId, $type, $payload, $hash) {
            $new = new IntegrationEvent(['provider' => $provider, 'external_event_id' => $eventId, 'event_type' => $type, 'payload' => $payload, 'payload_hash' => $hash, 'received_at' => now()]);
            $new->setCreatedAt(now());
            $new->setUpdatedAt(now());
            IntegrationEvent::upsert([$new->getAttributes()], ['provider', 'external_event_id'], ['external_event_id']);
            $event = IntegrationEvent::where('provider', $provider)->where('external_event_id', $eventId)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($event->payload_hash, $hash), 409, 'Evento già ricevuto con contenuto differente.');

            return $event;
        }, 5);
        if ($event->status->value !== 'processed' && $event->attempts < config('integrations.max_attempts')) {
            try {
                app(IntegrationQueue::class)->dispatch($event->id);
            } catch (\Throwable) {
                // Durable inbox survives a queue outage. The scheduled recovery command retries dispatch.
                $event->update(['error_code' => 'integration_dispatch_failed', 'next_attempt_at' => now()->addSeconds(10)]);
                Log::warning('Integration dispatch deferred', ['integration_event_id' => $event->id]);
            }
        }

        return $event;
    }
}
