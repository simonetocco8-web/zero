<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('fulfil', $this->route('vendorOrder'));
    }

    public function rules(): array
    {
        return ['status' => ['required', 'in:preparing,shipped,ready_for_pickup,completed'], 'carrier' => ['nullable', 'string', 'max:120'], 'tracking_reference' => ['nullable', 'string', 'max:191'], 'total_cents' => ['prohibited'], 'retailer_id' => ['prohibited']];
    }
}
