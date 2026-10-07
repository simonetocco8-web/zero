<?php

namespace App\Http\Controllers;

use App\Contracts\StoreWebhookAdapter;
use App\Services\Integrations\WebhookInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class WebhookController extends Controller
{
    public function stripe(Request $request, WebhookInbox $inbox)
    {
        $this->checkSize($request);
        $secret = config('stripe.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503, 'Webhook non configurato.');
        try {
            $event = Webhook::constructEvent($request->getContent(), $request->header('Stripe-Signature', ''), $secret, config('stripe.webhook_tolerance'));
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            abort(400, 'Firma o payload non validi.');
        }
        abort_unless(is_string($event->id) && is_string($event->type) && is_int($event->created), 422, 'Evento non valido.');
        Validator::make($event->toArray(), ['id' => ['required', 'string', 'max:191'], 'type' => ['required', 'string', 'max:110'], 'created' => ['required', 'integer', 'min:1'], 'data.object' => ['required', 'array']])->validate();
        $payload = ['id' => $event->id, 'type' => $event->type, 'created' => $event->created, 'data' => ['object' => Arr::only($event->data->object->toArray(), ['id', 'object', 'subscription', 'parent', 'metadata', 'client_reference_id', 'mode', 'payment_status'])]];
        $inbox->receive('stripe', $event->id, 'stripe.'.$event->type, $payload, hash('sha256', $request->getContent()));

        return response()->json(['received' => true], 202);
    }

    public function store(Request $request, string $driver, WebhookInbox $inbox)
    {
        $this->checkSize($request);
        $class = config("store.drivers.$driver.webhook");
        abort_unless(is_string($class) && is_subclass_of($class, StoreWebhookAdapter::class), 404);
        // Billing namespace cannot be supplied by a shop adapter.
        abort_if($driver === 'stripe' || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $driver), 404);
        $event = app($class)->verifyAndNormalize($request, $driver);
        $inbox->receive($driver, $event->eventId, 'store.'.$event->type, $event->toArray(), hash('sha256', json_encode($event->toArray(), JSON_THROW_ON_ERROR)));

        return response()->json(['received' => true], 202);
    }

    private function checkSize(Request $request): void
    {
        abort_if(strlen($request->getContent()) > config('integrations.max_body_bytes'), 413);
    }
}
