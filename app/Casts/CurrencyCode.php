<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<string, string> */
class CurrencyCode implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_string($value) || ! preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new InvalidArgumentException('A three-letter uppercase currency code is required.');
        }

        return $value;
    }
}
