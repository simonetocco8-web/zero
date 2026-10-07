<?php

namespace App\Actions;

use App\Models\AvailabilityRequest;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Services\PublicInventory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateAvailabilityRequest
{
    public function handle(InventoryItem $item, array $data): AvailabilityRequest
    {
        return DB::transaction(function () use ($item, $data) {
            Retailer::lockForUpdate()->findOrFail($item->retailer_id);
            $item = InventoryItem::lockForUpdate()->findOrFail($item->id);
            app(PublicInventory::class)->ensureAvailable($item);

            return $item->availabilityRequests()->create(Arr::only($data, ['customer_name', 'customer_company', 'customer_email', 'customer_phone', 'quantity', 'message']) + ['retailer_id' => $item->retailer_id, 'status' => 'new', 'privacy_accepted_at' => now(), 'privacy_policy_version' => config('availability.privacy_version')]);
        });
    }
}
