<?php

namespace App\Jobs;

use App\Actions\RecordAdministrativeAction;
use App\Contracts\StoreGatewayInterface;
use App\Enums\AdministrativeAction;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\StorePublication;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessStorePublication implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $publicationId) {}

    public function handle(StoreGatewayInterface $gateway): void
    {
        $publication = DB::transaction(function () {
            $record = StorePublication::lockForUpdate()->findOrFail($this->publicationId);
            if ($record->status === 'succeeded' || ($record->status === 'processing' && $record->processing_at > now()->subMinutes(5))) {
                return null;
            }
            $record->update(['status' => 'processing', 'processing_at' => now(), 'error_code' => null]);

            return $record;
        });
        if (! $publication) {
            return;
        }
        try {
            $item = $publication->inventoryItem;
            if ($item->retailer->status->value !== 'approved') {
                throw new \RuntimeException('Retailer is not approved.');
            }
            $key = 'inventory-'.$item->id;
            $result = ($publication->operation === 'create' || ! $item->external_product_id) ? $gateway->createProduct($key, $publication->payload, $publication->id) : $gateway->updateProduct($item->external_product_id, $publication->payload, $publication->id);
            $published = $gateway->publishProduct($result['external_product_id'], $publication->id);
            DB::transaction(function () use ($publication, $result, $published) {
                Retailer::lockForUpdate()->findOrFail($publication->inventoryItem->retailer_id);
                $item = InventoryItem::lockForUpdate()->findOrFail($publication->inventory_item_id);
                $item->update(['external_provider' => $result['provider'], 'external_product_id' => $result['external_product_id']]);
                app(RecordAdministrativeAction::class)->handle(User::findOrFail($publication->requested_by), AdministrativeAction::InventoryPublished, $item, [], ['status' => $item->status->value, 'external_provider' => $result['provider'], 'external_product_id' => $result['external_product_id']]);
                $publication->update(['status' => 'succeeded', 'result' => ['product' => $result, 'publication' => $published], 'completed_at' => now(), 'error_code' => null]);
            });
        } catch (\Throwable $e) {
            $publication->update(['status' => 'failed', 'error_code' => 'store_sync_failed']);
        }
    }
}
