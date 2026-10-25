<?php

namespace Tests\Concerns;

use App\Services\Company\CompanyRegistrationLookup;

trait WithCompanyRegistrationContext
{
    // Existing final-submit tests remain focused on validation, roles and atomicity.
    protected function withCompanyRegistrationContext(string $vat = '12345678901'): static
    {
        return $this->withSession([CompanyRegistrationLookup::SESSION_KEY => ['vat_number' => $vat, 'status' => 'not_found', 'source' => 'manual', 'prefill' => [], 'activity_status' => null, 'reason' => null, 'ready' => true, 'looked_up_at' => now()->timestamp]]);
    }
}
