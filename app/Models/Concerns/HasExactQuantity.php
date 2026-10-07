<?php

namespace App\Models\Concerns;

use App\Support\ExactInteger;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Casts\Attribute;
use InvalidArgumentException;

trait HasExactQuantity
{
    /** The public quantity is an exact decimal string; storage uses integer thousandths. */
    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn () => (string) BigDecimal::of(ExactInteger::parse($this->attributes['quantity_milliunits']))->dividedBy(1000, 3),
            set: function (mixed $value): array {
                if (! is_int($value) && (! is_string($value) || ! preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/D', $value))) {
                    throw new InvalidArgumentException('Quantity must be an exact nonnegative decimal with at most three places.');
                }

                return ['quantity_milliunits' => BigDecimal::of($value)->multipliedBy(1000)->toBigInteger()->toInt()];
            },
        );
    }
}
