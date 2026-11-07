<?php

namespace App\View;

use App\Models\InventoryItem;
use App\Support\DisplayMoney;
use App\Support\DisplayQuantity;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;

/** Read-only presentation of approved/proposed attributes; never saves the cloned model. */
class InventoryReviewComparison
{
    private const FIELDS = [
        'name' => 'Nome', 'brand' => 'Marca', 'category' => 'Categoria', 'sku' => 'SKU', 'ean' => 'EAN', 'condition' => 'Condizione', 'province' => 'Provincia',
        'length_mm' => 'Lunghezza del collo', 'width_mm' => 'Larghezza del collo', 'height_mm' => 'Altezza del collo', 'weight_g' => 'Peso lordo del collo',
        'packaging_type' => 'Tipologia di imballaggio', 'packing_rule' => 'Regola di confezionamento', 'units_per_parcel_milliunits' => 'Unità per collo',
        'quantity_milliunits' => 'Quantità', 'unit' => 'Unità di vendita', 'zero_price_cents' => 'Prezzo Zero', 'list_price_cents' => 'Prezzo di listino', 'currency' => 'Valuta',
        'pickup_available' => 'Ritiro disponibile', 'shipping_available' => 'Spedizione disponibile', 'exchange_available' => 'Scambio disponibile', 'shipping_cost_cents' => 'Costo spedizione legacy',
        'vat_treatment' => 'Trattamento IVA merce', 'vat_rate_basis_points' => 'Aliquota IVA merce', 'shipping_vat_treatment' => 'Trattamento IVA spedizione', 'shipping_vat_rate_basis_points' => 'Aliquota IVA spedizione',
        'description' => 'Descrizione completa', 'delivery_notes' => 'Note di consegna',
    ];

    public function rows(InventoryItem $item): array
    {
        $proposed = clone $item;
        $proposed->fill($item->proposed_data ?? []);
        $rows = [];
        foreach (self::FIELDS as $key => $label) {
            $before = $this->format($item, $key);
            $after = $this->format($proposed, $key);
            $rows[] = ['key' => $key, 'label' => $label, 'published' => $before, 'proposed' => $after, 'changed' => $before !== $after];
        }

        return $rows;
    }

    public function photosChanged(InventoryItem $item): bool
    {
        if (! $item->proposed_data) {
            return false;
        }
        $current = $item->images->sortBy('position')->map->only(['disk', 'path'])->values()->all();
        $proposed = array_values(array_map(fn ($photo) => Arr::only($photo, ['disk', 'path']), ($item->proposed_images ?? [])));

        return $current !== $proposed;
    }

    private function format(InventoryItem $item, string $key): string
    {
        $value = $item->{$key};
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        if ($value === null || $value === '') {
            return 'Non indicato';
        }
        if (in_array($key, ['length_mm', 'width_mm', 'height_mm'], true)) {
            return str_replace('.', ',', (string) BigDecimal::of($value)->dividedBy(10, 1)->strippedOfTrailingZeros()).' cm';
        }
        if ($key === 'weight_g') {
            return DisplayQuantity::format($value).' kg';
        }
        if (in_array($key, ['quantity_milliunits', 'units_per_parcel_milliunits'], true)) {
            return DisplayQuantity::format($value).' '.$item->unit;
        }
        if (str_ends_with($key, '_cents')) {
            return DisplayMoney::euros($value);
        }
        if (str_ends_with($key, '_basis_points')) {
            return DisplayMoney::percent($value);
        }
        if (str_ends_with($key, '_available')) {
            return $value ? 'Sì' : 'No';
        }
        if (str_ends_with($key, 'vat_treatment')) {
            return config('marketplace.vat_treatments.'.$value) ?? (string) $value;
        }
        if ($key === 'packing_rule') {
            return match ($value) {
                'whole_parcels' => 'Solo multipli esatti di un collo', 'pieces_capacity' => 'Pezzi: capacità massima per collo', default => (string) $value
            };
        }
        if ($key === 'condition') {
            return match ($value) {
                'new' => 'Nuovo', 'end_of_line' => 'Fine serie', 'old_stock' => 'Fondo magazzino', 'damaged_packaging' => 'Imballo danneggiato', default => (string) $value
            };
        }

        return (string) $value;
    }
}
