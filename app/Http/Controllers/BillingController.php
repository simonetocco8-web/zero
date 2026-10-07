<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartCheckoutRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\StartStripeCheckout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Subscription::class);
        $retailer = $request->user()->retailer;

        return response()->view('retailer.billing', ['retailer' => $retailer, 'plan' => Plan::where('code', 'pro')->sole(), 'billing' => $retailer->billingSubscriptions()->latest('id')->first(), 'active' => $retailer->activeSubscription?->plan, 'configured' => (bool) (config('stripe.secret') && config('stripe.prices.monthly') && config('stripe.prices.yearly'))])->header('Cache-Control', 'private, no-store');
    }

    public function checkout(StartCheckoutRequest $request, StartStripeCheckout $service)
    {
        return redirect()->away($service->handle($request->user(), $request->validated('billing_interval')));
    }

    public function returned(Request $request)
    {
        Gate::authorize('viewAny', Subscription::class);

        // Browser redirects are not proof of payment. Only verified webhook reconciliation changes entitlements.
        return to_route('retailer.billing')->with('status', $request->query('result') === 'success' ? 'Checkout terminato. Attendi la conferma del pagamento tramite webhook.' : 'Checkout interrotto. Il piano non è stato modificato dal ritorno del browser.');
    }
}
