<?php

namespace App\Http\Requests;

use App\Enums\AvailabilityRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('availabilityRequest'));
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(AvailabilityRequestStatus::class)]];
    }
}
