<?php

namespace App\Services\Company;

use UnexpectedValueException;

class CompanyStartMapper
{
    public function map(array $record, string $vat): CompanyLookupResult
    {
        if (! array_key_exists('companyName', $record)) {
            throw new UnexpectedValueException('invalid_response');
        }
        $returnedVat = $this->text($record['vatCode'] ?? null);
        if ($returnedVat !== null && $returnedVat !== $vat) {
            throw new UnexpectedValueException('vat_mismatch');
        }
        $address = $record['address'] ?? [];
        if (! is_array($address) || ! is_array($office = $address['registeredOffice'] ?? []) || ! is_array($region = $office['region'] ?? [])) {
            throw new UnexpectedValueException('invalid_response');
        }
        $fullAddress = $this->text($office['streetName'] ?? null);
        if ($fullAddress === null) {
            $parts = array_filter(array_map($this->text(...), [$office['toponym'] ?? null, $office['street'] ?? null, $office['streetNumber'] ?? null]), fn ($part) => $part !== null);
            $fullAddress = $parts ? implode(' ', $parts) : null;
        }
        $values = ['company_name' => $this->text($record['companyName']), 'vat_number' => $vat, 'address' => $fullAddress,
            'city' => $this->text($office['town'] ?? null), 'province' => $this->text($office['province'] ?? null, 80), 'region' => $this->text($region['description'] ?? null, 80)];
        $status = $this->text($record['activityStatus'] ?? null, 80);
        if ($status !== null && ! in_array($status, ['ATTIVA', 'REGISTRATA', 'INATTIVA', 'SOSPESA', 'IN_ISCRIZIONE', 'CESSATA'], true)) {
            throw new UnexpectedValueException('invalid_response');
        }

        return new CompanyLookupResult('found', array_filter($values, fn ($value) => $value !== null), $status);
    }

    private function text(mixed $value, int $limit = 255): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw new UnexpectedValueException('invalid_response');
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($value) > $limit) {
            throw new UnexpectedValueException('invalid_response');
        }

        return $value === '' ? null : $value;
    }
}
