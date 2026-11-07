<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\Plan;
use Brick\Math\BigInteger;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Exact, reusable quota projection. Construct under retailer lock for writes. */
class InventoryCapacity
{
    private BigInteger $total;

    private array $values = [];

    private array $skus = [];

    private int $newKey = 0;

    public function __construct(public readonly Plan $plan, Collection $items)
    {
        $this->total = BigInteger::zero();
        foreach ($items as $item) {
            $this->rememberSku($item->sku, $item->id);
            if ($item->trashed() || $item->status->value === 'archived') {
                continue;
            }
            $this->rememberSku($item->proposed_data['sku'] ?? null, $item->id);
            $value = $this->valueFor($item->proposed_data ?? $item->getAttributes(), $item);
            $this->values[$item->id] = $value;
            $this->total = $this->total->plus($value);
        }
    }

    public static function value(array $data): BigInteger
    {
        return BigInteger::of($data['quantity_milliunits'])->multipliedBy($data['zero_price_cents']);
    }

    private function valueFor(array $data, ?InventoryItem $item): BigInteger
    {
        $value = self::value($data);

        return $item?->published_at ? BigInteger::max($value, self::value($item->getAttributes())) : $value;
    }

    public function validate(array $data, ?InventoryItem $target): void
    {
        if (! empty($data['sku'])) {
            foreach (array_keys($this->skus[self::skuKey($data['sku'])] ?? []) as $id) {
                if ($id !== $target?->id) {
                    throw ValidationException::withMessages(['sku' => 'Il codice è già usato da un’altra giacenza.']);
                }
            }
        }
        if (! $target && $this->plan->max_items !== null && count($this->values) >= $this->plan->max_items) {
            throw ValidationException::withMessages(['plan' => 'Hai raggiunto il limite di '.$this->plan->max_items.' articoli.']);
        }
        $total = $this->total->minus($this->values[$target?->id] ?? 0)->plus($this->valueFor($data, $target));
        if ($this->plan->max_inventory_value_cents !== null && $total->isGreaterThan(BigInteger::of($this->plan->max_inventory_value_cents)->multipliedBy(1000))) {
            throw ValidationException::withMessages(['quantity' => 'Il valore totale del magazzino supera il limite del piano.']);
        }
    }

    public function accept(array $data, ?InventoryItem $target): void
    {
        $key = $target?->id ?? --$this->newKey;
        $value = $this->valueFor($data, $target);
        $this->total = $this->total->minus($this->values[$key] ?? 0)->plus($value);
        $this->values[$key] = $value;
        $this->rememberSku($data['sku'] ?? null, $key);
    }

    public function usage(): array
    {
        return ['items' => count($this->values), 'millicents' => (string) $this->total];
    }

    public static function skuKey(string $sku): string
    {
        // Preserve the source value; this key only reserves case/accent variants conservatively.
        $lower = mb_strtolower(trim($sku));

        return preg_replace_callback('/\p{Latin}/u', fn ($match) => Str::ascii($match[0]), $lower) ?? $lower;
    }

    private function rememberSku(?string $sku, int $id): void
    {
        if ($sku !== null && $sku !== '') {
            $this->skus[self::skuKey($sku)][$id] = true;
        }
    }
}
