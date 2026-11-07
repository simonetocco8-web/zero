<?php

namespace App\Http\Controllers;

use App\Http\Requests\CartItemRequest;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Services\MarketplaceCart;
use App\Services\PurchaseSummary;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request, MarketplaceCart $carts)
    {
        $cart = $carts->cart($request->user())->load(['items.product' => fn ($q) => $q->withSum(['stockReservations as reserved_milliunits' => fn ($r) => $r->where('status', 'active')->where('expires_at', '>', now())], 'quantity_milliunits')->with('retailer')]);

        return view('cart.index', ['cart' => $cart, 'summary' => app(PurchaseSummary::class)->cart($request->user(), $cart)]);
    }

    public function store(CartItemRequest $request, InventoryItem $item, MarketplaceCart $carts)
    {
        $carts->save($request->user(), $item, $request->validated('quantity'), $request->validated('delivery_mode'), true, $request->validated('shipping_quote_id'));

        return to_route('cart.index')->with('status', 'Prodotto aggiunto al carrello.');
    }

    public function update(CartItemRequest $request, CartItem $cartItem, MarketplaceCart $carts)
    {
        $carts->save($request->user(), $cartItem->product, $request->validated('quantity'), $request->validated('delivery_mode'), false, $request->validated('shipping_quote_id'));

        return to_route('cart.index')->with('status', 'Carrello aggiornato.');
    }

    public function destroy(Request $request, CartItem $cartItem, MarketplaceCart $carts)
    {
        $carts->remove($request->user(), $cartItem);

        return to_route('cart.index')->with('status', 'Prodotto rimosso.');
    }
}
