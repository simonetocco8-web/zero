<?php

namespace Database\Factories;

use App\Enums\IntegrationEventStatus;
use App\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntegrationEvent> */
class IntegrationEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'fake-store',
            'external_event_id' => fake()->uuid(),
            'event_type' => 'order.paid',
            'payload_hash' => hash('sha256', '{}'),
            'status' => IntegrationEventStatus::Pending,
            'attempts' => 0,
            'received_at' => now(),
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['status' => IntegrationEventStatus::Processed, 'processed_at' => now()]);
    }
}
