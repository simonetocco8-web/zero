<?php

namespace App\Models;

use App\Casts\CurrencyCode;
use App\Casts\ExactIntegerCast;
use App\Support\MoneyMath;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $attributes = ['currency' => 'EUR', 'is_active' => true];

    protected $fillable = ['code', 'name', 'currency', 'monthly_price_cents', 'annual_price_cents', 'max_items', 'max_inventory_value_cents', 'exchange_available', 'commission_basis_points', 'is_active'];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'monthly_price_cents' => ExactIntegerCast::class,
            'annual_price_cents' => ExactIntegerCast::class,
            'max_items' => ExactIntegerCast::class,
            'max_inventory_value_cents' => ExactIntegerCast::class,
            'exchange_available' => 'boolean',
            'commission_basis_points' => ExactIntegerCast::class,
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function commissionCents(mixed $amountCents): int
    {
        return MoneyMath::commissionCents($amountCents, $this->commission_basis_points);
    }
}
