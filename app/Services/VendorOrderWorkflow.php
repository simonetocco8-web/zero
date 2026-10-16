<?php

namespace App\Services;

use App\Enums\MarketplaceNoticeKind as Kind;
use App\Models\MarketplaceRefund;
use App\Models\MarketplaceReturnCase;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorOrder;
use App\Models\VendorOrderEvent;
use App\Services\Payments\RefundMarketplaceVendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorOrderWorkflow
{
    public function advance(User $actor, VendorOrder $vendor, string $target, array $tracking = []): VendorOrder
    {
        Gate::forUser($actor)->authorize('fulfil', $vendor);

        return app(StockReservations::class)->locked($vendor->order, function ($order, $payment) use ($actor, $vendor, $target, $tracking) {
            $v = VendorOrder::lockForUpdate()->findOrFail($vendor->id);
            Gate::forUser($actor)->authorize('fulfil', $v);
            if ($v->status->value === $target) {
                return $v;
            }
            $next = match ($v->status->value) {
                'paid' => ['preparing'],'preparing' => [$v->delivery_mode === 'shipping' ? 'shipped' : 'ready_for_pickup'],'shipped','ready_for_pickup' => ['completed'],default => []
            };
            if ($payment->status !== 'paid' || ! in_array($target, $next, true)) {
                throw ValidationException::withMessages(['status' => 'Transizione non disponibile per lo stato o la consegna corrente.']);
            }
            if (DB::table('marketplace_provider_refunds')->where('vendor_order_id', $v->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Rimborso avviato: evasione sospesa.']);
            }
            $from = $v->status->value;
            $updates = ['status' => $target];
            if ($target === 'shipped') {
                $updates += ['carrier' => $tracking['carrier'] ?? null, 'tracking_reference' => $tracking['tracking_reference'] ?? null, 'shipped_at' => now()];
            }
            if ($target === 'ready_for_pickup') {
                $updates['ready_for_pickup_at'] = now();
            }
            if ($target === 'completed') {
                $updates['completed_at'] = now();
            }
            $v->update($updates);
            VendorOrderEvent::create(['vendor_order_id' => $v->id, 'user_id' => $actor->id, 'from_status' => $from, 'to_status' => $target]);
            $this->aggregate($order);
            $kind = match ($target) {
                'shipped' => Kind::OrderShipped,'ready_for_pickup' => Kind::ReadyForPickup,'completed' => Kind::DeliveryCompleted,default => null
            };
            if ($kind) {
                app(MarketplaceNotices::class)->vendorEvent($v, $kind);
            }

            return $v;
        });
    }

    public function receive(User $buyer, VendorOrder $vendor): VendorOrder
    {
        Gate::forUser($buyer)->authorize('viewDelivery', $vendor);

        return app(StockReservations::class)->locked($vendor->order, function ($order, $payment) use ($buyer, $vendor) {
            $v = VendorOrder::lockForUpdate()->findOrFail($vendor->id);
            Gate::forUser($buyer)->authorize('viewDelivery', $v);
            if ($v->status->value === 'completed') {
                return $v;
            }
            if ($payment->status !== 'paid' || ! in_array($v->status->value, ['shipped', 'ready_for_pickup'], true)) {
                throw ValidationException::withMessages(['status' => 'La consegna non è ancora pronta per la conferma.']);
            }
            if (DB::table('marketplace_provider_refunds')->where('vendor_order_id', $v->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Rimborso avviato: conferma ricezione sospesa.']);
            }
            $from = $v->status->value;
            $v->update(['status' => 'completed', 'completed_at' => now()]);
            VendorOrderEvent::create(['vendor_order_id' => $v->id, 'user_id' => $buyer->id, 'from_status' => $from, 'to_status' => 'completed']);
            $this->aggregate($order);
            app(MarketplaceNotices::class)->vendorEvent($v, Kind::DeliveryCompleted);

            return $v;
        });
    }

    public function refund(User $admin, VendorOrder $vendor, string $reason): MarketplaceRefund|MarketplaceReturnCase
    {
        Gate::forUser($admin)->authorize('accessAdministration', User::class);

        if ($vendor->order->payment->provider === 'stripe_connect') {
            return app(RefundMarketplaceVendor::class)->handle($admin, $vendor, $reason);
        }

        return app(StockReservations::class)->locked($vendor->order, function ($order, $payment) use ($admin, $vendor, $reason) {
            $v = VendorOrder::lockForUpdate()->findOrFail($vendor->id);
            $existing = MarketplaceRefund::where('vendor_order_id', $v->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            app(MarketplaceEconomicRules::class)->sale();
            if ($payment->status !== 'paid' || ! $payment->simulated || $payment->provider !== 'fake_marketplace' || $v->totals_provisional || ! in_array($v->status->value, ['paid', 'preparing', 'shipped', 'ready_for_pickup', 'completed'], true)) {
                throw ValidationException::withMessages(['refund' => 'È rimborsabile soltanto un sottoordine con pagamento fake riuscito e importi definiti.']);
            }
            $returns = app(MarketplaceReturns::class);
            $case = $returns->request($admin, $v, $reason);
            if ($case->phase === 'after_dispatch') {
                return $case;
            }
            $from = $v->status->value;
            $returns->restoreBeforeDispatch($case, $v);
            app(MarketplaceLedger::class)->reverseVendor($v, $case->id);
            $bonus = $payment->funding_snapshot['bonus_by_vendor'][$v->id] ?? 0;
            if ($bonus > 0) {
                app(CustomerPromotionalBonus::class)->refund($order, $bonus, 'vendor:'.$v->id);
            }
            $returns->event($case, $admin, 'customer_refund_simulated', ['amount_cents' => $v->total_cents]);
            $returns->event($case, $admin, 'seller_reversal_or_recovery_recorded', ['commission_cents' => $case->financial_snapshot['commission_reversal_cents']]);
            $case->update(['status' => 'refunded', 'goods_return_snapshot' => ['status' => 'not_dispatched', 'stock_restored' => true]]);
            $refund = MarketplaceRefund::create(['uuid' => (string) Str::uuid(), 'vendor_order_id' => $v->id, 'requested_by' => $admin->id, 'amount_cents' => $v->total_cents, 'currency' => $v->currency, 'status' => 'refunded', 'simulated' => true, 'reason' => $reason]);
            $v->update(['status' => 'refunded']);
            VendorOrderEvent::create(['vendor_order_id' => $v->id, 'user_id' => $admin->id, 'from_status' => $from, 'to_status' => 'refunded']);
            $this->aggregate($order);
            if ($order->fresh()->status->value === 'refunded') {
                $payment->update(['status' => 'refunded']);
            }
            // A refund does not prove physical return; no automatic restock or wallet accounting.
            app(MarketplaceNotices::class)->vendorEvent($v, Kind::Refunded);

            return $refund;
        });
    }

    public function aggregate(Order $order): void
    {
        $states = $order->vendorOrders()->orderBy('id')->lockForUpdate()->get()->pluck('status')->map->value;
        $active = $states->reject(fn ($s) => in_array($s, ['refunded', 'cancelled'], true));
        $state = match (true) {
            $states->every(fn ($s) => $s === 'refunded') => 'refunded',$states->every(fn ($s) => $s === 'cancelled') => 'cancelled',$active->isNotEmpty() && $active->every(fn ($s) => $s === 'completed') => 'completed',$active->isNotEmpty() && $active->every(fn ($s) => in_array($s, ['completed', 'shipped'], true)) => 'shipped',$active->isNotEmpty() && $active->every(fn ($s) => in_array($s, ['completed', 'ready_for_pickup'], true)) => 'ready_for_pickup',$active->every(fn ($s) => $s === 'paid') => 'paid',default => 'preparing'
        };
        $order->update(['status' => $state]);
    }
}
