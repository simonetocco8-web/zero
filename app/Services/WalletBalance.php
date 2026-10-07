<?php

namespace App\Services;

use App\Enums\WalletTransactionType;
use App\Models\Retailer;
use App\Support\ExactInteger;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Builder;

class WalletBalance
{
    public function availableCents(Retailer $retailer, string $currency = 'EUR'): int
    {
        return ExactInteger::parse($this->maturedEntries($retailer, $currency)->sum('amount_cents'));
    }

    /** Current locking read, never a repeatable-read snapshot aggregate. Caller locks retailer first. */
    public function availableCentsLocked(Retailer $retailer, string $currency = 'EUR'): int
    {
        $sum = BigInteger::zero();
        foreach ($this->maturedEntries($retailer, $currency)->lockForUpdate()->get() as $entry) {
            $sum = $sum->plus($entry->amount_cents);
        }

        return $sum->toInt();
    }

    public function netEarnedCents(Retailer $retailer, string $currency = 'EUR'): int
    {
        return ExactInteger::parse($this->maturedEntries($retailer, $currency)->whereNotNull('sale_item_id')->sum('amount_cents'));
    }

    public function totalCents(Retailer $retailer, string $currency = 'EUR'): int
    {
        // Reservations change availability, not the actual credit owed to the retailer.
        return ExactInteger::parse($this->entries($retailer, $currency)
            ->whereNotIn('type', $this->reservationTypes())->sum('amount_cents'));
    }

    public function reservedCents(Retailer $retailer, string $currency = 'EUR'): int
    {
        $sum = ExactInteger::parse($this->maturedEntries($retailer, $currency)
            ->whereIn('type', $this->reservationTypes())->sum('amount_cents'));

        return BigInteger::of($sum)->negated()->toInt();
    }

    private function entries(Retailer $retailer, string $currency): Builder
    {
        return $retailer->walletTransactions()->getQuery()->where('currency', $currency);
    }

    private function maturedEntries(Retailer $retailer, string $currency): Builder
    {
        return $this->entries($retailer, $currency)->whereNotNull('available_at')->where('available_at', '<=', now());
    }

    private function reservationTypes(): array
    {
        return [WalletTransactionType::PayoutReservation->value, WalletTransactionType::PayoutRelease->value];
    }
}
