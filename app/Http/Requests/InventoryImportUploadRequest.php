<?php

namespace App\Http\Requests;

use App\Models\InventoryImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryImportUploadRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('custom_delimiter')) && $this->input('custom_delimiter') !== '') {
            $this->merge(['delimiter' => $this->input('custom_delimiter')]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryImport::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv', 'max:'.config('inventory_imports.max_kilobytes'), 'mimetypes:text/plain,text/csv,text/x-csv,application/csv,application/vnd.ms-excel,text/tab-separated-values'],
            'delimiter' => ['required', 'string', 'max:4'], 'enclosure' => ['required', 'string', 'size:1'],
            'encoding' => ['required', Rule::in(['auto', 'UTF-8', 'UTF-8-BOM', 'Windows-1252', 'ISO-8859-1'])],
            'has_headers' => ['required', 'boolean'], 'number_format' => ['required', Rule::in(['italian', 'international'])],
            'template_id' => ['nullable', 'integer'], 'custom_delimiter' => ['nullable', 'string', 'size:1'],
        ];
    }
}
