<?php

namespace App\Contracts;

interface ShippingQuoteContextProviderInterface
{
    public function context(int $retailerId): string;
}
