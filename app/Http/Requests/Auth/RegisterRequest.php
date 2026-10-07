<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\RetailerCompanyRequest;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return RetailerCompanyRequest::companyRules() + [
            'plan_code' => ['required', Rule::in(['free', 'pro']), Rule::exists('plans', 'code')->where('is_active', true)],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
