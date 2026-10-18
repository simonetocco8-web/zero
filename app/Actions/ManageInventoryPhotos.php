<?php

namespace App\Actions;

use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use App\Services\InventoryPhotos;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageInventoryPhotos
{
    public function references(InventoryItem $item): array
    {
        return $item->proposed_images ?? $item->images()->orderBy('position')->get()->map->only(['disk', 'path'])->all();
    }

    public function version(InventoryItem $item): string
    {
        return hash('sha256', json_encode($this->references($item), JSON_THROW_ON_ERROR));
    }

    public function handle(User $actor, InventoryItem $item, array $data, array $replacements, array $additions): void
    {
        Gate::forUser($actor)->authorize('managePhotos', $item);
        $photos = app(InventoryPhotos::class);
        $stored = [];
        try {
            $replacementRefs = [];
            foreach ($replacements as $index => $file) {
                $replacementRefs[$index] = $stored[] = $photos->store($file);
            }
            $newRefs = [];
            foreach ($additions as $file) {
                $newRefs[] = $stored[] = $photos->store($file);
            }
            DB::transaction(function () use ($actor, $item, $data, $replacementRefs, $newRefs) {
                $retailer = Retailer::lockForUpdate()->findOrFail($item->retailer_id);
                if ($actor->role->value !== 'admin') {
                    Gate::forUser($actor)->authorize('operate', $retailer);
                }
                $current = InventoryItem::lockForUpdate()->findOrFail($item->id);
                Gate::forUser($actor)->authorize('managePhotos', $current);
                $refs = $this->references($current);
                if (! hash_equals($this->version($current), $data['version'])) {
                    throw ValidationException::withMessages(['photos' => 'Le foto sono cambiate. Ricarica la pagina prima di modificarle.']);
                }
                foreach (array_merge($data['remove'] ?? [], array_keys($replacementRefs)) as $index) {
                    if (! array_key_exists($index, $refs)) {
                        throw ValidationException::withMessages(['photos' => 'Foto selezionata non valida.']);
                    }
                }
                foreach ($data['remove'] ?? [] as $index) {
                    if (isset($replacementRefs[$index])) {
                        throw ValidationException::withMessages(['photos' => 'Scegli se eliminare oppure sostituire la foto.']);
                    }
                    unset($refs[$index]);
                }
                $refs = array_merge(array_values(array_replace($refs, $replacementRefs)), $newRefs);
                if (count($refs) > config('inventory.max_photos')) {
                    throw ValidationException::withMessages(['photos' => 'Troppe foto: massimo '.config('inventory.max_photos').'.']);
                }
                if (in_array($current->status->value, ['published', 'change_pending'], true)) {
                    $current->proposed_data ??= Arr::except(Arr::only($current->getAttributes(), $current->getFillable()), ['retailer_id', 'status', 'reviewed_by', 'rejection_reason', 'published_at', 'external_provider', 'external_product_id']);
                    $current->proposed_stock_version ??= $current->stock_version;
                    $current->proposed_images = $refs;
                    $current->status = 'change_pending';
                    $current->submitted_at = now();
                    $current->save();
                } else {
                    $current->images()->delete();
                    foreach ($refs as $position => $photo) {
                        $current->images()->create($photo + ['position' => $position]);
                    }
                }
                // Superseded files stay private for publication/history references.
            }, 5);
        } catch (\Throwable $e) {
            $photos->delete($stored);
            throw $e;
        }
    }
}
