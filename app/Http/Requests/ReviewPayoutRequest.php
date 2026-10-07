<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReviewPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('payout'));
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:paid,rejected'], 'reason' => ['required_if:decision,rejected', 'nullable', 'string', 'max:1000'], 'payment_reference' => ['required_if:decision,paid', 'nullable', 'string', 'max:255']];
    }
}
