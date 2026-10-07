<?php

namespace App\Http\Controllers;

use App\Actions\ReviewPayout;
use App\Http\Requests\AdminFilterRequest;
use App\Http\Requests\ReviewPayoutRequest;
use App\Models\PayoutRequest;
use App\Services\AdminFilters;

class AdminPayoutController extends Controller
{
    public function index(AdminFilterRequest $request, AdminFilters $filters)
    {
        return view('admin.payouts', ['payouts' => $filters->apply(PayoutRequest::query(), $request->validated())->with('retailer.user')->latest()->paginate(15)->withQueryString()]);
    }

    public function review(ReviewPayoutRequest $request, PayoutRequest $payout, ReviewPayout $action)
    {
        $action->handle($request->user(), $payout, $request->validated('decision'), $request->validated('reason'), $request->validated('payment_reference'));

        return to_route('admin.payouts')->with('status', 'Stato del bonifico aggiornato.');
    }
}
