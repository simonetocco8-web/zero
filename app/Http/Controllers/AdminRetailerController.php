<?php

namespace App\Http\Controllers;

use App\Actions\ReviewRetailer;
use App\Http\Requests\AdminFilterRequest;
use App\Http\Requests\ReviewRetailerRequest;
use App\Models\Retailer;
use App\Services\AdminFilters;

class AdminRetailerController extends Controller
{
    public function index(AdminFilterRequest $request, AdminFilters $filters)
    {
        return view('admin.retailers', ['retailers' => $filters->apply(Retailer::query(), $request->validated(), true)->with('user', 'subscriptions.plan')->latest()->paginate(15)->withQueryString()]);
    }

    public function review(ReviewRetailerRequest $request, Retailer $retailer, ReviewRetailer $action)
    {
        $action->handle($request->user(), $retailer, $request->validated('decision'), $request->validated('reason'));

        return to_route('admin.retailers')->with('status', 'Verifica salvata.');
    }
}
