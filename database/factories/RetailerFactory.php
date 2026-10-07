<?php

namespace Database\Factories;

use App\Enums\RetailerStatus;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Retailer> */
class RetailerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_name' => fake()->company(),
            'vat_number' => fake()->unique()->numerify('###########'),
            'city' => fake()->city(),
            'province' => 'MI',
            'status' => RetailerStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => RetailerStatus::Approved, 'approved_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => RetailerStatus::Rejected, 'rejected_at' => now()]);
    }
}
