<?php

namespace App\Actions;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveCategory
{
    public function handle(User $actor, array $data, ?Category $category = null): Category
    {
        Gate::forUser($actor)->authorize($category ? 'update' : 'create', $category ?? Category::class);

        return DB::transaction(function () use ($data, $category) {
            $category = $category ? Category::lockForUpdate()->findOrFail($category->id) : new Category;
            $category->fill($data)->save();

            return $category;
        });
    }

    public function delete(User $actor, Category $category): void
    {
        Gate::forUser($actor)->authorize('delete', $category);
        DB::transaction(function () use ($category) {
            $category = Category::lockForUpdate()->findOrFail($category->id);
            if ($category->inventoryItems()->exists() || InventoryItem::where('proposed_data->category_id', $category->id)->exists()) {
                throw ValidationException::withMessages(['category' => 'Categoria utilizzata: disattivala invece di eliminarla.']);
            }
            $category->delete();
        });
    }
}
