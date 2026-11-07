<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingQuoteProviderInterface;

class FakeShippingProvider implements ShippingQuoteProviderInterface
{
    public function name(): string
    {
        return 'fake';
    }

    public function quote(array $shipment): array
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Fake shipping is only available in automated tests.');
        }
        $base = 500 + count($shipment['parcels']) * 100;

        return [
            ['carrier' => 'TEST Courier A', 'service' => 'TEST Standard', 'original_cents' => $base, 'final_cents' => $base, 'currency' => 'EUR', 'tax_treatment' => 'included', 'tax_rate_basis_points' => 2200, 'conditions' => 'Tariffa fittizia, solo test'],
            ['carrier' => 'TEST Courier B', 'service' => 'TEST Express', 'original_cents' => $base + 250, 'final_cents' => $base + 250, 'currency' => 'EUR', 'tax_treatment' => 'included', 'tax_rate_basis_points' => 2200, 'conditions' => 'Tariffa fittizia, solo test'],
        ];
    }
}
