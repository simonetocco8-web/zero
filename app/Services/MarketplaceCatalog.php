<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\StockReservation;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceCatalog
{
    public function __construct(private PublicInventory $public) {}

    public function query(array $filters = [], ?Category $category = null): Builder
    {
        $query = $this->public->query()->select(['inventory_items.id', 'retailer_id', 'category_id', 'name', 'description', 'brand', 'sku', 'ean', 'category', 'condition', 'province', 'quantity_milliunits', 'unit', 'currency', 'zero_price_cents', 'list_price_cents', 'shipping_cost_cents', 'delivery_notes', 'vat_treatment', 'pickup_available', 'shipping_available', 'exchange_available', 'status', 'published_at'])->with(['retailer:id,company_name,city,province,status', 'categoryRecord:id,name,slug,is_active', 'images' => fn ($q) => $q->select(['id', 'inventory_item_id', 'disk', 'path', 'position', 'alt_text'])->orderBy('position')]);
        $query->selectSub(StockReservation::selectRaw('COALESCE(SUM(quantity_milliunits), 0)')->whereColumn('inventory_item_id', 'inventory_items.id')->where('status', 'active')->where('expires_at', '>', now()), 'reserved_milliunits');
        if ($category) {
            $query->where('category_id', $category->id);
        } elseif (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(function ($q) use ($term) {
                foreach (['name', 'brand', 'sku', 'ean'] as $column) {
                    $q->orWhereRaw("$column LIKE ? ESCAPE '!'", [$term]);
                }
            });
        }
        foreach (['brand', 'condition', 'province'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['shipping_available', 'pickup_available', 'exchange_available'] as $flag) {
            if (! empty($filters[$flag])) {
                $query->where($flag, true);
            }
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $field => $op) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where('zero_price_cents', $op, BigDecimal::of(str_replace(',', '.', $filters[$field]))->multipliedBy(100)->toBigInteger()->toInt());
            }
        }

        return match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('zero_price_cents')->orderBy('id'),
            'price_desc' => $query->orderByDesc('zero_price_cents')->orderByDesc('id'),
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id')
        };
    }

    public function homeData(): array
    {
        $categories = $this->categories();
        $sections = collect([
            ['title' => 'Materiali per costruire', 'names' => ['edilizia', 'edilizia pesante']],
            ['title' => 'Attrezzatura per lavorare', 'names' => ['ferramenta', 'elettroutensili']],
        ])->map(function ($section) use ($categories) {
            $selected = $categories->filter(fn ($category) => in_array(mb_strtolower($category->name), $section['names'], true) || in_array($category->slug, array_map(fn ($name) => str_replace(' ', '-', $name), $section['names']), true));

            return ['title' => $section['title'], 'items' => $selected->isEmpty() ? collect() : $this->query()->whereIn('category_id', $selected->pluck('id'))->limit(4)->get()];
        });

        return [
            'categories' => $categories,
            'items' => $this->query()->limit(8)->get(),
            'featured' => config('marketplace.featured_item_ids') ? $this->query()->whereIn('id', config('marketplace.featured_item_ids'))->limit(8)->get() : collect(),
            'deals' => $this->query()->whereColumn('list_price_cents', '>', 'zero_price_cents')->limit(8)->get(),
            'exchangeItems' => $this->query(['exchange_available' => true])->limit(4)->get(),
            'brands' => $this->public->query()->whereNotNull('brand')->whereRaw("TRIM(brand) <> ''")->distinct()->orderBy('brand')->limit(15)->pluck('brand'),
            'categorySections' => $sections,
        ];
    }

    public function categories()
    {
        return Category::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'slug']);
    }

    public function facets(?Category $category = null): array
    {
        $query = $this->public->query();
        if ($category) {
            $query->where('category_id', $category->id);
        }

        return ['brands' => (clone $query)->whereNotNull('brand')->where('brand', '!=', '')->distinct()->orderBy('brand')->pluck('brand'), 'provinces' => (clone $query)->distinct()->orderBy('province')->pluck('province')];
    }

    public function canExchange(?User $viewer, InventoryItem $item): bool
    {
        if (! $viewer || $item->status->value !== 'published' || $item->available_quantity_milliunits <= 0 || ! $item->exchange_available || ! $this->public->isAvailable($item) || ! $viewer->can('proposeExchange', User::class) || $viewer->retailer?->id === $item->retailer_id) {
            return false;
        }

        return $item->retailer->user->can('proposeExchange', User::class);
    }
}
