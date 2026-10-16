<?php

namespace App\Services;

use App\Models\ExchangeVersion;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\WalletTransaction;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Monetary retailer credit, separate from promotional bonus. Caller holds ordered retailer locks. */
class RetailerCredit
{
    public function available(Retailer $retailer, bool $locked = false): int
    {
        $q = $retailer->walletTransactions()->where('currency', 'EUR')->where('simulated', ! app(MarketplaceEconomicRules::class)->isStripe())->whereNotNull('available_at')->where('available_at', '<=', now());
        $sum = BigInteger::zero();
        foreach (($locked ? $q->lockForUpdate() : $q)->get() as $entry) {
            $sum = $sum->plus($entry->amount_cents);
        }

        return max(0, $sum->toInt());
    }

    public function reserve(Order|ExchangeVersion $source, bool $requested = true): int
    {
        $this->transaction();
        $order = $source instanceof Order;
        $retailer = $order ? ($source->user->role->value === 'retailer' ? $source->user->retailer : null) : Retailer::findOrFail($source->payer_retailer_id);
        if (! $requested || ! $retailer || ! config('marketplace.credit.retailer_spending_enabled')) {
            return 0;
        }
        $order ? app(MarketplaceEconomicRules::class)->sale() : app(MarketplaceEconomicRules::class)->exchange();
        Retailer::lockForUpdate()->findOrFail($retailer->id);
        $existing = $this->hold($source);
        if ($existing) {
            return (int) $existing->amount_cents;
        }
        $cents = min($order ? $source->total_cents : $source->difference_cents, $this->available($retailer, true));
        if ($cents === 0) {
            return 0;
        }
        $id = DB::table('retailer_credit_holds')->insertGetId(['retailer_id' => $retailer->id, 'order_id' => $order ? $source->id : null, 'exchange_version_id' => $order ? null : $source->id, 'amount_cents' => $cents, 'currency' => 'EUR', 'simulated' => ! app(MarketplaceEconomicRules::class)->isStripe(), 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
        $hold = DB::table('retailer_credit_holds')->find($id);
        $this->entry($hold, 'reserve-out', 'credit_reservation', 'available', -$cents);
        $this->entry($hold, 'reserve-in', 'credit_reservation', 'reserved', $cents);

        return $cents;
    }

    public function active(Order|ExchangeVersion $source): bool
    {
        $expected = $source->payment()->value('retailer_credit_cents');
        if (! $expected) {
            return true;
        }
        $hold = $this->hold($source);

        return $hold && (bool) $hold->simulated === ! app(MarketplaceEconomicRules::class)->isStripe() && $hold->status === 'reserved' && (int) $hold->amount_cents === (int) $expected;
    }

    public function finish(Order|ExchangeVersion $source, bool $success): void
    {
        $this->transaction();
        $hold = $this->hold($source);
        if (! $hold) {
            return;
        }
        Retailer::lockForUpdate()->findOrFail($hold->retailer_id);
        if ($hold->status !== 'reserved') {
            if ($success && $hold->status === 'released') {
                throw new \LogicException('Retailer credit already released; reconcile late capture.');
            }

            return;
        }
        $kind = $success ? ($source instanceof Order ? 'purchase_credit_spend' : 'exchange_difference_credit_spend') : 'credit_release';
        $this->entry($hold, 'finish-out', $kind, 'reserved', -(int) $hold->amount_cents);
        if (! $success) {
            $this->entry($hold, 'finish-in', $kind, 'available', (int) $hold->amount_cents);
        }
        DB::table('retailer_credit_holds')->where('id', $hold->id)->update(['status' => $success ? 'spent' : 'released', 'updated_at' => now()]);
    }

    /** Fixed original vendor allocation; each refund is independently idempotent. */
    public function refundVendor(Order $order, int $vendorId): void
    {
        $cents = (int) ($order->payment->funding_snapshot['credit_by_vendor'][$vendorId] ?? 0);
        $this->reverse($order, $cents, 'vendor:'.$vendorId);
    }

    /** Internal assisted exchange reversal; never called from an unverified browser callback. */
    public function reverse(Order|ExchangeVersion $source, int $cents, string $key): void
    {
        $this->transaction();
        if ($cents === 0) {
            return;
        }
        $hold = $this->hold($source);
        if (! $hold || $hold->status !== 'spent' || $cents < 0) {
            throw new \LogicException('Credit reversal requires a spent hold.');
        }
        Retailer::lockForUpdate()->findOrFail($hold->retailer_id);
        $entryKey = 'credit:'.$hold->id.':refund:'.hash('sha256', $key);
        $prior = WalletTransaction::where('idempotency_key', $entryKey)->lockForUpdate()->first();
        if ($prior) {
            if ($prior->amount_cents !== $cents) {
                throw new \LogicException('Credit reversal key reused.');
            }

            return;
        }
        $refunded = BigInteger::zero();
        foreach (WalletTransaction::where('retailer_credit_hold_id', $hold->id)->where('operation_kind', 'credit_refund')->lockForUpdate()->get() as $entry) {
            $refunded = $refunded->plus($entry->amount_cents);
        }
        if ($cents > BigInteger::of($hold->amount_cents)->minus($refunded)->toInt()) {
            throw ValidationException::withMessages(['credit' => 'Rimborso superiore al credito speso.']);
        }
        $this->entry($hold, 'refund:'.hash('sha256', $key), 'credit_refund', 'available', $cents);
    }

    private function hold(Order|ExchangeVersion $source): ?object
    {
        return DB::table('retailer_credit_holds')->where($source instanceof Order ? 'order_id' : 'exchange_version_id', $source->id)->lockForUpdate()->first();
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Retailer credit mutation requires an ordered locked transaction.');
        }
    }

    private function entry(object $hold, string $suffix, string $kind, string $bucket, int $cents): void
    {
        WalletTransaction::create(['retailer_id' => $hold->retailer_id, 'retailer_credit_hold_id' => $hold->id, 'idempotency_key' => 'credit:'.$hold->id.':'.$suffix, 'type' => 'adjustment', 'operation_kind' => $kind, 'entry_bucket' => $bucket, 'amount_cents' => $cents, 'currency' => $hold->currency, 'simulated' => (bool) $hold->simulated, 'description' => 'Credito retailer: '.$kind, 'available_at' => $bucket === 'available' ? now() : null, 'operation_snapshot' => ['order_id' => $hold->order_id, 'exchange_version_id' => $hold->exchange_version_id]]);
    }
}
