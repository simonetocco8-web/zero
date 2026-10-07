<?php

namespace App\Http\Requests;

use App\Rules\Iban;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetailerCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->retailer && $this->user()->can('update', $this->user()->retailer);
    }

    public function rules(): array
    {
        return self::companyRules($this->user()?->retailer?->id) + ['iban' => ['nullable', 'string', 'max:34', new Iban]];
    }

    public static function companyRules(?int $id = null): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'vat_number' => ['required', 'regex:/^[0-9]{11}$/', Rule::unique('retailers', 'vat_number')->ignore($id)],
            'city' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:80'], 'region' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Blank means preserve the existing bank account; removal must be explicit.
        $this->merge(['iban' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('iban')))]);
    }
}
