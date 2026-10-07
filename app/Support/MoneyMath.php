<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class MoneyMath
{
    /** Round each line/commission to cents, half up. Never perform native overflowing multiplication. */
    public static function commissionCents(mixed $amountCents, mixed $basisPoints): int
    {
        $amount = ExactInteger::parse($amountCents);
        $rate = ExactInteger::parse($basisPoints);
        if ($amount < 0 || $rate < 0 || $rate > 10000) {
            throw new InvalidArgumentException('Invalid commission amount or basis points.');
        }

        return BigDecimal::of(BigInteger::of($amount)->multipliedBy($rate))
            ->dividedBy(10000, 0, RoundingMode::HalfUp)->toBigInteger()->toInt();
    }

    public static function lineTotalCents(mixed $unitPriceCents, mixed $quantityMilliunits): int
    {
        $price = ExactInteger::parse($unitPriceCents);
        $quantity = ExactInteger::parse($quantityMilliunits);
        if ($price < 0 || $quantity < 0) {
            throw new InvalidArgumentException('Price and quantity cannot be negative.');
        }

        return BigDecimal::of(BigInteger::of($price)->multipliedBy($quantity))
            ->dividedBy(1000, 0, RoundingMode::HalfUp)->toBigInteger()->toInt();
    }
}
