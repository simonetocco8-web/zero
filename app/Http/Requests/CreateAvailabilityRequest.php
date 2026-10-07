<?php

namespace App\Http\Requests;

use App\Services\PublicInventory;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;

class CreateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(PublicInventory::class)->ensureAvailable($this->route('item'));

        return true;
    }

    protected function prepareForValidation(): void
    {
        $fields = ['customer_name', 'customer_company', 'customer_email', 'customer_phone', 'message'];
        $clean = [];
        foreach ($fields as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', strip_tags($this->input($field))));
            }
        }
        if (isset($clean['customer_email'])) {
            $clean['customer_email'] = strtolower($clean['customer_email']);
        }
        if (is_string($this->input('quantity'))) {
            $clean['quantity'] = str_replace(',', '.', trim($this->input('quantity')));
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['required', 'string', 'max:255'], 'customer_company' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:40'],
            'quantity' => ['required', 'string', 'regex:/^[0-9]{1,9}(?:\.[0-9]{1,3})?$/', function ($attribute, $value, $fail) {
                if (is_string($value) && preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,3})?$/D', $value) && ! BigDecimal::of($value)->isGreaterThan(0)) {
                    $fail('Inserisci una quantità maggiore di zero.');
                }
            }],
            'message' => ['nullable', 'string', 'max:5000'], 'privacy_consent' => ['required', 'accepted'],
            'contact_website' => ['nullable', 'string', 'max:0'],
        ];
    }
}
