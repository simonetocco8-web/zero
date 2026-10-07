<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

class DisplayMoney
{
    public static function euros(int $cents, string $currency = 'EUR'): string
    {
        $n = BigInteger::of($cents);
        [$whole,$fraction] = $n->abs()->quotientAndRemainder(100);

        return ($cents < 0 ? '−' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', (string) $whole).','.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT).' '.($currency === 'EUR' ? '€' : $currency);
    }

    public static function percent(int $bps): string
    {
        return rtrim(rtrim((string) BigDecimal::of($bps)->dividedBy(100, 2), '0'), '.').'%';
    }
}
