<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorOrderStatusRequest;
use App\Models\ExchangeDifferencePayment;
use App\Models\VendorOrder;
use App\Services\VendorOrderWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RetailerSalesController extends Controller
{
    public function overview(Request $r)
    {
        $id = $r->user()->retailer?->id;
        abort_unless($id, 403);

        return view('retailer.sales-home', ['received' => VendorOrder::where('retailer_id', $id)->count(), 'pending' => VendorOrder::where('retailer_id', $id)->where('status', 'payment_pending')->count()]);
    }

    public function index(Request $r)
    {
        $id = $r->user()->retailer?->id;
        abort_unless($id, 403);
        $f = $r->validate(['status' => ['nullable', 'in:payment_pending,paid,preparing,shipped,ready_for_pickup,completed,cancelled,refunded'], 'order_id' => ['nullable', 'integer', 'min:1']]);
        $q = VendorOrder::where('retailer_id', $id)->with('items');
        foreach ($f as $field => $value) {
            if ($value !== null && $value !== '') {
                $q->where($field, $value);
            }
        }

        return view('retailer.sales', ['vendors' => $q->latest('id')->paginate(15)->withQueryString(), 'filters' => $f]);
    }

    public function show(VendorOrder $vendorOrder)
    {
        Gate::authorize('viewSale', $vendorOrder);

        return view('retailer.sale', ['vendor' => $vendorOrder->load('items', 'events', 'refund', 'order:id,user_id,customer_snapshot')]);
    }

    public function update(VendorOrderStatusRequest $r, VendorOrder $vendorOrder, VendorOrderWorkflow $flow)
    {
        $flow->advance($r->user(), $vendorOrder, $r->validated('status'), $r->safe()->except('status'));

        return to_route('retailer.sales.show', $vendorOrder)->with('status', 'Stato aggiornato.');
    }

    public function differences(Request $r)
    {
        $id = $r->user()->retailer?->id;
        abort_unless($id, 403);
        $f = $r->validate(['status' => ['nullable', 'in:payment_pending,paid,failed,cancelled,expired'], 'direction' => ['nullable', 'in:pay,receive']]);
        $q = ExchangeDifferencePayment::whereHas('version.exchange', fn ($q) => $q->where(fn ($q) => $q->where('retailer_a_id', $id)->orWhere('retailer_b_id', $id)))->with('version');
        if (! empty($f['status'])) {
            $q->where('status', $f['status']);
        }if (! empty($f['direction'])) {
            $q->whereHas('version', fn ($q) => $q->where($f['direction'] === 'pay' ? 'payer_retailer_id' : 'payee_retailer_id', $id));
        }

        return view('retailer.differences', ['payments' => $q->latest('id')->paginate(15)->withQueryString(), 'filters' => $f, 'retailerId' => $id]);
    }
}
