<?php

namespace App\Http\Controllers;

use App\Actions\ReviewInventoryItem;
use App\Http\Requests\AdminFilterRequest;
use App\Http\Requests\ReviewInventoryRequest;
use App\Models\InventoryItem;
use App\Services\AdminFilters;

class AdminInventoryController extends Controller
{
    public function index(AdminFilterRequest $request, AdminFilters $filters)
    {
        return view('inventory.admin', ['items' => $filters->apply(InventoryItem::query(), $request->validated(), false, true)->with('retailer', 'images', 'publications')->latest()->paginate(15)->withQueryString()]);
    }

    public function review(ReviewInventoryRequest $request, InventoryItem $item, ReviewInventoryItem $action)
    {
        $action->handle($request->user(), $item, $request->validated('decision'), $request->validated('reason'));

        return to_route('admin.stock')->with('status', $item->publications()->latest()->first()?->status === 'failed' ? 'Verifica salvata. Simulazione negozio non riuscita: errore registrato per il recupero.' : 'Verifica salvata.');
    }
}
