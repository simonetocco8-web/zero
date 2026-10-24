<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\ApplyMarketplacePaymentResult;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\StockReservations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class MarketplaceOrderController extends Controller
{
    public function index(Request $r)
    {
        $filters = $r->validate(['status' => ['nullable', 'in:payment_pending,paid,preparing,shipped,ready_for_pickup,completed,cancelled,refunded']]);
        $query = Order::with('vendorOrders')->where('user_id', $r->user()->id);
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

    public function simulatePayment(Request $request, Order $order)
    {
        Gate::authorize('view', $order);
        abort_unless(config('marketplace.economic_rules.execution_mode') === 'simulation', 403);
        $request->validate(array_fill_keys([
            'amount', 'amount_cents', 'currency', 'reference', 'provider', 'status',
            'retailer_id', 'total_cents', 'event_id', 'payment_id', 'simulated',
        ], ['prohibited']));

        $order->refresh()->load('payment');
        $payment = $order->payment;
        abort_unless($payment && $payment->provider === 'fake_marketplace' && $payment->simulated && filled($payment->reference), 409);
        // A sequential double click must not create another economic event.
        if ($order->status->value === 'paid' && $payment->status === 'paid') {
            return to_route('orders.show', $order)->with('status', 'Il pagamento simulato risulta già completato.');
        }
        abort_unless($order->status->value === 'payment_pending' && $payment->status === 'payment_pending', 409);

        try {
            // Database values only. The service rechecks identity and stock under SQL locks.
            $outcome = app(ApplyMarketplacePaymentResult::class)->handle(
                $order, 'order-simulation:'.Str::uuid(), $payment->reference,
                'paid', $order->total_cents, $order->currency, 'fake_marketplace'
            );
        } catch (\Throwable $exception) {
            report($exception);
            $outcome = 'unavailable';
        }

        return to_route('orders.show', $order)->with('status', match ($outcome) {
            'consumed' => 'Pagamento simulato riuscito. Nessun addebito reale.',
            'already_paid' => 'Il pagamento simulato risulta già completato.',
            'tax_unresolved' => "Pagamento non completato: la configurazione IVA dell'ordine non è ancora determinata.",
            'reconciliation_required' => "Pagamento non completato: la prenotazione o lo stato dell'ordine richiedono una verifica.",
            default => 'Simulazione non completata. Verifica lo stato del tuo ordine.',
        });
    }
}
