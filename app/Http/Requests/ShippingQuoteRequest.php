<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShippingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase', User::class);
    }

    public function messages(): array
    {
        return ['quantity.required_with' => 'Indica la quantità da spedire.', 'address_id.exists' => 'Scegli un indirizzo della tua rubrica.'];
    }

    public function attributes(): array
    {
        return ['quantity' => 'quantità', 'address_id' => 'indirizzo salvato'];
    }

    public function rules(): array
    {
        return [
            'item_id' => ['nullable', 'integer'], 'retailer_id' => ['nullable', 'integer'],
            'quantity' => ['required_with:item_id', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,3})?$/D'],
            'address_id' => ['nullable', 'integer', Rule::exists('customer_addresses', 'id')->where('user_id', $this->user()->id)],
            'address' => ['nullable', 'array'], 'save_address' => ['sometimes', 'boolean'],
            'price' => ['prohibited'], 'shipping_cost_cents' => ['prohibited'],
        ];
    }
}
