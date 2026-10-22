<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\RetailerCompanyRequest;
use App\Models\User;
use App\Support\RegistrationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['vat_number.unique' => RegistrationMessages::DUPLICATE_VAT];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $failed = $validator->failed()['vat_number'] ?? [];
            if (isset($failed['Unique']) && ! isset($failed['Regex'])) {
                $validator->errors()->add('company_exists', RegistrationMessages::DUPLICATE_VAT);
            }
        }];
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
