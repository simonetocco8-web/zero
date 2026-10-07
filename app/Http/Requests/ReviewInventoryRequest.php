<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('item'));
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:published,rejected'], 'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000']];
    }
}
