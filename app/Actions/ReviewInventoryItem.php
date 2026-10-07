<?php

namespace App\Actions;

use App\Enums\AdministrativeAction;
use App\Jobs\ProcessStorePublication;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\StorePublication;
use App\Models\User;
use App\Services\InventoryRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewInventoryItem
{
    public function handle(User $actor, InventoryItem $item, string $decision, ?string $reason): void
    {
        Gate::forUser($actor)->authorize('review', $item);
        $publicationId = null;
        DB::transaction(function () use ($actor, $item, $decision, $reason, &$publicationId) {
            $retailer = Retailer::lockForUpdate()->findOrFail($item->retailer_id);
            $item = InventoryItem::lockForUpdate()->findOrFail($item->id);
            if (! in_array($item->status->value, ['pending', 'change_pending'])) {
                throw ValidationException::withMessages(['decision' => 'Questa giacenza non è in attesa di verifica.']);
            }
            $before = ['status' => $item->status->value];
            if ($decision === 'published') {
                if ($retailer->status->value !== 'approved') {
                    throw ValidationException::withMessages(['decision' => 'Il rivenditore non è approvato.']);
                }
                $data = $item->proposed_data ?? $item->getAttributes();
                app(InventoryRules::class)->validate($retailer, $data, $item);
                if ($item->proposed_data) {
                    $item->fill($item->proposed_data);
                    $item->images()->delete();
                    foreach ($item->proposed_images ?? [] as $position => $photo) {
                        $item->images()->create($photo + ['position' => $position]);
                    }
                }
                $publication = StorePublication::create(['inventory_item_id' => $item->id, 'requested_by' => $actor->id, 'operation' => $item->external_product_id ? 'update' : 'create', 'payload' => Arr::only($data, ['name', 'brand', 'category', 'sku', 'ean', 'quantity_milliunits', 'unit', 'currency', 'list_price_cents', 'zero_price_cents', 'condition', 'province', 'pickup_available', 'shipping_available', 'exchange_available', 'description']) + ['availability_request_url' => route('availability.create', $item), 'images' => $item->images()->get(['disk', 'path', 'position'])->toArray()]]);
                $publicationId = $publication->id;
                $item->status = 'published';
                $item->published_at ??= now();
                $item->rejection_reason = null;
            } else {
                $item->status = $item->published_at ? 'published' : 'rejected';
                $item->rejection_reason = $reason;
            }
            $item->proposed_data = null;
            $item->proposed_images = null;
            $item->reviewed_by = $actor->id;
            $item->save();
            app(RecordAdministrativeAction::class)->handle($actor, $decision === 'published' ? AdministrativeAction::InventoryApproved : AdministrativeAction::InventoryRejected, $item, $before, ['status' => $item->status->value]);
        });
        // Fake remains synchronous; explicitly configured real drivers always use the durable queue after commit.
        if ($publicationId) {
            if (config('store.driver') === 'fake') {
                ProcessStorePublication::dispatchSync($publicationId);
            } else {
                ProcessStorePublication::dispatch($publicationId)->onConnection(config('integrations.queue_connection'))->onQueue(config('integrations.queue'))->afterCommit();
            }
        }
    }
}
