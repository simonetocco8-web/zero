<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class CartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase', User::class) && (! $this->route('cartItem') || $this->user()->can('update', $this->route('cartItem')));
    }

    public function rules(): array
    {
        return ['quantity' => ['required', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,3})?$/D'], 'delivery_mode' => ['required', 'in:pickup,shipping'], 'price' => ['prohibited'], 'unit_price_cents' => ['prohibited'], 'total_cents' => ['prohibited']];
    }
}
