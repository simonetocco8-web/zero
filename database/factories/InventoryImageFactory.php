<?php

namespace Database\Factories;

use App\Models\InventoryImage;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InventoryImage> */
class InventoryImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'disk' => 'local',
            'path' => 'inventory/'.fake()->uuid().'.jpg',
            'position' => 0,
        ];
    }
}
