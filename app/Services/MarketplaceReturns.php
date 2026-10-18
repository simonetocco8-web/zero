<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\MarketplaceReturnCase;
use App\Models\MarketplaceReturnEvent;
use App\Models\MarketplaceStockReturn;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\VendorOrder;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Called under the existing ordered stock/order/vendor locks. No provider or payout side effects. */
class MarketplaceReturns
{
    public function request(User $actor, VendorOrder $vendor, string $reason): MarketplaceReturnCase
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Returns require locked order transaction.');
        }
        $existing = MarketplaceReturnCase::where('vendor_order_id', $vendor->id)->whereNotIn('status', ['rejected', 'closed'])->lockForUpdate()->first();
        if ($existing) {
            return $existing;
        }
        $after = $vendor->shipped_at || $vendor->completed_at || in_array($vendor->status->value, ['shipped', 'completed'], true);
        $fees = $vendor->items()->get()->pluck('commission_cents');
        $fee = $fees->contains(null) ? null : BigInteger::sum(...$fees->all())->toInt();
        $serviceTax = $vendor->funds_flow_snapshot['commission_tax'] ?? null;
        $serviceVat = $serviceTax['tax_cents'] ?? null;
        $snapshot = ['currency' => $vendor->currency, 'simulated' => $vendor->order->payment->simulated, 'customer_refund_cents' => $vendor->total_cents, 'commission_reversal_cents' => $fee, 'seller_credit_reversal_cents' => $fee === null ? null : $vendor->total_cents - $fee - ($serviceVat ?? 0), 'commission_service_tax_cents' => $serviceVat, 'customer_refund_operation' => 'separate_from_seller_recovery', 'commission_service_tax' => $serviceTax ?? 'unresolved', 'before_dispatch_full_refund' => ! $after];
        $case = MarketplaceReturnCase::create(['request_key' => (string) Str::uuid(), 'vendor_order_id' => $vendor->id, 'requested_by' => $actor->id, 'phase' => $after ? 'after_dispatch' : 'before_dispatch', 'reason' => $reason, 'requested_amount_cents' => $vendor->total_cents, 'approved_amount_cents' => $after ? null : $vendor->total_cents, 'status' => $after ? 'requested' : 'approved', 'financial_snapshot' => $snapshot, 'goods_return_snapshot' => ['status' => $after ? 'requires_review' : 'not_dispatched', 'stock_restored' => false]]);
        $this->event($case, $actor, $after ? 'return_requested' : 'full_refund_approved', $snapshot);

        return $case;
    }

    public function event(MarketplaceReturnCase $case, User $actor, string $action, array $snapshot): void
    {
        MarketplaceReturnEvent::create(['marketplace_return_case_id' => $case->id, 'user_id' => $actor->id, 'action' => $action, 'snapshot' => $snapshot]);
    }

    public function review(User $admin, MarketplaceReturnCase $case, bool $approved, int $amount, string $notes): MarketplaceReturnCase
    {
        Gate::forUser($admin)->authorize('accessAdministration', User::class);

        app(MarketplaceEconomicRules::class)->sale();

        return app(StockReservations::class)->locked(VendorOrder::findOrFail($case->vendor_order_id)->order, function () use ($admin, $case, $approved, $amount, $notes) {
            $current = MarketplaceReturnCase::lockForUpdate()->findOrFail($case->id);
            if ($current->phase !== 'after_dispatch' || $current->status !== 'requested' || $amount < 0 || $amount > $current->requested_amount_cents || mb_strlen($notes) > 2000) {
                throw ValidationException::withMessages(['return' => 'Valutazione non valida per questa pratica.']);
            }
            $current->update(['status' => $approved ? 'approved' : 'rejected', 'approved_amount_cents' => $approved ? $amount : 0, 'notes' => $notes]);
            $this->event($current, $admin, $approved ? 'return_approved' : 'return_rejected', ['amount_cents' => $approved ? $amount : 0, 'notes' => $notes]);

            return $current;
        });
    }

    public function restoreBeforeDispatch(MarketplaceReturnCase $case, VendorOrder $vendor): void
    {
        if ($case->phase !== 'before_dispatch' || $case->status !== 'approved' || DB::transactionLevel() === 0) {
            throw new \LogicException('Stock restoration requires approved pre-dispatch refund.');
        }
        foreach ($vendor->items()->orderBy('inventory_item_id')->get() as $line) {
            $consumed = StockReservation::where('order_id', $vendor->order_id)->where('inventory_item_id', $line->inventory_item_id)->where('status', 'consumed')->lockForUpdate()->first();
            if (! $consumed) {
                continue;
            }
            $returned = MarketplaceStockReturn::where('order_item_id', $line->id)->lockForUpdate()->get()->sum('quantity_milliunits');
            $quantity = min($line->quantity_milliunits, $consumed->quantity_milliunits) - (int) $returned;
            if ($quantity <= 0) {
                continue;
            }
            $item = InventoryItem::withTrashed()->lockForUpdate()->findOrFail($line->inventory_item_id);
            $updated = BigInteger::of($item->quantity_milliunits)->plus($quantity)->toInt();
            DB::table('inventory_items')->where('id', $item->id)->update(['quantity_milliunits' => $updated, 'stock_version' => DB::raw('stock_version + 1'), 'updated_at' => now()]);
            MarketplaceStockReturn::create(['marketplace_return_case_id' => $case->id, 'order_item_id' => $line->id, 'quantity_milliunits' => $quantity]);
        }
    }
}
