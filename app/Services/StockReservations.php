<?php

namespace App\Services;

use App\Enums\MarketplaceNoticeKind;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Retailer;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockReservations
{
    /** Authoritative current read inside transactions, never a repeatable-read SUM snapshot. */
    public function available(InventoryItem $item): int
    {
        $query = StockReservation::where('inventory_item_id', $item->id)->where('status', 'active')->where('expires_at', '>', now());
        $reserved = DB::transactionLevel() > 0 ? $query->orderBy('id')->lockForUpdate()->get()->sum('quantity_milliunits') : $query->sum('quantity_milliunits');

        return max(0, $item->quantity_milliunits - (int) $reserved);
    }

    /** Acquire the same ordered database locks for initial reservation and idempotent retry. */
    public function reserveOrder(Order $order): void
    {
        $this->locked($order, function ($current) {
            $this->reserveOrderLocked($current);
        });
    }

    private function reserveOrderLocked(Order $order): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock reservation requires a transaction and product locks.');
        }
        app(MarketplaceEconomicRules::class)->sale();
        if ($order->status->value !== 'payment_pending') {
            $this->fail();
        }
        $minutes = config('marketplace.reservation_minutes');
        if ((! is_string($minutes) && ! is_int($minutes)) || ! preg_match('/^[0-9]{1,4}$/D', (string) $minutes) || $minutes < 1 || $minutes > 1440) {
            throw new \LogicException('MARKETPLACE_RESERVATION_MINUTES must be an integer from 1 to 1440.');
        }
        $expiry = now()->addMinutes((int) $minutes);
        foreach ($this->lines($order) as $line) {
            $item = InventoryItem::lockForUpdate()->findOrFail($line->inventory_item_id);
            $key = 'order:'.$order->checkout_key.':item:'.$item->id;
            $existing = StockReservation::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->order_id !== $order->id || $existing->quantity_milliunits !== $line->quantity_milliunits || $existing->status->value !== 'active' || $existing->expires_at <= now()) {
                    $this->fail();
                }

                continue;
            }
            if ($line->quantity_milliunits <= 0 || $line->quantity_milliunits > $this->available($item)) {
                $this->fail();
            }
            StockReservation::create(['inventory_item_id' => $item->id, 'user_id' => $order->user_id, 'order_id' => $order->id, 'quantity_milliunits' => $line->quantity_milliunits, 'expires_at' => $expiry, 'idempotency_key' => $key]);
        }
    }

    public function lines(Order $order)
    {
        return OrderItem::whereIn('vendor_order_id', $order->vendorOrders()->select('id'))->orderBy('inventory_item_id')->get();
    }

    /** Global mutation lock order: retailers, products, order, payment, reservations. */
    public function locked(Order $order, callable $callback): mixed
    {
        return DB::transaction(function () use ($order, $callback) {
            $lines = $this->lines($order);
            $retailers = InventoryItem::whereIn('id', $lines->pluck('inventory_item_id'))->orderBy('retailer_id')->pluck('retailer_id')->push($order->user->retailer?->id)->filter()->unique()->sort();
            foreach ($retailers as $id) {
                Retailer::lockForUpdate()->findOrFail($id);
            }
            $items = InventoryItem::whereIn('id', $lines->pluck('inventory_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = Order::lockForUpdate()->findOrFail($order->id);
            $payment = $current->payment()->lockForUpdate()->firstOrFail();
            $reservations = StockReservation::where('order_id', $order->id)->orderBy('inventory_item_id')->lockForUpdate()->get();

            return $callback($current, $payment, $reservations, $items, $lines);
        }, 5);
    }

    public function marketable($items): bool
    {
        $retailers = Retailer::whereIn('id', $items->pluck('retailer_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $items->isNotEmpty() && $items->every(fn ($p) => in_array($p->status->value, ['published', 'change_pending'], true) && $p->published_at !== null && $retailers->get($p->retailer_id)?->status->value === 'approved');
    }

    public function isActive($order, $reservations, $lines): bool
    {
        return $order->status->value === 'payment_pending' && $lines->isNotEmpty() && $reservations->count() === $lines->count() && $lines->every(function ($line) use ($reservations, $order) {
            $r = $reservations->firstWhere('inventory_item_id', $line->inventory_item_id);

            return $r && $r->user_id === $order->user_id && $r->quantity_milliunits === $line->quantity_milliunits && $r->status->value === 'active' && $r->expires_at > now();
        });
    }

    public function release(Order $order, string $reason = 'cancelled'): void
    {
        if (! in_array($reason, ['cancelled', 'failed', 'expired'], true)) {
            throw new \InvalidArgumentException('Invalid release reason.');
        }
        $this->locked($order, function ($order, $payment, $reservations) use ($reason) {
            $this->releaseLocked($order, $payment, $reservations, $reason);
        });
    }

    public function releaseLocked($order, $payment, $reservations, string $reason): void
    {
        if ($order->status->value !== 'payment_pending') {
            return;
        }
        foreach ($reservations as $r) {
            if ($r->status->value === 'active') {
                $r->update(['status' => $r->expires_at <= now() ? 'expired' : 'released', 'released_at' => now()]);
            }
        }
        $order->update(['status' => 'cancelled']);
        $order->vendorOrders()->update(['status' => 'cancelled']);
        app(CustomerPromotionalBonus::class)->finish($order, false);
        app(RetailerCredit::class)->finish($order, false);
        $payment->update(['status' => $reason]);
        app(MarketplaceNotices::class)->orderEvent($order, MarketplaceNoticeKind::Cancelled);
    }

    public function expire(): int
    {
        $count = 0;
        // Bounded batches, no cache needed for correctness. Each order is rechecked under locks.
        StockReservation::where('status', 'active')->where('expires_at', '<=', now())->whereNotNull('order_id')->select('id', 'order_id')->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows->pluck('order_id')->unique() as $id) {
                $this->locked(Order::findOrFail($id), function ($order, $payment, $reservations) use (&$count) {
                    if ($order->status->value === 'payment_pending' && $reservations->contains(fn ($r) => $r->status->value === 'active' && $r->expires_at <= now())) {
                        $this->releaseLocked($order, $payment, $reservations, 'expired');
                        $count++;
                    }
                });
            }
        });
        StockReservation::where('status', 'active')->where('expires_at', '<=', now())->whereNull('order_id')->whereNull('exchange_id')->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$count) {
                    $product = InventoryItem::findOrFail($row->inventory_item_id);
                    Retailer::lockForUpdate()->findOrFail($product->retailer_id);
                    InventoryItem::lockForUpdate()->findOrFail($product->id);
                    $r = StockReservation::lockForUpdate()->findOrFail($row->id);
                    if ($r->status->value === 'active' && $r->expires_at <= now()) {
                        $r->update(['status' => 'expired', 'released_at' => now()]);
                        $count++;
                    }
                }, 5);
            }
        });

        return $count + app(ExchangeWorkflow::class)->expire();
    }

    public function fail(): never
    {
        throw ValidationException::withMessages(['quantity' => 'Quantità non disponibile o prenotazione scaduta. Crea un nuovo checkout.']);
    }
}
