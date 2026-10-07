<?php

namespace App\Services\Integrations;

use App\Contracts\StoreWebhookAdapter;
use App\Data\StoreEventData;
use Illuminate\Http\Request;

final class FakeStoreWebhook implements StoreWebhookAdapter
{
    public function verifyAndNormalize(Request $request, string $provider): StoreEventData
    {
        $secret = config("store.drivers.$provider.webhook_secret");
        abort_unless(is_string($secret) && $secret !== '', 503, 'Webhook non configurato.');
        $timestamp = $request->header('X-Zero-Timestamp', '');
        $signature = $request->header('X-Zero-Signature', '');
        abort_unless(ctype_digit($timestamp) && strlen($timestamp) <= 12 && abs(time() - (int) $timestamp) <= 300, 401, 'Firma non valida.');
        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $signature), 401, 'Firma non valida.');
        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'JSON non valido.');
        }
        abort_unless(is_array($payload), 400, 'JSON non valido.');

        return StoreEventData::fromArray($provider, $payload);
    }
}
