<?php

namespace App\Services\Shipping;

use Illuminate\Validation\ValidationException;

class ShippingParcelBuilder
{
    public function build(iterable $lines): array
    {
        $parcels = [];
        foreach ($lines as $line) {
            $p = $line['product'];
            $q = $line['quantity_milliunits'];
            $size = $p->only(['length_mm', 'width_mm', 'height_mm', 'weight_g']);
            if (count(array_filter($size, fn ($n) => is_int($n) && $n > 0)) !== 4 || ! $p->units_per_parcel_milliunits || $q <= 0) {
                $this->fail();
            }
            $capacity = $p->units_per_parcel_milliunits;
            if ($p->packing_rule === 'pieces_capacity') {
                if ($p->unit !== 'piece' || $q % 1000 !== 0 || $capacity % 1000 !== 0) {
                    $this->fail();
                }
                // Seller declares that even a partial box uses these full shipping dimensions/weight.
                $count = intdiv($q - 1, $capacity) + 1;
            } elseif ($p->packing_rule === 'whole_parcels' && $q % $capacity === 0) {
                $count = intdiv($q, $capacity);
            } else {
                $this->fail();
            }
            if ($count + count($parcels) > config('shipping.max_parcels')) {
                $this->fail();
            }
            for ($i = 0; $i < $count; $i++) {
                $parcels[] = $size + ['packaging_type' => $p->packaging_type, 'inventory_item_id' => $p->id];
            }
        }
        if (! $parcels) {
            $this->fail();
        }

        return $parcels;
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['shipping' => 'Imballaggio insufficiente o quantità non compatibile con i colli dichiarati. Chiedi al rivenditore di completare i dati.']);
    }
}
