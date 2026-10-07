<?php

namespace App\Http\Requests;

use App\Models\Subscription;
use Illuminate\Foundation\Http\FormRequest;

class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('checkout', Subscription::class);
    }

    public function rules(): array
    {
        return ['billing_interval' => ['required', 'in:monthly,yearly'], 'retailer_id' => ['prohibited'], 'price_id' => ['prohibited'], 'price_cents' => ['prohibited']];
    }
}
