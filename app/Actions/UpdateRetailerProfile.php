<?php

namespace App\Actions;

use App\Http\Requests\RetailerCompanyRequest;
use App\Models\Retailer;
use App\Models\ShippingQuote;
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
            $locked->fill(Arr::only($data, array_keys(RetailerCompanyRequest::companyRules())));
            if ($locked->isDirty(['address', 'city', 'province', 'postal_code', 'country_code', 'company_name'])) {
                ShippingQuote::where('retailer_id', $locked->id)->where('status', 'valid')->update(['status' => 'expired']);
            }
            $locked->save();
            app(RetailerBankVerificationService::class)->requestLocked($locked, ! empty($data['iban']) ? $data['iban'] : null, $nameChanged);
        });
    }
}
