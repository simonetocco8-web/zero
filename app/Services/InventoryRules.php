<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Services\InventoryImport\ValidationSnapshot;
use App\Support\ExactInteger;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class InventoryRules
{
    public function data(array $input, ?Category $category = null): array
    {
        $data = Arr::only($input, ['name', 'brand', 'sku', 'ean', 'condition', 'province', 'description', 'delivery_notes', 'vat_treatment']);
        foreach (['', 'shipping_'] as $prefix) {
            $treatment = $input[$prefix.'vat_treatment'] ?? null;
            $data[$prefix.'vat_treatment'] = $treatment === '' ? null : $treatment;
            $value = $input[$prefix.'vat_rate'] ?? null;
            if ($value !== null && $value !== '' && ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[0-9]{1,3}(?:[.,][0-9]{1,2})?$/D', (string) $value))) {
                $this->fail($prefix.'vat_rate', 'Inserisci un’aliquota tra 0 e 100 con al massimo due decimali.');
            }
            $data[$prefix.'vat_rate_basis_points'] = $value === null || $value === '' ? null : BigDecimal::of(str_replace(',', '.', (string) $value))->multipliedBy(100)->toBigInteger()->toInt();
        }
        $this->validateVat($data);
        $category ??= Category::findOrFail($input['category_id']);
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

    public function validate(Retailer $retailer, array $data, ?InventoryItem $target = null, ?InventoryCapacity $capacity = null, ?ValidationSnapshot $preview = null): void
    {
        if ($target && $data['quantity_milliunits'] < $target->quantity_milliunits - app(StockReservations::class)->available($target)) {
            $this->fail('quantity', 'La quantità fisica non può essere inferiore alle prenotazioni attive.');
        }
        if (isset($data['category_id'])) {
            $category = $preview ? $preview->categories->get($data['category_id']) : Category::sharedLock()->find($data['category_id']);
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
        $this->validateVat($data);
        $subscription = $preview?->subscription ?? $retailer->activeSubscription()->with('plan')->lockForUpdate()->first();
        if (! $subscription || ($subscription->ends_at && $subscription->ends_at <= now()) || ($subscription->starts_at && $subscription->starts_at > now())) {
            $this->fail('plan', 'È necessario un piano attivo per gestire le giacenze.');
        }
        $plan = $preview?->plan ?? Plan::sharedLock()->findOrFail($subscription->plan_id);
        if ($data['exchange_available'] && ! $plan->exchange_available) {
            $this->fail('exchange_available', 'Lo scambio richiede un piano PRO attivo.');
        }
        // The batch projection is built after taking the same retailer lock used by all inventory writers.
        $capacity ??= new InventoryCapacity($plan, $retailer->inventoryItems()->withTrashed()->lockForUpdate()->get());
        $capacity->validate($data, $target);
    }

    private function validateVat(array $data): void
    {
        foreach (['', 'shipping_'] as $prefix) {
            $treatment = $data[$prefix.'vat_treatment'] ?? null;
            $rate = $data[$prefix.'vat_rate_basis_points'] ?? null;
            if ($treatment !== null && (! is_string($treatment) || ! array_key_exists($treatment, config('marketplace.vat_treatments')))) {
                $this->fail($prefix.'vat_treatment', 'Trattamento IVA non configurato.');
            }
            if ($rate !== null && (! is_int($rate) || $rate < 0 || $rate > 10000)) {
                $this->fail($prefix.'vat_rate', 'Inserisci un’aliquota tra 0 e 100 con al massimo due decimali.');
            }
            if ($rate !== null && ($treatment === null || ($treatment === 'not_applicable' && $rate !== 0))) {
                $this->fail($prefix.'vat_rate', 'L’aliquota richiede un trattamento IVA coerente. Per IVA non applicabile lascia vuoto o indica zero.');
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
