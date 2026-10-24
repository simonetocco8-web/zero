<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\User;
use App\Support\MoneyMath;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

class MarketplaceQuote
{
    public function __construct(private MarketplaceCart $carts) {}

    public function build(User $user, array $modes, ?CustomerAddress $address = null, bool $locking = false): array
    {
        app(MarketplaceEconomicRules::class)->sale();
        $cart = $this->carts->cart($user);
        $modes = array_map(fn ($mode) => (string) $mode, $modes);
        $itemsQuery = $cart->items()->orderBy('inventory_item_id');
        if ($locking) {
            $itemsQuery->lockForUpdate();
        }$items = $itemsQuery->get();
        if ($items->isEmpty()) {
            $this->carts->fail('cart', 'Il carrello è vuoto.');
        }
        if ($address && $address->user_id !== $user->id) {
            $this->carts->fail('address_id', 'Indirizzo non autorizzato.');
        }
        $groups = [];
        $total = BigInteger::zero();
        $subtotal = BigInteger::zero();
        $shipping = BigInteger::zero();
        $allTax = BigInteger::zero();
        $allBase = BigInteger::zero();
        $provisional = false;
        foreach ($items as $item) {
            $productQuery = InventoryItem::with('retailer');
            if ($locking) {
                $productQuery->lockForUpdate();
            }$p = $productQuery->findOrFail($item->inventory_item_id);
            if ($locking) {
                $p->setRelation('retailer', Retailer::lockForUpdate()->findOrFail($p->retailer_id));
            }$mode = $modes[$p->retailer_id] ?? $item->delivery_mode;
            $this->carts->validate($user, $p, $item->quantity_milliunits, $mode);
            if ($mode === 'shipping' && ! $address) {
                $this->carts->fail('address_id', 'Scegli o aggiungi un indirizzo per la spedizione.');
            }
            if ($mode === 'shipping' && $p->shipping_cost_cents === null) {
                $this->carts->fail('delivery_mode', 'Il costo di consegna deve essere definito dal rivenditore prima del checkout.');
            }
            $subscriptionQuery = $p->retailer->activeSubscription()->with('plan');
            if ($locking) {
                $subscriptionQuery->lockForUpdate();
            }$sub = $subscriptionQuery->first();
            if (! $sub || $sub->starts_at > now() || ($sub->ends_at && $sub->ends_at <= now())) {
                $this->carts->fail('cart', 'Piano del rivenditore non valido.');
            }
            $planQuery = Plan::query();
            if ($locking) {
                $planQuery->sharedLock();
            }$plan = $planQuery->findOrFail($sub->plan_id);
            $gross = MoneyMath::lineTotalCents($p->zero_price_cents, $item->quantity_milliunits);
            $cost = $mode === 'shipping' ? $p->shipping_cost_cents : 0;
            $treatment = $p->vat_treatment;
            $taxes = app(MarketplaceTaxSnapshot::class);
            $fiscal = $taxes->amount($gross, $treatment, $p->vat_rate_basis_points, $p->vatLabel());
            $shippingFiscal = $cost === 0
                ? $taxes->amount(0, 'not_applicable', null, $mode === 'pickup' ? 'Ritiro senza trasporto' : 'Spedizione gratuita')
                : $taxes->amount($cost, $p->shipping_vat_treatment, $p->shipping_vat_rate_basis_points, config('marketplace.vat_treatments.'.$p->shipping_vat_treatment) ?? 'IVA da definire');
            $shippingFiscal['input_amount_cents'] = $cost;
            $cost = $shippingFiscal['gross_cents'] ?? $cost;
            $base = $fiscal['taxable_cents'];
            $tax = $fiscal['tax_cents'] === null || $shippingFiscal['tax_cents'] === null ? null : BigInteger::of($fiscal['tax_cents'])->plus($shippingFiscal['tax_cents'])->toInt();
            $taxable = $base === null || $shippingFiscal['taxable_cents'] === null ? null : BigInteger::of($base)->plus($shippingFiscal['taxable_cents'])->toInt();
            $due = $fiscal['gross_cents'] ?? $gross;
            $commissionRate = app(MarketplaceCommission::class)->saleRate($plan->code);
            $uncertain = $fiscal['provisional'] || $shippingFiscal['provisional'];
            $line = ['inventory_item_id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit, 'unit_price_cents' => $p->zero_price_cents, 'quantity_milliunits' => $item->quantity_milliunits, 'subtotal_cents' => $gross, 'shipping_cost_cents' => $cost, 'tax_cents' => $tax, 'taxable_cents' => $taxable, 'total_cents' => BigInteger::of($due)->plus($cost)->toInt(), 'commission_basis_points' => $commissionRate, 'commission_cents' => $uncertain ? null : MoneyMath::commissionCents($base, $commissionRate), 'tax_snapshot' => array_replace($fiscal, ['provisional' => $uncertain]) + ['policy' => 'explicit_product_vat_v1', 'input_amount_cents' => $gross, 'issuer_retailer_id' => $p->retailer_id, 'shipping_tax' => $shippingFiscal, 'commission_base_cents' => $base], 'product_snapshot' => ['brand' => $p->brand, 'ean' => $p->ean, 'category' => $p->categoryRecord?->name ?? $p->category, 'retailer_name' => $p->retailer->company_name, 'delivery_notes' => $p->delivery_notes, 'available_quantity_milliunits' => app(StockReservations::class)->available($p), 'pickup_available' => $p->pickup_available, 'shipping_available' => $p->shipping_available]];
            if (! isset($groups[$p->retailer_id])) {
                $groups[$p->retailer_id] = ['retailer_id' => $p->retailer_id, 'retailer_snapshot' => ['seller_role' => 'merchandise_seller', 'id' => $p->retailer_id, 'name' => $p->retailer->company_name, 'vat_number' => $p->retailer->vat_number, 'city' => $p->retailer->city], 'delivery_mode' => $mode, 'address_snapshot' => $mode === 'shipping' ? $address->only(['recipient_name', 'company', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'province', 'country_code', 'phone']) : null, 'items' => [], 'subtotal_cents' => 0, 'shipping_cents' => 0, 'tax_cents' => 0, 'taxable_cents' => 0, 'total_cents' => 0, 'totals_provisional' => false, 'funds_flow_snapshot' => ['policy' => 'approved_sales_v1', 'merchandise_seller_id' => $p->retailer_id, 'commission_basis_points' => $commissionRate, 'commission_base' => 'taxable_goods_excluding_shipping_existing_convention', 'commission_tax' => ['issuer' => 'ZeroMagazzino', 'recipient_retailer_id' => $p->retailer_id, 'rate_basis_points' => null, 'tax_cents' => null, 'gross_cents' => null], 'shipping_payer' => 'buyer', 'psp_cost_cents' => null]];
            }
            if ($groups[$p->retailer_id]['delivery_mode'] !== $mode) {
                $this->carts->fail('delivery_mode', 'Scegli una modalità unica per ciascun rivenditore.');
            }
            $g = &$groups[$p->retailer_id];
            $g['items'][] = $line;
            foreach (['subtotal_cents' => $gross, 'shipping_cents' => $cost, 'tax_cents' => $tax, 'taxable_cents' => $taxable, 'total_cents' => $line['total_cents']] as $key => $amount) {
                $g[$key] = $amount === null || $g[$key] === null ? null : BigInteger::of($g[$key])->plus($amount)->toInt();
            }$g['totals_provisional'] = $g['totals_provisional'] || $uncertain;
            unset($g);
            $total = $total->plus($line['total_cents']);
            $subtotal = $subtotal->plus($gross);
            $shipping = $shipping->plus($cost);
            if ($tax !== null) {
                $allTax = $allTax->plus($tax);
            }if ($taxable !== null) {
                $allBase = $allBase->plus($taxable);
            }$provisional = $provisional || $uncertain;
        }

        foreach ($groups as &$group) {
            $fees = collect($group['items'])->pluck('commission_cents');
            $feeTotal = $fees->containsStrict(null) ? null : BigInteger::sum(...$fees->all())->toInt();
            $bases = collect($group['items'])->map(fn ($line) => $line['tax_snapshot']['commission_base_cents']);
            $group['funds_flow_snapshot']['commission_base_cents'] = $bases->containsStrict(null) ? null : BigInteger::sum(...$bases->all())->toInt();
            $group['funds_flow_snapshot']['commission_tax'] = $feeTotal === null ? $group['funds_flow_snapshot']['commission_tax'] : app(MarketplaceCommission::class)->serviceTax($feeTotal, $group['retailer_id']);
            $group['funds_flow_snapshot']['recipient_stripe_account_id'] = DB::table('marketplace_connected_accounts')->where('retailer_id', $group['retailer_id'])->value('stripe_account_id');
            $group['funds_flow_snapshot']['funds_flow'] = config('marketplace.economic_rules.funds_flow');
            $group['funds_flow_snapshot'] += ['gross_receivable_cents' => $group['total_cents'], 'commission_cents' => $feeTotal, 'seller_net_before_psp_and_commission_tax_cents' => $feeTotal === null ? null : $group['total_cents'] - $feeTotal];
        }
        unset($group);

        return ['groups' => array_values($groups), 'currency' => 'EUR', 'subtotal_cents' => $subtotal->toInt(), 'shipping_cents' => $shipping->toInt(), 'tax_cents' => $provisional ? null : $allTax->toInt(), 'taxable_cents' => $provisional ? null : $allBase->toInt(), 'total_cents' => $total->toInt(), 'totals_provisional' => $provisional];
    }

    public function hash(array $quote): string
    {
        return hash('sha256', json_encode($quote, JSON_THROW_ON_ERROR));
    }
}
