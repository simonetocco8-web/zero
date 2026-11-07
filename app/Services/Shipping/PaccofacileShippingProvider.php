<?php

namespace App\Services\Shipping;

use App\Contracts\ShippingQuoteProviderInterface;
use Illuminate\Validation\ValidationException;

class PaccofacileShippingProvider implements ShippingQuoteProviderInterface
{
    public function name(): string
    {
        return 'paccofacile';
    }

    public function quote(array $shipment): array
    {
        // Intentionally no HTTP call: endpoint, authentication and fiscal contract are unverified.
        throw ValidationException::withMessages(['shipping' => 'Quotazione Paccofacile non ancora attivata: contratto tecnico ufficiale e collaudo sandbox necessari. Nessun costo sostitutivo applicato.']);
    }
}
