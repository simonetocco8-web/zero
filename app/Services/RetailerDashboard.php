<?php

namespace App\Services;

use App\Models\Retailer;
use Brick\Math\BigInteger;

class RetailerDashboard
{
    public function data(Retailer $retailer): array
    {
        $subscription = $retailer->activeSubscription()->with('plan')->first();
        $requested = $retailer->subscriptions()->with('plan')->latest('id')->first();
        $items = $retailer->inventoryItems()->where('status', '!=', 'archived')->where('currency', 'EUR');
        $value = BigInteger::zero();
        foreach ((clone $items)->cursor() as $item) {
            $value = $value->plus($item->inventoryValueCents());
        }

        return ['retailer' => $retailer, 'subscription' => $subscription, 'requested' => $requested, 'plan' => $subscription?->plan, 'productCount' => (clone $items)->count(), 'inventoryValue' => $value->toInt(), 'credit' => app(WalletBalance::class)->availableCents($retailer), 'newRequestCount' => $retailer->availabilityRequests()->where('status', 'new')->count(), 'recentRequests' => $retailer->availabilityRequests()->with('inventoryItem')->latest()->limit(5)->get()];
    }
}
