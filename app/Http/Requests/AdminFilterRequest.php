<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accessAdministration', User::class);
    }

    public function rules(): array
    {
        $statuses = match ($this->route()->getName()) {
            'admin.retailers' => ['pending', 'approved', 'rejected', 'suspended'],'admin.stock' => ['draft', 'pending', 'published', 'change_pending', 'rejected', 'archived'],default => ['pending', 'paid', 'rejected']
        };

        return ['status' => ['nullable', Rule::in($statuses)], 'company' => ['nullable', 'string', 'max:255'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')], 'page' => ['nullable', 'integer', 'min:1']];
    }
}
