<?php

namespace Database\Factories;

use App\Enums\WalletTransactionType;
use App\Models\PayoutRequest;
use App\Models\Retailer;
use App\Models\SaleItem;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WalletTransaction> */
class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sale_item_id' => null,
            'payout_request_id' => null,
            'retailer_id' => Retailer::factory(),
            'idempotency_key' => fake()->uuid(),
            'type' => WalletTransactionType::Adjustment,
            'currency' => 'EUR',
            'amount_cents' => 10000,
            'available_at' => now(),
        ];
    }

    public function saleCredit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => WalletTransactionType::SaleCredit,
            'sale_item_id' => $attributes['sale_item_id'] ?? SaleItem::factory(),
            'retailer_id' => fn (array $attributes) => SaleItem::findOrFail($attributes['sale_item_id'])->retailer_id,
            'currency' => fn (array $attributes) => SaleItem::findOrFail($attributes['sale_item_id'])->currency,
        ]);
    }

    public function reservation(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => WalletTransactionType::PayoutReservation,
            'amount_cents' => -10000,
            'payout_request_id' => $attributes['payout_request_id'] ?? PayoutRequest::factory(),
            'retailer_id' => fn (array $attributes) => PayoutRequest::findOrFail($attributes['payout_request_id'])->retailer_id,
            'currency' => fn (array $attributes) => PayoutRequest::findOrFail($attributes['payout_request_id'])->currency,
        ]);
    }
}
