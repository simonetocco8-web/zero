<?php

namespace App\Http\Controllers;

use App\Actions\RequestPayout;
use App\Http\Requests\RequestPayout as PayoutForm;
use App\Services\WalletBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CreditController extends Controller
{
    public function index(Request $request, WalletBalance $wallet)
    {
        $retailer = $request->user()->retailer;
        Gate::authorize('operate', $retailer);

        return response()->view('retailer.credit', [
            'retailer' => $retailer, 'available' => $wallet->availableCents($retailer), 'earned' => $wallet->netEarnedCents($retailer), 'reserved' => $wallet->reservedCents($retailer),
            'plan' => $retailer->activeSubscription?->plan,
            'sales' => $retailer->saleItems()->with('sale')->where('currency', 'EUR')->latest('id')->paginate(10, ['*'], 'sales_page'),
            'movements' => $retailer->walletTransactions()->where('currency', 'EUR')->latest('id')->paginate(15, ['*'], 'movements_page'),
            'payouts' => $retailer->payoutRequests()->where('currency', 'EUR')->latest('id')->paginate(10, ['*'], 'payouts_page'),
            'pending' => $retailer->payoutRequests()->where('status', 'pending')->exists(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(PayoutForm $request, RequestPayout $action)
    {
        $action->handle($request->user());

        return to_route('retailer.credit')->with('status', 'Bonifico richiesto. Il credito è stato riservato.');
    }
}
