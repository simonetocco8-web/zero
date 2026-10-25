<?php

namespace App\Jobs;

use App\Services\BankVerification\OpenApiTrustClient;
use App\Services\BankVerification\RetailerBankVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessRetailerBankVerification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $verificationId) {}

    public function handle(RetailerBankVerificationService $service, OpenApiTrustClient $client): void
    {
        $service->process($this->verificationId, $client);
    }
}
