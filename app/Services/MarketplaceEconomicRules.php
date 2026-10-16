<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Validate implemented strategies, never infer a commercial rule or real provider. */
class MarketplaceEconomicRules
{
    public function sale(): void
    {
        $this->common();
        $this->profile('sale_commission_base', 'taxable_goods_excluding_shipping');
        $this->minutes('marketplace.reservation_minutes');
        foreach (['free', 'pro'] as $plan) {
            $this->saleRate($plan);
        }
    }

    public function exchange(): void
    {
        $this->common();
        $this->exchangeRate();
        $this->profile('exchange_commission_base', 'paid_difference');
        $this->profile('exchange_commission_charged_to', 'payee');
        $this->minutes('marketplace.exchange_difference_payment_minutes');
        $this->minutes('marketplace.reservation_minutes');
    }

    private function common(): void
    {
        if ($this->isStripe()) {
            $this->stripeReady();
        }
        $profiles = ['execution_mode' => $this->isStripe() ? 'stripe_connect' : 'simulation', 'funds_flow' => $this->isStripe() ? 'stripe_connect_separate_charges_transfers' : 'simulated_vendor_allocations', 'vat_rules' => 'descriptive_snapshots_v1', 'invoicing_rules' => 'retailer_goods_platform_service_v1', 'shipping_rules' => 'buyer_sales_receiver_exchange_v1', 'cancellation_rules' => 'pre_dispatch_review_v1', 'refund_rules' => 'full_pre_dispatch_review_after_v1'];
        foreach ($profiles as $key => $supported) {
            $this->profile($key, $supported);
        }
    }

    public function isStripe(): bool
    {
        return config('marketplace.economic_rules.execution_mode') === 'stripe_connect';
    }

    public function stripeReady(): void
    {
        $treatment = config('marketplace.commission_tax.treatment');
        if ($treatment !== 'not_applicable' && ($treatment !== 'excluded' || config('marketplace.commission_tax.rate_basis_points') === null)) {
            $this->fail('commission_tax');
        }
        if ($treatment === 'excluded') {
            $this->integer(config('marketplace.commission_tax.rate_basis_points'), 'commission_tax_rate', 0, 10000);
        }
        $secret = config('marketplace.stripe.secret');
        $mode = config('marketplace.stripe.mode');
        // Proxy credentials may be opaque placeholders. Mode is explicit, verified again on the PSP response.
        if (config('marketplace.provider') !== 'stripe_connect' || config('marketplace.stripe.activation_verified') !== true || config('marketplace.stripe.tax_verified') !== true || ! is_string($secret) || $secret === '' || ! in_array($mode, ['test', 'live'], true) || ($mode === 'live' && config('marketplace.stripe.live_enabled') !== true) || (str_starts_with($secret, 'sk_live_') && $mode !== 'live') || (str_starts_with($secret, 'sk_test_') && $mode !== 'test')) {
            $this->fail('stripe_activation');
        }
    }

    public function provider(): string
    {
        return $this->isStripe() ? 'stripe_connect' : 'fake_marketplace';
    }

    private function profile(string $key, string $supported): void
    {
        if (config('marketplace.economic_rules.'.$key) !== $supported) {
            $this->fail($key);
        }
    }

    public function saleRate(string $plan): int
    {
        if (! in_array($plan, ['free', 'pro'], true)) {
            $this->fail('sale_commission_basis_points');
        }

        return $this->integer(config('marketplace.economic_rules.sale_commission_basis_points.'.$plan), 'sale_commission_basis_points', 0, 10000);
    }

    public function exchangeRate(): int
    {
        return $this->integer(config('marketplace.economic_rules.exchange_commission_basis_points'), 'exchange_commission_basis_points', 0, 10000);
    }

    public function minutes(string $path): int
    {
        return $this->integer(config($path), $path, 1, $path === 'marketplace.reservation_minutes' ? 1440 : 10080);
    }

    private function integer(mixed $value, string $key, int $min, int $max): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[0-9]{1,5}$/D', (string) $value) || (int) $value < $min || (int) $value > $max) {
            $this->fail($key);
        }

        return (int) $value;
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['economic_rules' => 'Operazione marketplace non disponibile: le regole commerciali necessarie non sono configurate o approvate.']);
    }
}
