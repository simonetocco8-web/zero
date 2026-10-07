<?php

namespace App\Http\Requests;

use App\Enums\InventoryCondition;
use App\Models\InventoryItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('item') ? $this->user()->can('update', $this->route('item')) : $this->user()->can('create', InventoryItem::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'], 'brand' => ['nullable', 'string', 'max:255'], 'category' => ['required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:191', Rule::unique('inventory_items', 'sku')->where('retailer_id', $this->user()->retailer->id)->ignore($this->route('item')?->id)], 'ean' => ['nullable', 'string', 'max:191'],
            'quantity' => ['required', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,3})?$/'],
            'zero_price' => ['required', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/'],
            'list_price' => ['nullable', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/'],
            'condition' => ['required', Rule::enum(InventoryCondition::class)], 'province' => ['required', 'string', 'max:80'],
            'pickup_available' => ['sometimes', 'boolean'], 'shipping_available' => ['sometimes', 'boolean'], 'exchange_available' => ['sometimes', 'boolean'],
            'description' => ['required', 'string', 'max:10000'], 'intent' => ['required', 'in:draft,pending'],
            'photos' => ['nullable', 'array', 'max:'.config('inventory.max_photos')], 'photos.*' => ['required', 'file', 'max:15360', 'mimetypes:image/jpeg,image/png,image/webp'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->has('quantity') && (string) $this->input('quantity') === '0') {
                $validator->errors()->add('quantity', 'Inserisci una quantità maggiore di zero.');
            }
            if (! $this->boolean('pickup_available') && ! $this->boolean('shipping_available')) {
                $validator->errors()->add('pickup_available', 'Seleziona almeno una modalità di consegna.');
            }
        }];
    }
}
