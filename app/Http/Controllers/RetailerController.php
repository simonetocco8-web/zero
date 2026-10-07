<?php

namespace App\Http\Controllers;

use App\Actions\UpdateRetailerProfile;
use App\Http\Requests\RetailerCompanyRequest;
use App\Services\RetailerDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RetailerController extends Controller
{
    public function dashboard(Request $request, RetailerDashboard $dashboard)
    {
        $retailer = $request->user()->retailer;
        abort_unless($retailer, 403, 'Profilo rivenditore mancante.');
        Gate::authorize('view', $retailer);

        return view('retailer.dashboard', $dashboard->data($retailer));
    }

    public function profile(Request $request)
    {
        $retailer = $request->user()->retailer;
        abort_unless($retailer, 403);
        Gate::authorize('update', $retailer);

        return view('retailer.profile', compact('retailer'));
    }

    public function update(RetailerCompanyRequest $request, UpdateRetailerProfile $action)
    {
        $action->handle($request->user(), $request->user()->retailer, $request->validated());

        return to_route('retailer.profile')->with('status', 'Profilo aggiornato.');
    }
}
