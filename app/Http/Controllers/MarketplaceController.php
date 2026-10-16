<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplaceRequest;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Services\InventoryPhotos;
use App\Services\MarketplaceCatalog;
use App\Services\PublicInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class MarketplaceController extends Controller
{
    public function home(MarketplaceCatalog $catalog)
    {
        return view('marketplace.home', ['categories' => $catalog->categories(), 'items' => $catalog->query()->limit(8)->get(), 'featured' => config('marketplace.featured_item_ids') ? $catalog->query()->whereIn('id', config('marketplace.featured_item_ids'))->limit(8)->get() : collect()]);
    }

    public function index(MarketplaceRequest $request, MarketplaceCatalog $catalog, ?Category $category = null)
    {
        $filters = $request->validated();
        if ($category) {
            unset($filters['category_id']);
        }
        $items = $catalog->query($filters, $category)->paginate(24)->appends($filters);

        return view('marketplace.index', ['items' => $items, 'filters' => $filters, 'category' => $category, 'categories' => $catalog->categories(), ...$catalog->facets($category)]);
    }

    public function show(InventoryItem $item, MarketplaceCatalog $catalog, Request $request)
    {
        $item->load('retailer', 'categoryRecord', 'images');
        app(PublicInventory::class)->ensureAvailable($item);
        Gate::authorize('viewPublic', $item);

        return view('marketplace.show', ['item' => $item, 'canExchange' => $catalog->canExchange($request->user(), $item)]);
    }

    public function image(InventoryItem $item, int $position, Request $request, PublicInventory $public, InventoryPhotos $photos)
    {
        abort_unless($public->isAvailable($item), 404);
        abort_if($request->has('proposed'), 404);
        $image = $item->images()->where('position', $position)->firstOrFail();
        Gate::authorize('viewPublic', $image);
        $reference = $image->only(['disk', 'path']);
        abort_unless($photos->isSafeReference($reference) && Storage::disk('local')->exists($image->path), 404);

        // Revalidate eligibility on EVERY request, including after suspension/photo replacement.
        return Storage::disk('local')->response($image->path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function purchase(InventoryItem $item)
    {
        Gate::authorize('viewPublic', $item);

        abort_if($item->retailer->user_id === request()->user()->id, 403);

        return view('cart.add', compact('item'));
    }

    public function exchange(InventoryItem $item, Request $request, MarketplaceCatalog $catalog)
    {
        Gate::authorize('viewPublic', $item);
        abort_unless($catalog->canExchange($request->user(), $item), 403);

        return app(ExchangeController::class)->create($request, $item, $catalog);
    }
}
