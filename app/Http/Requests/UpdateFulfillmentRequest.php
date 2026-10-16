<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFulfillmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('vendorOrder') ?? $this->route('exchange'));
    }

    public function rules(): array
    {
        return ['status' => ['required', 'in:preparing,shipped,ready_for_pickup,completed,cancelled'], 'carrier' => ['sometimes', 'nullable', 'string', 'max:120'], 'tracking_code' => ['sometimes', 'nullable', 'string', 'max:191']];
    }
}
