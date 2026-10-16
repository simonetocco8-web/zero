<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchase', User::class);
    }

    public function rules(): array
    {
        return ['first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->user()->id)], 'phone' => ['nullable', 'string', 'max:40'], 'role' => ['prohibited'], 'retailer_id' => ['prohibited']];
    }
}
