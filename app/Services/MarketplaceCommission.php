<?php

namespace App\Services;

use App\Support\DisplayMoney;
use App\Support\MoneyMath;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

/** Approved rates, captured when an operation is agreed, never recomputed on payment. */
class MarketplaceCommission
{
    public function saleRate(string $planCode): int
    {
        return app(MarketplaceEconomicRules::class)->saleRate($planCode);
    }

    public function saleLabel(string $planCode): string
    {
        return in_array($planCode, ['free', 'pro'], true) ? DisplayMoney::percent($this->saleRate($planCode)) : 'Commissione marketplace da approvare';
    }

    public function serviceTax(int $fee, ?int $retailer): array
    {
        $treatment = config('marketplace.commission_tax.treatment');
        $rate = config('marketplace.commission_tax.rate_basis_points');
        $known = $treatment === 'not_applicable' || ($treatment === 'excluded' && (is_int($rate) || is_string($rate)) && preg_match('/^[0-9]{1,5}$/D', (string) $rate) && (int) $rate <= 10000);
        $tax = $known ? ($treatment === 'not_applicable' ? 0 : MoneyMath::commissionCents($fee, (int) $rate)) : null;

        return ['issuer' => 'ZeroMagazzino', 'recipient_retailer_id' => $retailer, 'taxable_cents' => $fee, 'rate_basis_points' => $known ? ($treatment === 'not_applicable' ? null : (int) $rate) : null, 'treatment' => $treatment, 'tax_cents' => $tax, 'gross_cents' => $tax === null ? null : BigInteger::of($fee)->plus($tax)->toInt()];
    }

    public function difference(int $paidDifference, ?int $receiver): array
    {
        app(MarketplaceEconomicRules::class)->exchange();
        $rate = app(MarketplaceEconomicRules::class)->exchangeRate();
        $fee = MoneyMath::commissionCents($paidDifference, $rate);

        return ['policy' => 'approved_difference_v1', 'basis_points' => $rate, 'base_cents' => $paidDifference, 'amount_cents' => $fee, 'charged_retailer_id' => $receiver, 'recipient_stripe_account_id' => $receiver ? DB::table('marketplace_connected_accounts')->where('retailer_id', $receiver)->value('stripe_account_id') : null, 'recipient_net_cents' => $paidDifference - $fee, 'service_tax' => $this->serviceTax($fee, $receiver), 'psp_cost_cents' => null];
    }
}
