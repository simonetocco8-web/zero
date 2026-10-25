<?php

namespace App\Http\Requests\Auth;

use App\Services\Company\CompanyRegistrationLookup;
use App\Support\RegistrationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CompanyLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('vat_number'))) {
            $this->merge(['vat_number' => CompanyRegistrationLookup::normalize($this->input('vat_number'))]);
        }
        $this->session()->forget(CompanyRegistrationLookup::SESSION_KEY);
        $this->redirectRoute = 'register';
    }

    public function rules(): array
    {
        return ['vat_number' => ['bail', 'required', 'string', 'regex:/^[0-9]{11}$/', 'unique:retailers,vat_number']];
    }

    public function messages(): array
    {
        return ['vat_number.unique' => RegistrationMessages::DUPLICATE_VAT];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (isset($validator->failed()['vat_number']['Unique'])) {
                $validator->errors()->add('company_exists', RegistrationMessages::DUPLICATE_VAT);
            }
        }];
    }
}
