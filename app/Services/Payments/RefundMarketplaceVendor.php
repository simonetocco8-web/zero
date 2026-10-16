<?php

namespace App\Services\Payments;

use App\Enums\MarketplaceNoticeKind;
use App\Models\MarketplaceReturnCase;
use App\Models\User;
use App\Models\VendorOrder;
use App\Models\VendorOrderEvent;
use App\Services\CustomerPromotionalBonus;
use App\Services\MarketplaceEconomicRules;
use App\Services\MarketplaceLedger;
use App\Services\MarketplaceNotices;
use App\Services\MarketplaceReturns;
use App\Services\StockReservations;
use App\Services\VendorOrderWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Persist intent first, call PSP outside locks, finalize only after provider success. */
class RefundMarketplaceVendor
{
    public function handle(User $admin, VendorOrder $vendor, string $reason): MarketplaceReturnCase
    {
        Gate::forUser($admin)->authorize('accessAdministration', User::class);
        app(MarketplaceEconomicRules::class)->sale();
        $id = app(StockReservations::class)->locked($vendor->order, function ($order, $payment) use ($admin, $vendor, $reason) {
            $v = VendorOrder::lockForUpdate()->findOrFail($vendor->id);
            $existing = DB::table('marketplace_provider_refunds')->where('vendor_order_id', $v->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->id;
            }
            if ($payment->provider !== 'stripe_connect' || $payment->simulated || $payment->status !== 'paid' || ! $payment->stripe_payment_intent_id) {
                throw ValidationException::withMessages(['refund' => 'Pagamento marketplace non rimborsabile.']);
            }
            $case = app(MarketplaceReturns::class)->request($admin, $v, $reason);
            if ($case->phase !== 'before_dispatch') {
                return -$case->id;
            }
            $bonus = $payment->funding_snapshot['bonus_by_vendor'][$v->id] ?? 0;

            return DB::table('marketplace_provider_refunds')->insertGetId(['vendor_order_id' => $v->id, 'marketplace_return_case_id' => $case->id, 'requested_by' => $admin->id, 'idempotency_key' => 'vendor-refund:'.$v->id, 'payment_intent_id' => $payment->stripe_payment_intent_id, 'card_cents' => $v->total_cents - $bonus, 'bonus_cents' => $bonus, 'currency' => $v->currency, 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
        });
        if ($id < 0) {
            return MarketplaceReturnCase::findOrFail(-$id);
        }
        $this->process($id);

        return MarketplaceReturnCase::findOrFail(DB::table('marketplace_provider_refunds')->where('id', $id)->value('marketplace_return_case_id'));
    }

    public function process(int $id): void
    {
        $request = DB::table('marketplace_provider_refunds')->find($id);
        if (! $request || $request->status === 'succeeded') {
            return;
        }
        $gateway = app(StripeConnectMarketplaceGateway::class);
        $result = $request->stripe_refund_id ? $gateway->retrieveRefund($request->stripe_refund_id) : $gateway->refund($request->payment_intent_id, (int) $request->card_cents, $request->idempotency_key);
        if (($result['amount'] ?? null) !== (int) $request->card_cents || ($result['currency'] ?? null) !== strtolower($request->currency) || ($result['payment_intent'] ?? null) !== $request->payment_intent_id || ! is_string($result['id'] ?? null)) {
            throw new \LogicException('Provider refund mismatch.');
        }
        $vendor = VendorOrder::findOrFail($request->vendor_order_id);
        app(StockReservations::class)->locked($vendor->order, function ($order, $payment) use ($id, $result, $vendor) {
            $request = DB::table('marketplace_provider_refunds')->where('id', $id)->lockForUpdate()->first();
            if ($request->status === 'succeeded') {
                return;
            }
            if ($request->stripe_refund_id && $request->stripe_refund_id !== $result['id']) {
                throw new \LogicException('Refund idempotency mismatch.');
            }
            $status = $result['status'] ?? null;
            if (! in_array($status, ['succeeded', 'pending', 'requires_action', 'failed', 'canceled'], true)) {
                throw new \LogicException('Unknown refund state.');
            }
            DB::table('marketplace_provider_refunds')->where('id', $id)->update(['stripe_refund_id' => $result['id'], 'status' => $status, 'updated_at' => now()]);
            if ($status !== 'succeeded') {
                return;
            }
            $v = VendorOrder::lockForUpdate()->findOrFail($vendor->id);
            $case = MarketplaceReturnCase::lockForUpdate()->findOrFail($request->marketplace_return_case_id);
            $admin = User::findOrFail($request->requested_by);
            $returns = app(MarketplaceReturns::class);
            $returns->restoreBeforeDispatch($case, $v);
            app(MarketplaceLedger::class)->reverseVendor($v, $case->id);
            if ($request->bonus_cents > 0) {
                app(CustomerPromotionalBonus::class)->refund($order, (int) $request->bonus_cents, 'provider-refund:'.$id);
            }
            $returns->event($case, $admin, 'provider_refund_succeeded', ['provider_refund_id' => $result['id'], 'card_cents' => (int) $request->card_cents, 'bonus_cents' => (int) $request->bonus_cents]);
            $case->update(['status' => 'refunded', 'goods_return_snapshot' => ['status' => 'not_dispatched', 'stock_restored' => true]]);
            $from = $v->status->value;
            $v->update(['status' => 'refunded']);
            VendorOrderEvent::create(['vendor_order_id' => $v->id, 'user_id' => $admin->id, 'from_status' => $from, 'to_status' => 'refunded']);
            app(VendorOrderWorkflow::class)->aggregate($order);
            if ($order->fresh()->status->value === 'refunded') {
                $payment->update(['status' => 'refunded']);
            }
            app(MarketplaceNotices::class)->vendorEvent($v, MarketplaceNoticeKind::Refunded);
        });
    }
}
