<?php

namespace App\Http\Requests;

use App\Models\Exchange;
use Illuminate\Foundation\Http\FormRequest;

class ExchangeProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('exchange') ? $this->user()->can('negotiate', $this->route('exchange')) : $this->user()->can('create', Exchange::class);
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines', []);
        $checkboxMode = $this->input('selection_mode') === 'checkboxes';
        if (is_array($lines)) {
            foreach (['a', 'b'] as $side) {
                if (! is_array($lines[$side] ?? null)) {
                    continue;
                }
                $lines[$side] = array_values(array_map(function ($line) {
                    unset($line['selected']);

                    return $line;
                }, array_filter($lines[$side], fn ($line) => is_array($line) && ((! $checkboxMode && ! array_key_exists('selected', $line)) || ($line['selected'] ?? null) === '1'))));
            }
            $this->merge(['lines' => $lines]);
        }
    }

    public function rules(): array
    {
        $counter = (bool) $this->route('exchange');

        return ['selection_mode' => ['nullable', 'in:checkboxes'], 'key' => ['required', 'uuid'], 'requested_item_id' => [$counter ? 'prohibited' : 'required', 'integer'], 'base_version_id' => [$counter ? 'required' : 'prohibited', 'integer'], 'base_summary_hash' => [$counter ? 'required' : 'prohibited', 'regex:/^[a-f0-9]{64}$/D'],
            'lines' => ['required', 'array:a,b'], 'lines.a' => ['required', 'array', 'min:1', 'max:20'], 'lines.b' => ['required', 'array', 'min:1', 'max:20'], 'lines.*.*' => ['required', 'array:inventory_item_id,quantity,value'], 'lines.*.*.inventory_item_id' => ['required', 'integer', 'min:1'], 'lines.*.*.quantity' => ['required', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,3})?$/D'], 'lines.*.*.value' => ['required', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/D'],
            'delivery' => ['required', 'array:a,b'], 'delivery.a' => ['required', 'array:mode,cost,destination,payer_side,settlement'], 'delivery.b' => ['required', 'array:mode,cost,destination,payer_side,settlement'], 'delivery.*.payer_side' => ['nullable', 'in:a,b'], 'delivery.*.settlement' => ['nullable', 'in:external,sender'], 'delivery.*.mode' => ['required', 'in:pickup,shipping'], 'delivery.*.cost' => ['required', 'regex:/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/D'], 'delivery.*.destination' => ['nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:2000'], 'total_cents' => ['prohibited'], 'difference_cents' => ['prohibited'], 'expires_at' => ['prohibited']];
    }
}
