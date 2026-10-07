<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sale> */
class SaleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => 'fake-store',
            'external_order_id' => fake()->uuid(),
            'status' => SaleStatus::Pending,
            'currency' => 'EUR',
            'total_cents' => 2500,
            'ordered_at' => now(),
        ];
    }
}
