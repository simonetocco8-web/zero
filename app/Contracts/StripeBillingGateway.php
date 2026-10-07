<?php

namespace App\Contracts;

use App\Data\StripeSubscriptionData;
use App\Models\BillingCheckout;

interface StripeBillingGateway
{
    public function createCheckout(BillingCheckout $checkout): array;

    public function subscription(string $id): StripeSubscriptionData;
}
