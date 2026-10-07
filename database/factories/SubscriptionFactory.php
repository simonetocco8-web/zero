<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'retailer_id' => Retailer::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Pending,
            'billing_interval' => BillingInterval::Monthly,
            'currency' => 'EUR',
            'price_cents' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Active, 'starts_at' => now()]);
    }
}
