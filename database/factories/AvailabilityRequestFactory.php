<?php

namespace Database\Factories;

use App\Enums\AvailabilityRequestStatus;
use App\Models\AvailabilityRequest;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AvailabilityRequest> */
class AvailabilityRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'retailer_id' => fn (array $attributes) => InventoryItem::findOrFail($attributes['inventory_item_id'])->retailer_id,
            'quantity_milliunits' => 1000, 'privacy_accepted_at' => now(), 'privacy_policy_version' => 'availability-v1', 'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'message' => 'Richiesta di disponibilità di esempio.',
            'status' => AvailabilityRequestStatus::New,
        ];
    }

    public function contacted(): static
    {
        return $this->state(fn () => ['status' => AvailabilityRequestStatus::Contacted, 'contacted_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => AvailabilityRequestStatus::Closed, 'closed_at' => now()]);
    }
}
