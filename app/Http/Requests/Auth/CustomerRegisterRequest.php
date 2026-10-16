<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CustomerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'], 'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::defaults()], 'phone' => ['nullable', 'string', 'max:40']];
    }
}
