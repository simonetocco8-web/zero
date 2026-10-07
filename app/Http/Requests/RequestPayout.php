<?php

namespace App\Http\Requests;

use App\Models\PayoutRequest;
use Illuminate\Foundation\Http\FormRequest;

class RequestPayout extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PayoutRequest::class);
    }

    public function rules(): array
    {
        return ['retailer_id' => ['prohibited'], 'amount_cents' => ['prohibited'], 'iban' => ['prohibited'], 'currency' => ['prohibited']];
    }
}
