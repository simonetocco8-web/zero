<?php

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Also a ValidationException for existing non-JSON form and CLI callers. */
class ShippingFailure extends ValidationException
{
    public function __construct(public readonly string $shippingCode, public readonly array $fields = [], public readonly array $diagnostics = [])
    {
        $validator = Validator::make([], []);
        foreach ($fields ?: ['shipping'] as $field) {
            $validator->errors()->add($field, ShippingErrors::catalog()[$shippingCode][0]);
        }
        parent::__construct($validator);
    }
}
