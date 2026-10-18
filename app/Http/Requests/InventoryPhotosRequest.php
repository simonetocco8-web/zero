<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InventoryPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePhotos', $this->route('item'));
    }

    public function rules(): array
    {
        $file = ['file', 'max:15360', 'mimetypes:image/jpeg,image/png,image/webp'];

        return ['version' => ['required', 'string', 'size:64'], 'remove' => ['sometimes', 'array'], 'remove.*' => ['integer', 'min:0', 'distinct'], 'replacements' => ['sometimes', 'array', 'max:'.config('inventory.max_photos')], 'replacements.*' => $file, 'photos' => ['sometimes', 'array', 'max:'.config('inventory.max_photos')], 'photos.*' => $file];
    }
}
