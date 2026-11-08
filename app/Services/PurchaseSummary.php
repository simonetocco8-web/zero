<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\ShippingQuote;
use App\Models\User;
use App\Services\Shipping\ShippingQuoteService;
use App\Support\MoneyMath;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Read-only estimates. Never creates orders, reserves stock or authorizes checkout. */
class PurchaseSummary
{
    public function cart(User $user, Cart $cart, array $modes = [], ?array $destination = null): array
    {
        abort_unless($cart->user_id === $user->id, 403);

        return $this->summarize($user, $cart->items, $modes, $destination);
    }

    public function product(User $user, InventoryItem $product, int $quantity, string $mode): array
    {
        $line = new CartItem(['quantity_milliunits' => $quantity, 'delivery_mode' => $mode]);
        $line->setRelation('product', $product);

        return $this->summarize($user, collect([$line]));
    }

    /** Adapts existing server-calculated order/checkout snapshots without repricing them. */
    public function quoted(array|Model $data): array
    {
        return ['goods_input_cents' => $data['subtotal_cents'], 'goods_cents' => $data['totals_provisional'] ? null : $data['total_cents'] - $data['shipping_cents'], 'shipping_estimate_cents' => $data['shipping_cents'], 'total_cents' => $data['totals_provisional'] ? null : $data['total_cents'], 'shipping_missing' => false, 'tax_pending' => $data['totals_provisional'], 'unavailable' => false, 'checkout_blocked' => false];
    }

    private function summarize(User $user, Collection $lines, array $modes = [], ?array $destination = null): array
    {
        $groups = [];
        foreach ($lines->groupBy(fn ($line) => $line->product->retailer_id) as $sellerId => $rows) {
            $seller = $rows->first()->product->retailer;
            $mode = $modes[$sellerId] ?? ($rows->pluck('delivery_mode')->unique()->count() === 1 ? $rows->first()->delivery_mode : 'mixed');
            $g = ['retailer_id' => $sellerId, 'name' => $seller->company_name, 'lines' => $rows, 'mode' => $mode, 'goods_input_cents' => 0, 'goods_cents' => 0, 'shipping_cents' => null, 'shipping_estimate_cents' => null, 'selected' => null, 'destination' => null, 'shipping_missing' => false, 'tax_pending' => false, 'unavailable' => false, 'dynamic' => false, 'can_calculate' => config('shipping.enabled') && $rows->every(fn ($row) => $row->product->shipping_available)];
            foreach ($rows as $row) {
                $p = $row->product;
                $input = MoneyMath::lineTotalCents($p->zero_price_cents, $row->quantity_milliunits);
                $fiscal = app(MarketplaceTaxSnapshot::class)->amount($input, $p->vat_treatment, $p->vat_rate_basis_points, $p->vatLabel());
                $g['goods_input_cents'] = $this->sum([$g['goods_input_cents'], $input]);
                $g['goods_cents'] = $this->sum([$g['goods_cents'], $fiscal['gross_cents']]);
                $g['tax_pending'] = $g['tax_pending'] || $fiscal['provisional'];
                try {
                    app(MarketplaceCart::class)->validate($user, $p, $row->quantity_milliunits, $mode);
                } catch (ValidationException) {
                    $g['unavailable'] = true;
                }
            }
            if ($mode === 'pickup') {
                $g['shipping_estimate_cents'] = $g['shipping_cents'] = 0;
            } elseif ($mode === 'shipping') {
                $g['dynamic'] = config('shipping.enabled') || $rows->contains('shipping_pricing_mode', 'dynamic') || $rows->contains(fn ($r) => $r->shipping_quote_id !== null);
                if ($g['dynamic']) {
                    $references = $rows->pluck('shipping_quote_id')->unique();
                    $selected = $references->count() === 1 && $references->first() ? ShippingQuote::where('user_id', $user->id)->find($references->first()) : null;
                    if (config('shipping.enabled') && $selected && ! $g['unavailable']) {
                        try {
                            $shipment = $rows->map(fn ($r) => ['product' => $r->product, 'quantity_milliunits' => $r->quantity_milliunits])->all();
                            $selected = app(ShippingQuoteService::class)->valid($user, $selected->id, $seller, $shipment, $destination ?? $selected->request_snapshot['destination']);
                            $g['selected'] = $selected;
                            $g['destination'] = $selected->request_snapshot['destination'];
                            $g['shipping_estimate_cents'] = $selected->rate['final_cents'];
                            // Same gross-price convention as MarketplaceQuote. Never add VAT again.
                            if (in_array($selected->rate['tax_treatment'] ?? null, ['included', 'not_applicable'], true)) {
                                $fiscal = app(MarketplaceTaxSnapshot::class)->amount($selected->rate['final_cents'], $selected->rate['tax_treatment'], $selected->rate['tax_rate_basis_points'] ?? null, 'Trasporto quotato');
                                $g['shipping_cents'] = $fiscal['gross_cents'];
                            }
                            $g['tax_pending'] = $g['tax_pending'] || $g['shipping_cents'] === null;
                        } catch (ValidationException) {
                            // Expired or mismatching snapshots are never presented as a usable selection.
                        }
                    }
                    $g['shipping_missing'] = $g['selected'] === null;
                } else {
                    $amounts = [];
                    $estimates = [];
                    foreach ($rows as $row) {
                        $p = $row->product;
                        $cost = $p->shipping_cost_cents;
                        $g['shipping_missing'] = $g['shipping_missing'] || $cost === null;
                        $tax = $cost === null ? null : app(MarketplaceTaxSnapshot::class)->amount($cost, $cost === 0 ? 'not_applicable' : $p->shipping_vat_treatment, $cost === 0 ? null : $p->shipping_vat_rate_basis_points, 'Trasporto');
                        $amounts[] = $tax['gross_cents'] ?? null;
                        $estimates[] = $tax['gross_cents'] ?? $cost;
                        $g['tax_pending'] = $g['tax_pending'] || ($tax['provisional'] ?? false);
                    }
                    $g['shipping_cents'] = $this->sum($amounts);
                    $g['shipping_estimate_cents'] = $this->sum($estimates);
                }
            } else {
                $g['shipping_missing'] = true;
            }
            $g['total_cents'] = $g['shipping_missing'] || $g['tax_pending'] || $g['unavailable'] ? null : $this->sum([$g['goods_cents'], $g['shipping_cents']]);
            $g['checkout_blocked'] = $mode === 'shipping' && $g['dynamic'] && (! config('shipping.enabled') || ! config('shipping.checkout_verified'));
            $groups[] = $g;
        }

        return ['groups' => $groups] + $this->aggregate($groups);
    }

    private function aggregate(array $groups): array
    {
        return [
            'goods_input_cents' => $this->sum(array_column($groups, 'goods_input_cents')),
            'goods_cents' => $this->sum(array_column($groups, 'goods_cents')),
            'shipping_estimate_cents' => $this->sum(array_map(fn ($g) => $g['shipping_estimate_cents'] ?? 0, $groups)),
            'shipping_cents' => $this->sum(array_column($groups, 'shipping_cents')),
            'total_cents' => $this->sum(array_column($groups, 'total_cents')),
            'shipping_missing' => in_array(true, array_column($groups, 'shipping_missing'), true),
            'tax_pending' => in_array(true, array_column($groups, 'tax_pending'), true),
            'unavailable' => in_array(true, array_column($groups, 'unavailable'), true),
            'checkout_blocked' => in_array(true, array_column($groups, 'checkout_blocked'), true),
        ];
    }

    private function sum(array $amounts): ?int
    {
        return in_array(null, $amounts, true) ? null : BigInteger::sum(0, ...$amounts)->toInt();
    }
}
