<?php

namespace App\Http\Controllers;

use App\Actions\DeleteInventoryItem;
use App\Actions\ManageInventoryPhotos;
use App\Http\Requests\InventoryPhotosRequest;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InventoryManagementController extends Controller
{
    public function destroy(Request $request, InventoryItem $item, DeleteInventoryItem $action)
    {
        $action->handle($request->user(), $item);

        return to_route($request->user()->role->value === 'admin' ? 'admin.stock' : 'retailer.stock')->with('status', 'Articolo eliminato.');
    }

    public function editPhotos(InventoryItem $item, ManageInventoryPhotos $photos)
    {
        Gate::authorize('managePhotos', $item);

        return view('inventory.photos', ['item' => $item, 'photos' => $photos->references($item), 'version' => $photos->version($item)]);
    }

    public function photos(InventoryPhotosRequest $request, InventoryItem $item, ManageInventoryPhotos $photos)
    {
        $photos->handle($request->user(), $item, $request->validated(), $request->file('replacements', []), $request->file('photos', []));

        return to_route('inventory.photos.edit', $item)->with('status', 'Foto aggiornate. Per gli articoli pubblicati è necessaria la verifica amministrativa.');
    }
}
