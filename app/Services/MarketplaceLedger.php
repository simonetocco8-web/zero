<?php

namespace App\Services;

use App\Models\ExchangeVersion;
use App\Models\Retailer;
use App\Models\VendorOrder;
use App\Models\WalletTransaction;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Internal accounting boundary. Fake entries never become legacy withdrawable credit. */
class MarketplaceLedger
{
    public function append(Retailer $retailer, string $key, string $kind, string $bucket, int $amount, array $source, bool $simulated = true): ?WalletTransaction
    {
        if (! $simulated && ! app(MarketplaceEconomicRules::class)->isStripe()) {
            throw ValidationException::withMessages(['economic_rules' => 'Il ledger marketplace reale non è abilitato.']);
        }
        if (! empty($source['exchange_version_id'])) {
            app(MarketplaceEconomicRules::class)->exchange();
        } else {
            app(MarketplaceEconomicRules::class)->sale();
        }
        if (! in_array($kind, ['pending_credit', 'commission', 'commission_tax', 'credit_release', 'reservation', 'reservation_release', 'payout', 'reversal', 'recovery', 'commission_reversal'], true) || ! in_array($bucket, ['pending', 'available', 'reserved'], true)) {
            throw new \InvalidArgumentException('Invalid ledger operation.');
        }
        if ($amount === 0) {
            return null;
        }

        return DB::transaction(function () use ($retailer, $key, $kind, $bucket, $amount, $source, $simulated) {
            Retailer::lockForUpdate()->findOrFail($retailer->id);
            $data = ['retailer_id' => $retailer->id, 'idempotency_key' => $key, 'type' => 'adjustment', 'operation_kind' => $kind, 'entry_bucket' => $bucket, 'amount_cents' => $amount, 'currency' => 'EUR', 'simulated' => $simulated] + $source;
            $existing = WalletTransaction::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                foreach ($data as $field => $value) {
                    if (($field === 'operation_snapshot' ? $existing->operation_snapshot : ($existing->getRawOriginal($field) ?? null)) != $value) {
                        throw new \LogicException('Ledger idempotency key reused with different data.');
                    }
                }

                return $existing;
            }

            return WalletTransaction::create($data + ['description' => 'Operazione marketplace '.$kind, 'available_at' => $bucket === 'available' ? now() : null]);
        }, 5);
    }

    public function creditVendor(VendorOrder $vendor): void
    {
        $fee = $vendor->items()->get()->pluck('commission_cents');
        if ($vendor->totals_provisional || $fee->contains(null)) {
            throw new \LogicException('Cannot book unresolved marketplace amounts.');
        }
        $r = Retailer::findOrFail($vendor->retailer_id);
        $simulated = $vendor->order->payment->simulated;
        $source = ['vendor_order_id' => $vendor->id];
        $this->append($r, 'vendor:'.$vendor->id.':credit', 'pending_credit', 'pending', $vendor->total_cents, $source, $simulated);
        $this->append($r, 'vendor:'.$vendor->id.':fee', 'commission', 'pending', -BigInteger::sum(...$fee->all())->toInt(), $source, $simulated);
        $tax = $vendor->funds_flow_snapshot['commission_tax']['tax_cents'] ?? null;
        if (! $simulated && $tax === null) {
            throw new \LogicException('Commission VAT snapshot unresolved.');
        }
        if ($tax) {
            $this->append($r, 'vendor:'.$vendor->id.':fee-tax', 'commission_tax', 'pending', -$tax, $source, $simulated);
        }
    }

    public function creditExchange(ExchangeVersion $v): void
    {
        if ($v->difference_cents === 0) {
            return;
        }
        if ($v->payment_provisional || ($v->commission_snapshot['policy'] ?? null) !== 'approved_difference_v1') {
            throw new \LogicException('Exchange financial snapshot unresolved or historical.');
        }
        $r = Retailer::findOrFail($v->payee_retailer_id);
        $simulated = $v->payment->simulated;
        $source = ['exchange_version_id' => $v->id];
        $this->append($r, 'exchange:'.$v->id.':credit', 'pending_credit', 'pending', $v->difference_cents, $source, $simulated);
        $this->append($r, 'exchange:'.$v->id.':fee', 'commission', 'pending', -$v->commission_snapshot['amount_cents'], $source, $simulated);
        $tax = $v->commission_snapshot['service_tax']['tax_cents'] ?? null;
        if (! $simulated && $tax === null) {
            throw new \LogicException('Exchange commission VAT snapshot unresolved.');
        }
        if ($tax) {
            $this->append($r, 'exchange:'.$v->id.':fee-tax', 'commission_tax', 'pending', -$tax, $source, $simulated);
        }
    }

    public function reverseVendor(VendorOrder $vendor, int $caseId): void
    {
        $r = Retailer::findOrFail($vendor->retailer_id);
        foreach (WalletTransaction::where('vendor_order_id', $vendor->id)->whereIn('operation_kind', ['pending_credit', 'commission', 'commission_tax'])->lockForUpdate()->get() as $original) {
            $released = WalletTransaction::where('reverses_transaction_id', $original->id)->where('operation_kind', 'credit_release')->where('entry_bucket', 'available')->exists();
            $this->append($r, 'return:'.$caseId.':reverse:'.$original->id, in_array($original->operation_kind, ['commission', 'commission_tax'], true) ? 'commission_reversal' : ($released ? 'recovery' : 'reversal'), $released ? 'available' : 'pending', -$original->amount_cents, ['vendor_order_id' => $vendor->id, 'marketplace_return_case_id' => $caseId, 'reverses_transaction_id' => $original->id], $original->simulated);
        }
    }

    public function releaseVendor(VendorOrder $vendor): void
    {
        DB::transaction(function () use ($vendor) {
            $r = Retailer::lockForUpdate()->findOrFail($vendor->retailer_id);
            $current = VendorOrder::lockForUpdate()->findOrFail($vendor->id);
            if (! in_array($current->status->value, ['paid', 'preparing', 'shipped', 'ready_for_pickup', 'completed'], true)) {
                throw new \LogicException('Cannot release credit for unpaid or refunded vendor order.');
            }
            foreach (WalletTransaction::where('vendor_order_id', $vendor->id)->whereIn('operation_kind', ['pending_credit', 'commission', 'commission_tax'])->lockForUpdate()->get() as $entry) {
                if (! $entry->simulated) {
                    throw new \LogicException('Provider funds and documented delivery +72h must be reconciled before release.');
                }
                $source = ['vendor_order_id' => $vendor->id, 'reverses_transaction_id' => $entry->id];
                $this->append($r, 'entry:'.$entry->id.':release-pending', 'credit_release', 'pending', -$entry->amount_cents, $source, $entry->simulated);
                $this->append($r, 'entry:'.$entry->id.':release-available', 'credit_release', 'available', $entry->amount_cents, $source, $entry->simulated);
            }
        }, 5);
    }

    public function balances(Retailer $retailer, bool $simulated = true): array
    {
        $result = ['pending' => 0, 'available' => 0, 'reserved' => 0];
        foreach ($result as $bucket => $_) {
            $sum = BigInteger::zero();
            foreach ($retailer->walletTransactions()->where('simulated', $simulated)->where('entry_bucket', $bucket)->get() as $entry) {
                $sum = $sum->plus($entry->amount_cents);
            }$result[$bucket] = $sum->toInt();
        }

        return $result;
    }
}
