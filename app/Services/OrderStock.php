<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\SaleItem;
use App\Models\VendorOrder;
use Brick\Math\BigInteger;

class OrderStock
{
    /** Caller holds retailer/order/item locks; the snapshot rule applies to historic sales too. */
    public function restore(SaleItem $item, VendorOrder $order): void
    {
        if ($order->stock_rule !== 'restore_on_full_refund' || $item->stock_deducted_milliunits <= $item->stock_restored_milliunits) {
            return;
        }
        $product = InventoryItem::whereKey($item->inventory_item_id)->lockForUpdate()->firstOrFail();
        $quantity = BigInteger::of($product->quantity_milliunits)->plus($item->stock_deducted_milliunits - $item->stock_restored_milliunits)->toInt();
        $product->update(['quantity_milliunits' => $quantity]);
        $item->update(['stock_restored_milliunits' => $item->stock_deducted_milliunits]);
    }
}
