<?php

namespace App\Actions;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\User;
use App\Services\CustomerPromotionalBonus;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceNotices;
use App\Services\MarketplaceQuote;
use App\Services\RetailerCredit;
use App\Services\StockReservations;
use App\Support\ProportionalAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateMarketplaceOrder
{
    public function handle(User $user, string $key, array $modes, ?int $addressId, string $reviewHash, bool $useBonus = false, bool $useCredit = true): Order
    {
        Gate::forUser($user)->authorize('purchase', User::class);

        return DB::transaction(function () use ($user, $key, $modes, $addressId, $reviewHash, $useBonus, $useCredit) {
            User::lockForUpdate()->findOrFail($user->id);
            $existing = Order::where('checkout_key', $key)->first();
            if ($existing) {
                Gate::forUser($user)->authorize('view', $existing);

                return $existing;
            }
            $cart = app(MarketplaceCart::class)->cart($user);
            $ids = $cart->items()->pluck('inventory_item_id');
            $retailers = InventoryItem::whereIn('id', $ids)->orderBy('retailer_id')->pluck('retailer_id')->push($user->retailer?->id)->filter()->unique()->sort();
            foreach ($retailers as $id) {
                Retailer::whereKey($id)->lockForUpdate()->firstOrFail();
            }
            InventoryItem::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $address = $addressId ? $user->customerAddresses()->lockForUpdate()->findOrFail($addressId) : null;
            $quotes = app(MarketplaceQuote::class);
            $quote = $quotes->build($user, $modes, $address, true);
            if (! hash_equals($reviewHash, $quotes->hash($quote))) {
                app(MarketplaceCart::class)->fail('checkout', 'Prezzi, disponibilità o dati sono cambiati. Rivedi il riepilogo prima di confermare.');
            }
            $order = Order::create(collect($quote)->except('groups')->all() + ['checkout_key' => $key, 'user_id' => $user->id, 'customer_snapshot' => $user->only(['name', 'email', 'first_name', 'last_name', 'phone'])]);
            foreach ($quote['groups'] as $group) {
                $vendor = $order->vendorOrders()->create(collect($group)->except('items')->all() + ['currency' => 'EUR', 'tax_snapshot' => ['provisional' => $group['totals_provisional'], 'shipping_basis' => 'once_per_distinct_line', 'policy' => 'explicit_product_vat_v1', 'shipping_tax' => $group['totals_provisional'] ? 'unresolved' : 'per_line_snapshot']]);
                foreach ($group['items'] as $line) {
                    $vendor->items()->create($line);
                }
            }
            $credit = app(RetailerCredit::class)->reserve($order, $useCredit);
            $bonus = app(CustomerPromotionalBonus::class)->reserve($order, $useBonus);
            $weights = $order->vendorOrders()->orderBy('id')->get()->mapWithKeys(fn ($v) => [$v->id => $v->total_cents - $v->shipping_cents])->all();
            $allocations = ProportionalAllocation::split($bonus, $weights);
            $creditWeights = $order->vendorOrders()->orderBy('id')->get()->mapWithKeys(fn ($v) => [$v->id => $v->total_cents])->all();
            $creditAllocations = ProportionalAllocation::split($credit, $creditWeights);
            $order->payment()->create(['retailer_credit_cents' => $credit, 'bonus_cents' => $bonus, 'card_cents' => $order->total_cents - $bonus - $credit, 'funding_snapshot' => ['retailer_credit_policy' => 'matured_ledger_v1', 'retailer_credit_cents' => $credit, 'credit_by_vendor' => $creditAllocations, 'card_by_vendor' => collect($creditWeights)->map(fn ($amount, $id) => $amount - $creditAllocations[$id] - $allocations[$id])->all(), 'policy' => 'customer_bonus_v1', 'goods_limit_basis_points' => config('marketplace.bonus.maximum_goods_basis_points'), 'platform_funded_bonus_cents' => $bonus, 'bonus_by_vendor' => $allocations], 'provider' => 'fake_marketplace', 'amount_cents' => $order->total_cents, 'currency' => 'EUR', 'simulated' => true]);
            app(StockReservations::class)->reserveOrder($order);
            $cart->items()->delete();
            app(MarketplaceNotices::class)->orderReceived($order);

            return $order->refresh();
        }, 5);
    }
}
