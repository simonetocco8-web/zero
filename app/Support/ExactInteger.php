<?php

namespace App\Support;

use Brick\Math\BigInteger;
use Brick\Math\Exception\IntegerOverflowException;
use InvalidArgumentException;

final class ExactInteger
{
    public static function parse(mixed $value): int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^-?[0-9]+$/D', $value))) {
            throw new InvalidArgumentException('An integer or integer string is required; floats are forbidden.');
        }

        try {
            return BigInteger::of($value)->toInt();
        } catch (IntegerOverflowException $exception) {
            throw new InvalidArgumentException('Integer is outside the supported signed 64-bit range.', previous: $exception);
        }
    }
}
