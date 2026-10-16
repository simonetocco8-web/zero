<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorOrderStatusRequest;
use App\Models\Exchange;
use App\Models\MarketplaceReturnCase;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\VendorOrder;
use App\Services\AdminMarketplaceRecords;
use App\Services\VendorOrderWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminMarketplaceController extends Controller
{
    private function authorizeAdmin(): void
    {
        Gate::authorize('accessAdministration', User::class);
    }

    public function overview()
    {
        $this->authorizeAdmin();

        return view('admin.marketplace-home', ['sections' => AdminMarketplaceRecords::TITLES]);
    }

    public function index(Request $r, string $resource, AdminMarketplaceRecords $records)
    {
        $this->authorizeAdmin();
        abort_unless(isset(AdminMarketplaceRecords::TITLES[$resource]), 404);
        $f = $r->validate(['status' => ['nullable', 'in:payment_pending,paid,preparing,shipped,ready_for_pickup,completed,cancelled,refunded,failed,expired,proposed,countered,awaiting_acceptance,accepted,awaiting_difference_payment,confirmed,rejected,active,consumed,released,defined,undefined,accounted'], 'retailer_id' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer', 'min:1'], 'id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        if (! empty($f['from']) && ! empty($f['to']) && $f['from'] > $f['to']) {
            throw ValidationException::withMessages(['to' => 'La data finale deve seguire quella iniziale.']);
        }

        return view('admin.marketplace-records', ['records' => $records->query($resource, $f)->paginate(20)->withQueryString(), 'filters' => $f, 'resource' => $resource, 'title' => AdminMarketplaceRecords::TITLES[$resource], 'catalog' => $records]);
    }

    public function order(Order $order)
    {
        $this->authorizeAdmin();

        return view('admin.marketplace-order', ['order' => $order->load('vendorOrders.items', 'payment', 'stockReservations')]);
    }

    public function vendor(VendorOrder $vendorOrder)
    {
        $this->authorizeAdmin();

        return view('admin.marketplace-vendor', ['vendor' => $vendorOrder->load('items', 'events', 'refund', 'order', 'returnCases')]);
    }

    public function exchange(Exchange $exchange)
    {
        $this->authorizeAdmin();

        return view('admin.marketplace-exchange', ['exchange' => $exchange->load('versions.lines', 'versions.acceptances', 'versions.payment', 'events', 'stockReservations')]);
    }

    public function update(VendorOrderStatusRequest $r, VendorOrder $vendorOrder, VendorOrderWorkflow $flow)
    {
        $this->authorizeAdmin();
        $flow->advance($r->user(), $vendorOrder, $r->validated('status'), $r->safe()->except('status'));

        return to_route('admin.marketplace.vendors.show', $vendorOrder)->with('status', 'Stato aggiornato.');
    }

    public function refund(Request $r, VendorOrder $vendorOrder, VendorOrderWorkflow $flow)
    {
        $this->authorizeAdmin();
        $data = $r->validate(['reason' => ['required', 'string', 'max:2000'], 'amount_cents' => ['prohibited']]);
        $result = $flow->refund($r->user(), $vendorOrder, $data['reason']);

        return to_route('admin.marketplace.vendors.show', $vendorOrder)->with('status', $result instanceof MarketplaceReturnCase ? 'Richiesta reso/rimborso registrata per valutazione. Nessun rimborso o riassortimento automatico.' : 'Rimborso integrale simulato, storno commissione e ripristino stock applicabile registrati. Nessun trasferimento reale.');
    }

    public function reservation(StockReservation $reservation)
    {
        $this->authorizeAdmin();

        return view('admin.marketplace-reservation', ['reservation' => $reservation]);
    }
}
