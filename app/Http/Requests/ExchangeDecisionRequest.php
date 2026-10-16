<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('exchange'));
    }

    public function rules(): array
    {
        return ['version_id' => ['required', 'integer', 'min:1'], 'summary_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'amount_cents' => ['prohibited'], 'reference' => ['prohibited']];
    }
}
