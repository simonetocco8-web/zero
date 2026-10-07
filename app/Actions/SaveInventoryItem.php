<?php

namespace App\Actions;

use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use App\Services\InventoryPhotos;
use App\Services\InventoryRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SaveInventoryItem
{
    public function __construct(private InventoryRules $rules, private InventoryPhotos $photos) {}

    public function handle(User $user, array $input, array $files = [], ?InventoryItem $item = null): InventoryItem
    {
        $stored = [];
        $removed = [];
        try {
            foreach ($files as $file) {
                $stored[] = $this->photos->store($file);
            }
            $result = DB::transaction(function () use ($user, $input, $item, $stored, &$removed) {
                $retailer = Retailer::lockForUpdate()->findOrFail($user->retailer->id);
                Gate::forUser($user)->authorize('operate', $retailer);
                $current = $item ? InventoryItem::lockForUpdate()->findOrFail($item->id) : null;
                Gate::forUser($user)->authorize($current ? 'update' : 'create', $current ?? InventoryItem::class);
                $data = $this->rules->data($input);
                $this->rules->validate($retailer, $data, $current);
                $published = $current && in_array($current->status->value, ['published', 'change_pending']);
                $images = $stored ?: ($published ? ($current->proposed_images ?? $current->images->map->only(['disk', 'path'])->all()) : []);
                if ($published) {
                    if ($stored) {
                        $removed = $current->proposed_images ?? [];
                    }
                    $current->proposed_data = $data;
                    $current->proposed_images = $images;
                    $current->status = 'change_pending';
                    $current->submitted_at = now();
                    $current->save();
                } else {
                    $current ??= new InventoryItem;
                    $current->fill($data);
                    $current->retailer_id = $retailer->id;
                    $current->status = $input['intent'];
                    $current->submitted_at = $input['intent'] === 'pending' ? now() : null;
                    $current->rejection_reason = null;
                    $current->save();
                    if ($stored) {
                        $removed = $current->images->map->only(['disk', 'path'])->all();
                        $current->images()->delete();
                        foreach ($stored as $position => $photo) {
                            $current->images()->create($photo + ['position' => $position]);
                        }
                    }
                }

                return $current;
            });
        } catch (\Throwable $e) {
            $this->photos->delete($stored);
            throw $e;
        }
        // Cleanup happens after commit; failure must never delete committed new photos.
        $live = $result->images()->pluck('path')->all();
        $this->photos->delete(array_values(array_filter($removed, fn ($photo) => ! in_array($photo['path'], $live))));

        return $result;
    }

    public function archive(User $user, InventoryItem $item): void
    {
        DB::transaction(function () use ($user, $item) {
            $retailer = Retailer::lockForUpdate()->findOrFail($item->retailer_id);
            Gate::forUser($user)->authorize('operate', $retailer);
            $item = InventoryItem::lockForUpdate()->findOrFail($item->id);
            Gate::forUser($user)->authorize('archive', $item);
            $item->update(['status' => 'archived']);
        });
    }
}
