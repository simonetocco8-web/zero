<?php

namespace App\Services\BankVerification;

use RuntimeException;

class TrustFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $retryable = false, public readonly ?string $requestId = null, public readonly ?string $state = null)
    {
        parent::__construct('Bank verification failed: '.$reason);
    }
}
