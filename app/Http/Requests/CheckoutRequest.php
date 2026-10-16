<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase', User::class);
    }

    public function rules(): array
    {
        return ['use_credit' => ['sometimes', 'boolean'], 'use_bonus' => ['sometimes', 'boolean'], 'modes' => ['required', 'array', 'max:100'], 'modes.*' => ['required', 'in:pickup,shipping'], 'address_id' => ['nullable', 'integer', Rule::exists('customer_addresses', 'id')->where('user_id', $this->user()->id)], 'address' => ['nullable', 'array'], 'price' => ['prohibited'], 'total_cents' => ['prohibited'], 'tax_cents' => ['prohibited']];
    }
}
