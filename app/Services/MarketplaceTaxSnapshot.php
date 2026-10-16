<?php

namespace App\Services;

use App\Support\MoneyMath;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/** No default VAT rate. Supplied configuration is descriptive, not an approved fiscal policy. */
class MarketplaceTaxSnapshot
{
    public function goods(int $amount, ?string $treatment, string $description): array
    {
        $rate = config('marketplace.tax_rates_basis_points.'.$treatment);
        $base = null;
        $tax = null;
        $gross = null;
        if ($treatment === 'not_applicable') {
            $rate = 0;
            $base = $amount;
            $tax = 0;
            $gross = $amount;
        } elseif (is_int($rate) && $rate >= 0 && $rate <= 10000 && in_array($treatment, ['included', 'excluded'], true)) {
            if ($treatment === 'excluded') {
                $base = $amount;
                $tax = MoneyMath::commissionCents($base, $rate);
                $gross = BigInteger::of($base)->plus($tax)->toInt();
            } else {
                $gross = $amount;
                $base = BigDecimal::of(BigInteger::of($gross)->multipliedBy(10000))->dividedBy(10000 + $rate, 0, RoundingMode::HalfUp)->toBigInteger()->toInt();
                $tax = $gross - $base;
            }
        }

        return ['treatment' => $treatment, 'description' => $description, 'rate_basis_points' => $rate, 'taxable_cents' => $base, 'tax_cents' => $tax, 'gross_cents' => $gross, 'provisional' => $gross === null];
    }
}
