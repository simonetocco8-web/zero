<?php

namespace App\Casts;

use App\Support\ExactInteger;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<int|null, int|string|null> */
class ExactIntegerCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : ExactInteger::parse($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : ExactInteger::parse($value);
    }
}
