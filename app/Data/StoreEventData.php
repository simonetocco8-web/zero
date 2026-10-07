<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;

final readonly class StoreEventData
{
    private function __construct(public string $provider, public string $eventId, public string $type, public FinancialEventData $financial) {}

    public static function fromArray(string $provider, array $input): self
    {
        $valid = Validator::make($input, ['event_id' => ['required', 'string', 'max:191'], 'type' => ['required', 'in:order.paid,refund'], 'data' => ['required', 'array']])->validate();
        $financial = FinancialEventData::fromArray($valid['type'], [...$valid['data'], 'provider' => $provider, 'external_event_id' => $valid['event_id']]);

        return new self($provider, $valid['event_id'], $valid['type'], $financial);
    }

    public function toArray(): array
    {
        return ['event_id' => $this->eventId, 'type' => $this->type, 'data' => $this->financial->toInput()];
    }
}
