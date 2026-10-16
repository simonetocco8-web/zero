<?php

namespace App\Http\Controllers;

use App\Services\Payments\ConnectRetailerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MarketplaceConnectController extends Controller
{
    public function show(Request $r)
    {
        Gate::authorize('operate', $r->user()->retailer);

        return view('payments.connect', ['account' => DB::table('marketplace_connected_accounts')->where('retailer_id', $r->user()->retailer->id)->first()]);
    }

    public function begin(Request $r, ConnectRetailerAccount $accounts)
    {
        Gate::authorize('operate', $r->user()->retailer);

        return redirect()->away($accounts->begin($r->user()->retailer));
    }

    public function refresh(Request $r, ConnectRetailerAccount $accounts)
    {
        Gate::authorize('operate', $r->user()->retailer);
        $accounts->refresh($r->user()->retailer);

        return to_route('retailer.connect.show');
    }
}
