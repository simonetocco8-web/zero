<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase', User::class);
    }

    public function rules(): array
    {
        return ['quantity' => ['sometimes', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,3})?$/D'], 'delivery_mode' => ['sometimes', 'in:pickup,shipping']];
    }
}
