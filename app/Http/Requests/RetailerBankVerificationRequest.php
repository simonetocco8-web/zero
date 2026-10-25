<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetailerBankVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $retailer = $this->user()?->retailer;

        return $retailer !== null && $this->user()->can('update', $retailer);
    }

    public function rules(): array
    {
        return [
            'retry' => ['sometimes', 'boolean'],
            'retailer_id' => ['prohibited'],
            'verification_id' => ['prohibited'],
            'iban' => ['prohibited'],
        ];
    }
}
