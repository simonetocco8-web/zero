<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Support\ExactInteger;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class InventoryRules
{
    public function data(array $input): array
    {
        $data = Arr::only($input, ['name', 'brand', 'sku', 'ean', 'condition', 'province', 'description', 'delivery_notes', 'vat_treatment']);
        $category = Category::findOrFail($input['category_id']);
        $data['category_id'] = $category->id;
        $data['category'] = $category->name; // Legacy snapshot, retained for compatibility.
        $data['unit'] = $input['unit'] ?? 'piece';
        if (isset($input['shipping_cost']) && $input['shipping_cost'] !== '' && (! is_string($input['shipping_cost']) && ! is_int($input['shipping_cost']) || ! preg_match('/^[0-9]{1,9}(?:[.,][0-9]{1,2})?$/D', (string) $input['shipping_cost']))) {
            $this->fail('shipping_cost', 'Inserisci un costo esatto e non negativo, con al massimo due decimali.');
        }
        $data['shipping_cost_cents'] = isset($input['shipping_cost']) && $input['shipping_cost'] !== '' ? BigDecimal::of(str_replace(',', '.', $input['shipping_cost']))->multipliedBy(100)->toBigInteger()->toInt() : null;
        $data['quantity_milliunits'] = BigDecimal::of(str_replace(',', '.', $input['quantity']))->multipliedBy(1000)->toBigInteger()->toInt();
        if ($data['quantity_milliunits'] <= 0) {
            $this->fail('quantity', 'Inserisci una quantità maggiore di zero.');
        }
        foreach (['zero', 'list'] as $price) {
            $data[$price.'_price_cents'] = isset($input[$price.'_price']) ? BigDecimal::of(str_replace(',', '.', $input[$price.'_price']))->multipliedBy(100)->toBigInteger()->toInt() : null;
        }
        if ($data['list_price_cents'] !== null && $data['zero_price_cents'] > $data['list_price_cents']) {
            $this->fail('zero_price', 'Il prezzo ZeroMagazzino non può superare il prezzo di listino.');
        }
        foreach (['pickup', 'shipping', 'exchange'] as $flag) {
            $data[$flag.'_available'] = (bool) ($input[$flag.'_available'] ?? false);
        }
        $data['currency'] = 'EUR';

        return $data;
    }

    public function validate(Retailer $retailer, array $data, ?InventoryItem $target = null): void
    {
        if ($target && $data['quantity_milliunits'] < $target->quantity_milliunits - app(StockReservations::class)->available($target)) {
            $this->fail('quantity', 'La quantità fisica non può essere inferiore alle prenotazioni attive.');
        }
        if (isset($data['category_id'])) {
            $category = Category::sharedLock()->find($data['category_id']);
            $existingIds = [$target?->category_id, $target?->proposed_data['category_id'] ?? null];
            if (! $category || (! $category->is_active && ! in_array($category->id, $existingIds, true))) {
                $this->fail('category_id', 'Seleziona una categoria attiva.');
            }
        } elseif (! $target) {
            $this->fail('category_id', 'Seleziona una categoria.');
        }
        if (isset($data['shipping_cost_cents']) && ExactInteger::parse($data['shipping_cost_cents']) < 0) {
            $this->fail('shipping_cost', 'Il costo deve essere non negativo.');
        }
        if (isset($data['vat_treatment']) && ! array_key_exists($data['vat_treatment'], config('marketplace.vat_treatments'))) {
            $this->fail('vat_treatment', 'Trattamento IVA non configurato.');
        }
        $subscription = $retailer->activeSubscription()->with('plan')->lockForUpdate()->first();
        if (! $subscription || ($subscription->ends_at && $subscription->ends_at <= now()) || ($subscription->starts_at && $subscription->starts_at > now())) {
            $this->fail('plan', 'È necessario un piano attivo per gestire le giacenze.');
        }
        $plan = Plan::sharedLock()->findOrFail($subscription->plan_id);
        if ($data['exchange_available'] && ! $plan->exchange_available) {
            $this->fail('exchange_available', 'Lo scambio richiede un piano PRO attivo.');
        }
        // Current locking read: MySQL REPEATABLE READ snapshots may predate the retailer lock.
        $allItems = $retailer->inventoryItems()->lockForUpdate()->get();
        if (! empty($data['sku']) && $allItems->contains(fn ($item) => $item->id !== $target?->id && $item->sku === $data['sku'])) {
            $this->fail('sku', 'Il codice è già usato da un’altra giacenza.');
        }
        $items = $allItems->filter(fn ($item) => $item->status->value !== 'archived');
        if (! $target && $plan->max_items !== null && $items->count() >= $plan->max_items) {
            $this->fail('plan', 'Hai raggiunto il limite di '.$plan->max_items.' articoli.');
        }
        $sum = BigInteger::zero();
        foreach ($items as $item) {
            $candidate = $item->id === $target?->id ? $data : ($item->proposed_data ?? $item->getAttributes());
            if ($item->id !== $target?->id && ! empty($data['sku']) && ($item->sku === $data['sku'] || ($item->proposed_data['sku'] ?? null) === $data['sku'])) {
                $this->fail('sku', 'Il codice è già usato da un’altra giacenza.');
            }
            $value = $this->value($candidate);
            if ($item->published_at) {
                $value = BigInteger::max($value, $this->value($item->getAttributes()));
            }
            $sum = $sum->plus($value);
        }
        if (! $target) {
            $sum = $sum->plus($this->value($data));
        }
        if ($plan->max_inventory_value_cents !== null && $sum->isGreaterThan(BigInteger::of($plan->max_inventory_value_cents)->multipliedBy(1000))) {
            $this->fail('quantity', 'Il valore totale del magazzino supera il limite del piano.');
        }
    }

    private function value(array $data): BigInteger
    {
        return BigInteger::of($data['quantity_milliunits'])->multipliedBy($data['zero_price_cents']);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
