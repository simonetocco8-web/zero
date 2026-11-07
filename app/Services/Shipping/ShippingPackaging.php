<?php

namespace App\Services\Shipping;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShippingPackaging
{
    public static function rules(bool $required = true): array
    {
        $rules = [];
        foreach (['length', 'width', 'height', 'weight', 'units_per_parcel'] as $field) {
            $rules[$field] = [$required ? 'required' : 'nullable', 'regex:/^[0-9]{1,6}(?:[.,][0-9]{1,3})?$/D'];
        }

        return $rules + [
            'dimension_unit' => ['required_with:length,width,height', 'in:cm,mm'],
            'weight_unit' => ['required_with:weight', 'in:kg,g'],
            'packing_rule' => [$required ? 'required' : 'nullable', 'in:whole_parcels,pieces_capacity'],
            'packaging_type' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function data(array $input, ?bool $required = null): array
    {
        $required ??= (bool) config('shipping.enabled');
        if (! $required && ! array_intersect(array_keys($input), ['length', 'width', 'height', 'weight', 'packing_rule', 'units_per_parcel'])) {
            return [];
        }
        Validator::make($input, self::rules($required))->validate();
        $data = [];
        foreach (['length' => 10000, 'width' => 10000, 'height' => 10000, 'weight' => 1000000, 'units_per_parcel' => 1000000000] as $field => $max) {
            $key = match ($field) {
                'weight' => 'weight_g', 'units_per_parcel' => 'units_per_parcel_milliunits', default => $field.'_mm'
            };
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') {
                $data[$key] = null;

                continue;
            }
            $factor = $field === 'units_per_parcel' ? 1000 : ($field === 'weight' ? (($input['weight_unit'] ?? '') === 'kg' ? 1000 : 1) : (($input['dimension_unit'] ?? '') === 'cm' ? 10 : 1));
            try {
                $n = BigDecimal::of(str_replace(',', '.', (string) $value))->multipliedBy($factor)->toBigInteger()->toInt();
            } catch (\Throwable) {
                throw ValidationException::withMessages([$field => 'Precisione non rappresentabile: usa millimetri e grammi interi.']);
            }
            if ($n <= 0 || $n > $max) {
                throw ValidationException::withMessages([$field => 'Valore fuori limite: massimo 1000 cm, 1000 kg, 1000000 unità per collo.']);
            }
            $data[$key] = $n;
        }
        $data['packing_rule'] = $input['packing_rule'] ?? null;
        $data['packaging_type'] = $input['packaging_type'] ?? null;
        if ($data['packing_rule'] === 'pieces_capacity' && ($input['unit'] ?? 'piece') !== 'piece') {
            throw ValidationException::withMessages(['packing_rule' => 'La capacità per pezzi richiede unità piece. Per quantità frazionarie definisci colli interi.']);
        }

        if ($data['packing_rule'] === 'pieces_capacity' && $data['units_per_parcel_milliunits'] % 1000 !== 0) {
            throw ValidationException::withMessages(['units_per_parcel' => 'La capacità per pezzi richiede un numero intero di unità.']);
        }

        return $data;
    }
}
