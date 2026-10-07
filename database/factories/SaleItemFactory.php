<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SaleItem> */
class SaleItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'retailer_id' => fn (array $attributes) => InventoryItem::findOrFail($attributes['inventory_item_id'])->retailer_id,
            'external_line_id' => fake()->uuid(),
            'name' => fn (array $attributes) => InventoryItem::findOrFail($attributes['inventory_item_id'])->name,
            'quantity' => '1.000',
            'currency' => fn (array $attributes) => Sale::findOrFail($attributes['sale_id'])->currency,
            'unit_price_cents' => 2500,
            'line_total_cents' => 2500,
            'commission_basis_points' => 200,
            'commission_cents' => 50,
            'discount_cents' => 0,
            'net_cents' => fn (array $attributes) => $attributes['line_total_cents'] - $attributes['commission_cents'],
        ];
    }
}
