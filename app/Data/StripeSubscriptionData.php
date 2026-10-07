<?php

namespace App\Data;

use App\Support\ExactInteger;
use Carbon\CarbonImmutable;

final readonly class StripeSubscriptionData
{
    private function __construct(public string $id, public string $customerId, public string $checkoutId, public int $retailerId, public string $status, public string $priceId, public int $priceCents, public string $interval, public ?CarbonImmutable $periodEnd, public bool $cancelAtPeriodEnd, public bool $invoicePaid, public ?CarbonImmutable $endedAt) {}

    public static function fromArray(array $data): self
    {
        $item = $data['items']['data'][0] ?? [];
        $price = $item['price'] ?? [];
        if (count($data['items']['data'] ?? []) !== 1 || ($item['quantity'] ?? 1) !== 1 || strtoupper($price['currency'] ?? '') !== 'EUR' || ($price['recurring']['interval_count'] ?? 1) !== 1) {
            throw new \RuntimeException('Unsupported subscription shape.');
        }
        $interval = match ($price['recurring']['interval'] ?? '') {
            'month' => 'monthly','year' => 'yearly',default => throw new \RuntimeException('Unsupported billing interval.')
        };
        $id = $data['id'] ?? '';
        $customer = $data['customer'] ?? '';
        $checkout = $data['metadata']['zero_checkout_id'] ?? '';
        if (! is_string($id) || ! is_string($customer) || ! is_string($checkout) || $id === '' || $customer === '' || strlen($id) > 191 || strlen($customer) > 191) {
            throw new \RuntimeException('Invalid subscription identifiers.');
        }
        $end = $item['current_period_end'] ?? $data['current_period_end'] ?? null;
        $paid = ($data['latest_invoice']['status'] ?? null) === 'paid';

        return new self($id, $customer, $checkout, ExactInteger::parse($data['metadata']['zero_retailer_id'] ?? 0), $data['status'] ?? 'incomplete', $price['id'] ?? '', ExactInteger::parse($price['unit_amount'] ?? -1), $interval, $end ? CarbonImmutable::createFromTimestampUTC(ExactInteger::parse($end)) : null, (bool) ($data['cancel_at_period_end'] ?? false), $paid, isset($data['ended_at']) ? CarbonImmutable::createFromTimestampUTC(ExactInteger::parse($data['ended_at'])) : null);
    }

    public function entitled(): bool
    {
        return $this->status === 'active' && $this->invoicePaid;
    }
}
