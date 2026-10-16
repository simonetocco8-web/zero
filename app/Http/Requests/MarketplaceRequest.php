<?php

namespace App\Http\Requests;

use App\Enums\InventoryCondition;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'], 'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'brand' => ['nullable', 'string', 'max:255'], 'condition' => ['nullable', Rule::enum(InventoryCondition::class)], 'province' => ['nullable', 'string', 'max:80'],
            'min_price' => ['nullable', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/D'], 'max_price' => ['nullable', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/D'],
            'shipping_available' => ['sometimes', 'boolean'], 'pickup_available' => ['sometimes', 'boolean'], 'exchange_available' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['price_asc', 'price_desc', 'newest', 'oldest'])], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->has('min_price') && ! $validator->errors()->has('max_price') && $this->filled('min_price') && $this->filled('max_price') && BigDecimal::of(str_replace(',', '.', $this->input('min_price')))->isGreaterThan(BigDecimal::of(str_replace(',', '.', $this->input('max_price'))))) {
                $validator->errors()->add('max_price', 'Il prezzo massimo deve essere almeno pari al minimo.');
            }
        }];
    }
}
