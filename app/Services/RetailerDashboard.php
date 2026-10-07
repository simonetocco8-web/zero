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
        $productCount = 0;
        foreach ((clone $items)->select(['quantity_milliunits', 'zero_price_cents'])->cursor() as $item) {
            $value = $value->plus($item->inventoryValueCents());
            $productCount++;
        }

        return ['retailer' => $retailer, 'subscription' => $subscription, 'requested' => $requested, 'plan' => $subscription?->plan, 'productCount' => $productCount, 'inventoryValue' => $value->toInt(), 'credit' => app(WalletBalance::class)->availableCents($retailer), 'newRequestCount' => $retailer->availabilityRequests()->where('status', 'new')->count(), 'recentRequests' => $retailer->availabilityRequests()->with('inventoryItem')->latest()->limit(5)->get()];
    }
}
