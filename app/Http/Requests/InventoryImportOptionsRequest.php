<?php

namespace App\Http\Requests;

class InventoryImportOptionsRequest extends InventoryImportUploadRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['file'], $rules['template_id']);

        return $rules;
    }
}
