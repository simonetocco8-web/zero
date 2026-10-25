<?php

namespace App\Services\Company;

final readonly class CompanyLookupResult
{
    public function __construct(public string $status, public array $prefill = [], public ?string $activityStatus = null, public ?string $reason = null) {}

    public function toArray(): array
    {
        return ['status' => $this->status, 'prefill' => $this->prefill, 'activity_status' => $this->activityStatus, 'reason' => $this->reason];
    }
}
