<?php

namespace App\Actions;

use App\Http\Requests\RetailerCompanyRequest;
use App\Models\Retailer;
use App\Models\User;
use App\Services\BankVerification\RetailerBankVerificationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateRetailerProfile
{
    public function handle(User $user, Retailer $retailer, array $data): void
    {
        Gate::forUser($user)->authorize('update', $retailer);
        DB::transaction(function () use ($user, $retailer, $data) {
            $locked = Retailer::lockForUpdate()->findOrFail($retailer->id);
            Gate::forUser($user)->authorize('update', $locked);
            $nameChanged = $locked->company_name !== $data['company_name'];
            $locked->update(Arr::only($data, array_keys(RetailerCompanyRequest::companyRules())));
            app(RetailerBankVerificationService::class)->requestLocked($locked, ! empty($data['iban']) ? $data['iban'] : null, $nameChanged);
        });
    }
}
