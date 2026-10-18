<?php

namespace App\Support;

use Brick\Math\BigDecimal;

final class DisplayQuantity
{
    public static function input(int|string $milliunits): string
    {
        return rtrim(rtrim((string) BigDecimal::of($milliunits)->dividedBy(1000, 3), '0'), '.');
    }

    public static function format(int|string $milliunits): string
    {
        return str_replace('.', ',', self::input($milliunits));
    }
}
