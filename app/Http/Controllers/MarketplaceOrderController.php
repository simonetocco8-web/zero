<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\StockReservations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MarketplaceOrderController extends Controller
{
    public function index(Request $r)
    {
        $filters = $r->validate(['status' => ['nullable', 'in:payment_pending,paid,preparing,shipped,ready_for_pickup,completed,cancelled,refunded']]);
        $query = Order::where('user_id', $r->user()->id);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return view('cart.orders', ['orders' => $query->latest('id')->paginate(15)->withQueryString(), 'filters' => $filters]);
    }

    public function show(Order $order)
    {
        Gate::authorize('view', $order);

        return view('cart.order', ['order' => $order->load('vendorOrders.items', 'payment')]);
    }

    public function cancel(Order $order)
    {
        Gate::authorize('view', $order);
        app(StockReservations::class)->release($order);

        return to_route('orders.show', $order)->with('status', 'Richiesta di annullamento elaborata. Gli ordini già pagati non vengono annullati.');
    }

    public function retry(Order $order)
    {
        Gate::authorize('view', $order);
        try {
            app(StartMarketplacePayment::class)->handle($order);
        } catch (\Throwable) {
            return back()->with('status', 'Simulazione non riuscita. Il tuo ordine resta salvato.');
        }

        return to_route('orders.show', $order)->with('status', 'Intento simulato registrato. Nessun addebito.');
    }
}
