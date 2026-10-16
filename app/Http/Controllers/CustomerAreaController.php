<?php

namespace App\Http\Controllers;

use App\Actions\SaveCustomerAddress;
use App\Http\Requests\CustomerProfileRequest;
use App\Models\CustomerAddress;
use App\Models\MarketplacePayment;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorOrder;
use App\Services\VendorOrderWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerAreaController extends Controller
{
    public function index(Request $r)
    {
        return view('customer.home', ['orders' => $r->user()->marketplaceOrders()->latest('id')->limit(5)->get()]);
    }

    public function payments(Request $r)
    {
        $filters = $r->validate(['status' => ['nullable', 'in:payment_pending,paid,failed,cancelled,expired,refunded']]);
        $q = MarketplacePayment::whereIn('order_id', Order::where('user_id', $r->user()->id)->select('id'));
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return view('customer.payments', ['payments' => $q->latest('id')->paginate(15)->withQueryString(), 'filters' => $filters]);
    }

    public function deliveries(Request $r)
    {
        $filters = $r->validate(['status' => ['nullable', 'in:payment_pending,paid,preparing,shipped,ready_for_pickup,completed,cancelled,refunded']]);
        $q = VendorOrder::whereHas('order', fn ($q) => $q->where('user_id', $r->user()->id))->with('items');
        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }

        return view('customer.deliveries', ['deliveries' => $q->latest('id')->paginate(15)->withQueryString(), 'filters' => $filters]);
    }

    public function delivery(VendorOrder $vendorOrder)
    {
        Gate::authorize('viewDelivery', $vendorOrder);

        return view('customer.delivery', ['vendor' => $vendorOrder->load('items', 'events', 'refund')]);
    }

    public function received(Request $r, VendorOrder $vendorOrder, VendorOrderWorkflow $flow)
    {
        $flow->receive($r->user(), $vendorOrder);

        return to_route('customer.deliveries.show', $vendorOrder)->with('status', 'Ricezione registrata.');
    }

    public function addresses(Request $r)
    {
        return view('customer.addresses', ['addresses' => $r->user()->customerAddresses()->orderByDesc('is_default_shipping')->latest('id')->paginate(15)]);
    }

    public function addressForm(Request $r, ?CustomerAddress $address = null)
    {
        if ($address) {
            Gate::authorize('update', $address);
        }

        return view('customer.address-form', ['address' => $address]);
    }

    public function saveAddress(Request $r, SaveCustomerAddress $action, ?CustomerAddress $address = null)
    {
        $action->handle($r->user(), $r->all(), $address);

        return to_route('customer.addresses.index')->with('status', 'Indirizzo salvato.');
    }

    public function deleteAddress(Request $r, CustomerAddress $address)
    {
        Gate::authorize('delete', $address);
        DB::transaction(function () use ($r, $address) {
            User::lockForUpdate()->findOrFail($r->user()->id);
            $r->user()->customerAddresses()->lockForUpdate()->findOrFail($address->id)->delete();
        });

        return to_route('customer.addresses.index')->with('status', 'Indirizzo rimosso. Gli ordini conservano lo snapshot originale.');
    }

    public function profile(Request $r)
    {
        return view('customer.profile', ['user' => $r->user()]);
    }

    public function updateProfile(CustomerProfileRequest $r)
    {
        $u = $r->user();
        $data = $r->validated();
        $u->fill($data);
        $u->name = $data['first_name'].' '.$data['last_name'];
        if ($u->isDirty('email')) {
            $u->email_verified_at = null;
        }$u->save();

        return to_route('customer.profile')->with('status', 'Profilo aggiornato.');
    }
}
