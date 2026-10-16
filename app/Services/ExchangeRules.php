<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\User;
use App\Support\MoneyMath;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Illuminate\Validation\ValidationException;

class ExchangeRules
{
    public function eligible(Retailer $retailer): Plan
    {
        $user = User::findOrFail($retailer->user_id);
        $sub = $retailer->activeSubscription()->lockForUpdate()->first();
        $plan = $sub ? Plan::sharedLock()->find($sub->plan_id) : null;
        if ($user->role->value !== 'retailer' || $retailer->status->value !== 'approved' || ! $sub || ! $sub->starts_at || $sub->starts_at > now() || ($sub->ends_at && $sub->ends_at <= now()) || $plan?->code !== 'pro' || ! $plan->exchange_available) {
            $this->fail('exchange', 'Entrambi i rivenditori devono essere approvati e avere un piano PRO attivo abilitato allo scambio.');
        }

        return $plan;
    }

    public function money(mixed $value): int
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/D', (string) $value)) {
            $this->fail('value', 'Inserisci un valore esatto e non negativo con al massimo due decimali.');
        }

        return BigDecimal::of(str_replace(',', '.', (string) $value))->multipliedBy(100)->toBigInteger()->toInt();
    }

    public function product(InventoryItem $item, int $owner, int $q): void
    {
        if ($item->retailer_id !== $owner || $item->status->value !== 'published' || ! $item->published_at || ! $item->exchange_available || $item->currency !== 'EUR' || $q <= 0 || $q > app(StockReservations::class)->available($item)) {
            $this->fail('lines', 'Prodotto non pubblicato, non scambiabile, non appartenente al lato indicato o quantità insufficiente.');
        }
    }

    public function proposal(array $input, Retailer $a, Retailer $b, $products, array $plans, $expiry): array
    {
        app(MarketplaceEconomicRules::class)->exchange();
        $rows = [];
        $values = ['a' => BigInteger::zero(), 'b' => BigInteger::zero()];
        $seen = [];
        $tax = [];
        $flowTax = ['a' => ['taxable_cents' => 0, 'tax_cents' => 0, 'gross_cents' => 0], 'b' => ['taxable_cents' => 0, 'tax_cents' => 0, 'gross_cents' => 0]];
        $provisional = false;
        foreach (['a' => $a, 'b' => $b] as $side => $owner) {
            $lines = $input['lines'][$side] ?? [];
            if (! is_array($lines) || count($lines) < 1 || count($lines) > 20) {
                $this->fail('lines', 'Seleziona da 1 a 20 prodotti per ciascun lato.');
            }
            foreach ($lines as $line) {
                $p = $products->get($line['inventory_item_id'] ?? null);
                if (! $p || isset($seen[$p->id])) {
                    $this->fail('lines', 'Prodotto inesistente o ripetuto.');
                }
                $seen[$p->id] = true;
                $q = app(MarketplaceCart::class)->quantity($line['quantity'] ?? null);
                $price = $this->money($line['value'] ?? null);
                $this->product($p, $owner->id, $q);
                try {
                    $goods = MoneyMath::lineTotalCents($price, $q);
                    $values[$side] = $values[$side]->plus($goods);
                    $values[$side]->toInt();
                } catch (MathException) {
                    $this->fail('value', 'Il valore complessivo supera il limite rappresentabile.');
                }
                $mode = $input['delivery'][$side]['mode'] ?? null;
                if (! in_array($mode, ['pickup', 'shipping'], true) || ! $p->{$mode.'_available'}) {
                    $this->fail('delivery', 'Consegna non disponibile per tutti i prodotti del lato.');
                }
                $fiscal = app(MarketplaceTaxSnapshot::class)->goods($goods, $p->vat_treatment, $p->vatLabel());
                $provisional = $provisional || $fiscal['provisional'];
                $tax[] = ['inventory_item_id' => $p->id, 'side' => $side, 'issuer_retailer_id' => $owner->id, 'recipient_retailer_id' => $side === 'a' ? $b->id : $a->id] + $fiscal;
                foreach (['taxable_cents', 'tax_cents', 'gross_cents'] as $field) {
                    $flowTax[$side][$field] = $flowTax[$side][$field] === null || $fiscal[$field] === null ? null : BigInteger::of($flowTax[$side][$field])->plus($fiscal[$field])->toInt();
                }
                $rows[] = ['inventory_item_id' => $p->id, 'retailer_id' => $owner->id, 'side' => $side, 'quantity_milliunits' => $q, 'agreed_unit_value_cents' => $price, 'goods_value_cents' => $goods, 'product_snapshot' => ['name' => $p->name, 'sku' => $p->sku, 'ean' => $p->ean, 'brand' => $p->brand, 'category' => $p->category, 'unit' => $p->unit, 'condition' => $p->condition->value, 'retailer_name' => $owner->company_name, 'province' => $p->province, 'approved_price_cents' => $p->zero_price_cents, 'vat_treatment' => $p->vat_treatment]];
            }
        }
        $delivery = [];
        foreach (['a' => $a, 'b' => $b] as $side => $owner) {
            $d = $input['delivery'][$side] ?? [];
            $mode = $d['mode'] ?? null;
            $cost = $this->money($d['cost'] ?? '0');
            $destination = trim((string) ($d['destination'] ?? ''));
            if (strlen($destination) > 1000 || ($mode === 'shipping' && $destination === '')) {
                $this->fail('delivery', 'Indica la destinazione concordata della spedizione.');
            }
            if ($mode === 'pickup' && $cost !== 0) {
                $this->fail('delivery', 'Il ritiro deve avere costo di trasporto zero.');
            }
            $payer = $d['payer_side'] ?? ($side === 'a' ? 'b' : 'a');
            $settlement = $d['settlement'] ?? 'external';
            if (! in_array($payer, ['a', 'b'], true) || ! in_array($settlement, ['external', 'sender'], true)) {
                $this->fail('delivery', 'Condizioni di trasporto non valide.');
            }
            $delivery[$side] = ['payer_side' => $payer, 'payer_retailer_id' => $payer === 'a' ? $a->id : $b->id, 'recipient_side' => $side === 'a' ? 'b' : 'a', 'settlement' => $settlement, 'shipping_tax_snapshot' => ['taxable_cents' => null, 'rate_basis_points' => null, 'tax_cents' => null, 'gross_cents' => $cost === 0 ? 0 : null], 'mode' => $mode, 'shipping_cost_cents' => $cost, 'destination' => $mode === 'shipping' ? $destination : $owner->company_name.' · '.$owner->city.' · '.$owner->province];
            $provisional = $provisional || $cost > 0;
        }
        $goodsA = $flowTax['a']['gross_cents'] ?? $values['a']->toInt();
        $goodsB = $flowTax['b']['gross_cents'] ?? $values['b']->toInt();
        // PDF v1.0: transport reimbursements are represented separately from the goods difference.
        $transportA = $delivery['a']['settlement'] === 'sender' && $delivery['a']['payer_side'] === 'b' ? $delivery['a']['shipping_cost_cents'] : 0;
        $transportB = $delivery['b']['settlement'] === 'sender' && $delivery['b']['payer_side'] === 'a' ? $delivery['b']['shipping_cost_cents'] : 0;
        $va = $goodsA;
        $vb = $goodsB;
        $difference = abs($va - $vb);
        $notes = $input['notes'] ?? null;
        if ($notes !== null && (! is_string($notes) || mb_strlen($notes) > 2000)) {
            $this->fail('notes', 'Le note possono contenere al massimo 2000 caratteri.');
        }
        usort($rows, fn ($x, $y) => $x['inventory_item_id'] <=> $y['inventory_item_id']);
        $snapshot = ['currency' => 'EUR', 'value_a_cents' => $va, 'value_b_cents' => $vb, 'difference_cents' => $difference, 'payer_retailer_id' => $difference === 0 ? null : ($va < $vb ? $a->id : $b->id), 'payee_retailer_id' => $difference === 0 ? null : ($va < $vb ? $b->id : $a->id), 'delivery_a' => $delivery['a'], 'delivery_b' => $delivery['b'], 'tax_snapshot' => ['policy' => 'separate_disposals_v1', 'goods' => $tax, 'disposals' => ['a' => ['issuer_retailer_id' => $a->id, 'recipient_retailer_id' => $b->id, 'agreed_goods_cents' => $values['a']->toInt()] + $flowTax['a'], 'b' => ['issuer_retailer_id' => $b->id, 'recipient_retailer_id' => $a->id, 'agreed_goods_cents' => $values['b']->toInt()] + $flowTax['b']], 'amount_due_b_to_a_cents' => $provisional ? null : $va, 'amount_due_a_to_b_cents' => $provisional ? null : $vb, 'transport_reimbursement_a_cents' => $transportA, 'transport_reimbursement_b_cents' => $transportB, 'shipping_tax_cents' => null, 'description' => 'Due cessioni distinte; conguaglio sui debiti reciproci, trasporto esterno separato.'], 'commission_snapshot' => app(MarketplaceCommission::class)->difference($difference, $difference === 0 ? null : ($va < $vb ? $b->id : $a->id)) + ['provisional' => $provisional], 'payment_provisional' => $provisional, 'parties_snapshot' => ['payment_terms' => ['difference_payment_minutes' => config('marketplace.exchange_difference_payment_minutes')], 'a' => ['id' => $a->id, 'name' => $a->company_name, 'vat_number' => $a->vat_number, 'city' => $a->city], 'b' => ['id' => $b->id, 'name' => $b->company_name, 'vat_number' => $b->vat_number, 'city' => $b->city]], 'notes' => $notes, 'expires_at' => $expiry];

        return [$snapshot, $rows];
    }

    public function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
