<?php

namespace App\Http\Controllers;

use App\Actions\SaveCustomerAddress;
use App\Http\Requests\ShippingQuoteRequest;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\ShippingQuote;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\Shipping\ShippingQuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShippingQuoteController extends Controller
{
    public function store(ShippingQuoteRequest $request, ShippingQuoteService $service, MarketplaceCart $carts)
    {
        $data = $request->validated();
        $user = $request->user();
        $address = ! empty($data['address_id']) ? $user->customerAddresses()->findOrFail($data['address_id']) : null;
        if (! $address && empty($data['address'])) {
            $address = $user->customerAddresses()->where('is_default_shipping', true)->first();
        }
        $destination = $service->address($address?->toArray() ?? ($data['address'] ?? []));
        if ($request->boolean('save_address') && ! $address) {
            app(SaveCustomerAddress::class)->handle($user, $destination + ['label' => 'Spedizione']);
        }
        if (! empty($data['item_id'])) {
            $product = InventoryItem::with('retailer')->findOrFail($data['item_id']);
            $q = $carts->quantity($data['quantity']);
            $carts->validate($user, $product, $q, 'shipping');
            $origin = $product->retailer;
            // Quote the resulting seller shipment, including products already in the cart.
            $existing = $carts->cart($user)->items()->with('product')->get()->filter(fn ($row) => $row->product->retailer_id === $origin->id);
            $lines = [];
            foreach ($existing as $row) {
                if ($row->inventory_item_id === $product->id) {
                    $q += $row->quantity_milliunits;
                } else {
                    $carts->validate($user, $row->product, $row->quantity_milliunits, 'shipping');
                    $lines[] = ['product' => $row->product, 'quantity_milliunits' => $row->quantity_milliunits];
                }
            }
            $carts->validate($user, $product, $q, 'shipping');
            $lines[] = ['product' => $product, 'quantity_milliunits' => $q];
        } else {
            $origin = Retailer::findOrFail($data['retailer_id'] ?? 0);
            $lines = $carts->cart($user)->items()->with('product')->get()->filter(fn ($row) => $row->product->retailer_id === $origin->id)->map(function ($row) use ($user, $carts) {
                $carts->validate($user, $row->product, $row->quantity_milliunits, 'shipping');

                return ['product' => $row->product, 'quantity_milliunits' => $row->quantity_milliunits];
            })->values()->all();
        }
        $quotes = $service->quote($user, $origin, $lines, $destination);

        return response()->json(['quotes' => array_map(fn ($q) => ['id' => $q->id, 'rate' => $q->rate, 'expires_at' => $q->expires_at->toIso8601String()], $quotes), 'informational' => ! config('shipping.checkout_verified')])->header('Cache-Control', 'private, no-store');
    }

    public function select(Request $request, ShippingQuoteService $service, MarketplaceCart $carts)
    {
        $data = $request->validate(['shipping_quote_id' => ['required', 'uuid'], 'return_to' => ['sometimes', 'in:cart,checkout']]);
        DB::transaction(function () use ($request, $data, $service, $carts) {
            $user = $request->user();
            User::lockForUpdate()->findOrFail($user->id);
            $selected = ShippingQuote::where('user_id', $user->id)->findOrFail($data['shipping_quote_id']);
            $origin = Retailer::lockForUpdate()->findOrFail($selected->retailer_id);
            $rows = $carts->cart($user)->items()->whereHas('product', fn ($p) => $p->where('retailer_id', $origin->id))->lockForUpdate()->get();
            $lines = $rows->map(function ($row) use ($carts, $user) {
                $p = InventoryItem::lockForUpdate()->findOrFail($row->inventory_item_id);
                $carts->validate($user, $p, $row->quantity_milliunits, 'shipping');

                return ['product' => $p, 'quantity_milliunits' => $row->quantity_milliunits];
            })->all();
            $service->valid($user, $selected->id, $origin, $lines, $selected->request_snapshot['destination'], 'sale', true);
            $carts->cart($user)->items()->whereIn('id', $rows->pluck('id'))->update(['shipping_quote_id' => $selected->id, 'shipping_pricing_mode' => 'dynamic', 'delivery_mode' => 'shipping']);
        });

        return to_route(($data['return_to'] ?? 'checkout') === 'cart' ? 'cart.index' : 'checkout.index')->with('status', 'Tariffa selezionata. Verifica indirizzo e riepilogo.');
    }
}
