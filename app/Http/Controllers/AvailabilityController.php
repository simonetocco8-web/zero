<?php

namespace App\Http\Controllers;

use App\Actions\CreateAvailabilityRequest as CreateRequest;
use App\Actions\UpdateAvailabilityStatus;
use App\Http\Requests\CreateAvailabilityRequest;
use App\Http\Requests\UpdateAvailabilityRequest;
use App\Models\AvailabilityRequest;
use App\Models\InventoryItem;
use App\Services\PublicInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AvailabilityController extends Controller
{
    public function create(InventoryItem $item, PublicInventory $public)
    {
        $public->ensureAvailable($item);

        return response()->view('availability.create', compact('item'))->header('Cache-Control', 'private, no-store');
    }

    public function store(CreateAvailabilityRequest $request, InventoryItem $item, CreateRequest $action)
    {
        $action->handle($item, $request->validated());

        return to_route('availability.create', $item)->with('status', 'Richiesta inviata. Il rivenditore potrà contattarti ai recapiti indicati.');
    }

    public function index(Request $request)
    {
        Gate::authorize('viewAny', AvailabilityRequest::class);

        return response()->view('availability.index', ['requests' => $request->user()->retailer->availabilityRequests()->with('inventoryItem')->latest('id')->paginate(15)])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateAvailabilityRequest $request, AvailabilityRequest $availabilityRequest, UpdateAvailabilityStatus $action)
    {
        $action->handle($request->user(), $availabilityRequest, $request->validated('status'));

        return to_route('retailer.requests')->with('status', 'Stato della richiesta aggiornato.');
    }
}
