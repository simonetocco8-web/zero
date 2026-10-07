<?php

namespace Database\Factories;

use App\Enums\PayoutStatus;
use App\Models\PayoutRequest;
use App\Models\Retailer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PayoutRequest> */
class PayoutRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'retailer_id' => Retailer::factory(),
            'currency' => 'EUR',
            'amount_cents' => 10000,
            'status' => PayoutStatus::Pending,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => PayoutStatus::Paid, 'paid_at' => now(), 'payment_reference' => fake()->uuid()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => PayoutStatus::Rejected, 'rejected_at' => now()]);
    }
}
