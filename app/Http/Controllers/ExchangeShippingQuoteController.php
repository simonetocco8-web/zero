<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExchangeProposalRequest;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Services\ExchangeRules;
use App\Services\MarketplaceCart;
use App\Services\Shipping\ShippingQuoteService;

class ExchangeShippingQuoteController extends Controller
{
    public function __invoke(ExchangeProposalRequest $request, ShippingQuoteService $shipping, ExchangeRules $rules)
    {
        $data = $request->validated();
        $exchange = $request->route('exchange');
        $a = $exchange ? Retailer::findOrFail($exchange->retailer_a_id) : $request->user()->retailer;
        $b = $exchange ? Retailer::findOrFail($exchange->retailer_b_id) : InventoryItem::findOrFail($data['requested_item_id'])->retailer;
        abort_if($a->id === $b->id, 422);
        $rules->eligible($a);
        $rules->eligible($b);
        $result = [];
        foreach (['a' => $a, 'b' => $b] as $side => $origin) {
            if ($data['delivery'][$side]['mode'] !== 'shipping') {
                $result[$side] = [];

                continue;
            }
            $destination = $shipping->origin($side === 'a' ? $b : $a);
            $lines = [];
            foreach ($data['lines'][$side] as $row) {
                $p = InventoryItem::findOrFail($row['inventory_item_id']);
                $q = app(MarketplaceCart::class)->quantity($row['quantity']);
                $rules->product($p, $origin->id, $q);
                $lines[] = ['product' => $p, 'quantity_milliunits' => $q];
            }
            $quotes = $shipping->quote($request->user(), $origin, $lines, $destination, 'exchange:'.$data['key'].':'.$side);
            $result[$side] = array_map(fn ($q) => ['id' => $q->id, 'rate' => $q->rate], $quotes);
        }

        return response()->json(['sides' => $result])->header('Cache-Control', 'private, no-store');
    }
}
