<?php

namespace App\Http\Controllers;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\SaveCustomerAddress;
use App\Http\Requests\CheckoutRequest;
use App\Services\CustomerPromotionalBonus;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Payments\StartMarketplacePayment;
use App\Services\RetailerCredit;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function index(Request $request, MarketplaceCart $carts)
    {
        $cart = $carts->cart($request->user())->load('items.product.retailer');

        foreach ($cart->items as $line) {
            $carts->validate($request->user(), $line->product, $line->quantity_milliunits, $line->delivery_mode);
        }

        return view('cart.checkout', ['cart' => $cart, 'addresses' => $request->user()->customerAddresses()->get()]);
    }

    public function review(CheckoutRequest $request, MarketplaceQuote $quotes)
    {
        $data = $request->validated();
        $address = isset($data['address_id']) ? $request->user()->customerAddresses()->findOrFail($data['address_id']) : null;
        if (! $address && ! empty($data['address']['address_line_1'])) {
            $address = app(SaveCustomerAddress::class)->handle($request->user(), $data['address'] + ['label' => 'Checkout']);
        }
        $quote = $quotes->build($request->user(), $data['modes'], $address);
        $key = (string) Str::uuid();
        $request->session()->put('marketplace_checkout', ['key' => $key, 'modes' => $data['modes'], 'address_id' => $address?->id, 'hash' => $quotes->hash($quote), 'use_bonus' => $request->boolean('use_bonus'), 'use_credit' => $request->boolean('use_credit')]);

        return view('cart.review', ['quote' => $quote, 'key' => $key, 'creditPreview' => $request->boolean('use_credit') && $request->user()->retailer && config('marketplace.credit.retailer_spending_enabled') ? min($quote['total_cents'], app(RetailerCredit::class)->available($request->user()->retailer)) : 0, 'bonusPreview' => $request->boolean('use_bonus') ? min(app(CustomerPromotionalBonus::class)->available($request->user()), BigInteger::of($quote['total_cents'] - $quote['shipping_cents'])->multipliedBy(config('marketplace.bonus.maximum_goods_basis_points'))->quotient(10000)->toInt()) : 0]);
    }

    public function confirm(Request $request, CreateMarketplaceOrder $action)
    {
        $data = $request->validate(['key' => ['required', 'uuid'], 'total_cents' => ['prohibited'], 'price' => ['prohibited']]);
        $review = $request->session()->get('marketplace_checkout');
        abort_unless($review && hash_equals($review['key'], $data['key']), 419);
        $order = $action->handle($request->user(), $data['key'], $review['modes'], $review['address_id'], $review['hash'], $review['use_bonus'] ?? false, $review['use_credit'] ?? false);
        try {
            app(StartMarketplacePayment::class)->handle($order);
        } catch (\Throwable) {
            return to_route('orders.show', $order)->with('status', 'Ordine salvato. Avvio del pagamento da riprovare.');
        }

        return to_route('orders.show', $order)->with('status', 'Ordine creato in attesa di pagamento. Completa il pagamento dalla scheda ordine.');
    }
}
