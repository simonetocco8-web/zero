<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;

final readonly class StoreEventData
{
    private function __construct(public string $provider, public string $eventId, public string $type, public ?FinancialEventData $financial, public ?array $exchange = null) {}

    public static function fromArray(string $provider, array $input): self
    {
        $valid = Validator::make($input, ['event_id' => ['required', 'string', 'max:191'], 'type' => ['required', 'in:order.pending,order.paid,refund,exchange.payment_confirmed'], 'data' => ['required', 'array']])->validate();
        if ($valid['type'] === 'exchange.payment_confirmed') {
            $data = Validator::make($valid['data'], ['external_exchange_id' => ['required', 'string', 'max:191'], 'payment_reference' => ['required', 'string', 'max:191']])->validate();

            return new self($provider, $valid['event_id'], $valid['type'], null, $data);
        }
        $financial = FinancialEventData::fromArray($valid['type'] === 'order.pending' ? 'order.paid' : $valid['type'], [...$valid['data'], 'provider' => $provider, 'external_event_id' => $valid['event_id']]);

        return new self($provider, $valid['event_id'], $valid['type'], $financial);
    }

    public function toArray(): array
    {
        return ['event_id' => $this->eventId, 'type' => $this->type, 'data' => $this->exchange ?? $this->financial->toInput()];
    }
}
