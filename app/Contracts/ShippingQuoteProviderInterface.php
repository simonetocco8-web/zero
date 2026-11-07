<?php

namespace App\Contracts;

interface ShippingQuoteProviderInterface
{
    /** Internal normalized contract, NOT the Paccofacile wire schema. Amounts are integer cents. */
    public function quote(array $shipment): array;

    public function name(): string;
}
