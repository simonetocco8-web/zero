<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewRetailerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('retailer'));
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:approved,rejected,suspended'], 'reason' => ['required_if:decision,rejected,suspended', 'nullable', 'string', 'max:1000']];
    }
}
