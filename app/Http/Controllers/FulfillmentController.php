<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFulfillmentRequest;
use App\Models\Exchange;
use App\Models\VendorOrder;
use App\Services\OperationalLifecycle;

class FulfillmentController extends Controller
{
    public function order(UpdateFulfillmentRequest $request, VendorOrder $vendorOrder, OperationalLifecycle $service)
    {
        return response()->json($service->transition($request->user(), $vendorOrder, $request->validated()));
    }

    public function exchange(UpdateFulfillmentRequest $request, Exchange $exchange, OperationalLifecycle $service)
    {
        return response()->json($service->transition($request->user(), $exchange, $request->validated()));
    }
}
