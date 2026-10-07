<?php

namespace App\Http\Controllers;

use App\Actions\SaveInventoryItem;
use App\Http\Requests\InventoryRequest;
use App\Models\InventoryItem;
use App\Services\InventoryPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('create', InventoryItem::class);

        return view('inventory.index', ['items' => $request->user()->retailer->inventoryItems()->with('images')->latest()->paginate(15)]);
    }

    public function create()
    {
        Gate::authorize('create', InventoryItem::class);

        return view('inventory.form', ['item' => new InventoryItem, 'data' => []]);
    }

    public function show(InventoryItem $item)
    {
        Gate::authorize('view', $item);

        return view('inventory.show', compact('item'));
    }

    public function edit(InventoryItem $item)
    {
        Gate::authorize('update', $item);

        return view('inventory.form', ['item' => $item, 'data' => $item->proposed_data ?? $item->getAttributes()]);
    }

    public function store(InventoryRequest $request, SaveInventoryItem $action)
    {
        $item = $action->handle($request->user(), $request->validated(), $request->file('photos', []));

        return to_route('retailer.stock.show', $item)->with('status', 'Giacenza salvata.');
    }

    public function update(InventoryRequest $request, InventoryItem $item, SaveInventoryItem $action)
    {
        $action->handle($request->user(), $request->validated(), $request->file('photos', []), $item);

        return to_route('retailer.stock.show', $item)->with('status', 'Giacenza salvata.');
    }

    public function archive(Request $request, InventoryItem $item, SaveInventoryItem $action)
    {
        $action->archive($request->user(), $item);

        return to_route('retailer.stock')->with('status', 'Giacenza archiviata.');
    }

    public function image(InventoryItem $item, int $position, Request $request)
    {
        Gate::authorize('view', $item);
        $proposed = $request->boolean('proposed');
        if ($proposed) {
            $photo = $item->proposed_images[$position] ?? null;
        } else {
            $photo = $item->images()->where('position', $position)->first()?->only(['disk', 'path']);
        } abort_unless($photo && app(InventoryPhotos::class)->isSafeReference($photo), 404);

        return Storage::disk($photo['disk'])->response($photo['path'], null, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
