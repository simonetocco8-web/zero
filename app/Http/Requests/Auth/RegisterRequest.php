<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\RetailerCompanyRequest;
use App\Models\User;
use App\Services\Company\CompanyRegistrationLookup;
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
            $context = $this->session()->get(CompanyRegistrationLookup::SESSION_KEY);
            if (! CompanyRegistrationLookup::validContext($context) || ($context['ready'] ?? false) !== true) {
                $validator->errors()->add('registration_step', 'Completa prima il passaggio Partita IVA.');
            } elseif ($this->input('vat_number') !== $context['vat_number']) {
                $validator->errors()->add('vat_number', 'Per cambiare Partita IVA torna al primo passaggio.');
            }
            $failed = $validator->failed()['vat_number'] ?? [];
            if (isset($failed['Unique']) && ! isset($failed['Regex'])) {
                $validator->errors()->add('company_exists', RegistrationMessages::DUPLICATE_VAT);
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('vat_number'))) {
            $this->merge(['vat_number' => CompanyRegistrationLookup::normalize($this->input('vat_number'))]);
        }
        $context = $this->session()->get(CompanyRegistrationLookup::SESSION_KEY);
        $this->redirectRoute = CompanyRegistrationLookup::validContext($context) && ($context['ready'] ?? false) === true ? 'register.details' : 'register';
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
