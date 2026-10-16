<?php

namespace App\Support;

use Brick\Math\BigInteger;

final class ProportionalAllocation
{
    /** Largest remainders, stable key tie-break; shares always sum to the original cents. */
    public static function split(int $cents, array $weights): array
    {
        if ($cents < 0 || array_filter($weights, fn ($v) => ! is_int($v) || $v < 0)) {
            throw new \InvalidArgumentException('Invalid allocation.');
        }
        ksort($weights);
        $total = BigInteger::sum(...array_values($weights));
        if ($total->isZero()) {
            if ($cents !== 0) {
                throw new \InvalidArgumentException('Cannot allocate without weights.');
            }

            return array_fill_keys(array_keys($weights), 0);
        }
        $shares = $remainders = [];
        foreach ($weights as $id => $weight) {
            [$share, $remainder] = BigInteger::of($cents)->multipliedBy($weight)->quotientAndRemainder($total);
            $shares[$id] = $share->toInt();
            $remainders[$id] = $remainder->toInt();
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $cents - array_sum($shares);
        foreach ($remainders as $id => $_) {
            if ($left-- <= 0) {
                break;
            }
            $shares[$id]++;
        }

        return $shares;
    }
}
