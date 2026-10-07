<?php

namespace Database\Factories;

use App\Enums\InventoryCondition;
use App\Enums\InventoryStatus;
use App\Models\InventoryItem;
use App\Models\Retailer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryItem> */
class InventoryItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'retailer_id' => Retailer::factory(),
            'name' => fake()->words(3, true),
            'brand' => fake()->company(),
            'category' => 'materiali_edili',
            'sku' => fake()->uuid(),
            'quantity' => '1.000',
            'currency' => 'EUR',
            'zero_price_cents' => 2500,
            'condition' => InventoryCondition::New,
            'province' => 'MI',
            'pickup_available' => true,
            'shipping_available' => false,
            'exchange_available' => false,
            'status' => InventoryStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => InventoryStatus::Published, 'published_at' => now()]);
    }
}
