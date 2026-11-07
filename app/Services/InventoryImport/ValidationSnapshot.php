<?php

namespace App\Services\InventoryImport;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Collection;

/** Read-only preview metadata, never used to authorize a product write. */
class ValidationSnapshot
{
    public function __construct(public readonly Subscription $subscription, public readonly Plan $plan, public readonly Collection $categories) {}
}
