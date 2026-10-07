<?php

namespace App\Actions;

use App\Http\Requests\RetailerCompanyRequest;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class UpdateRetailerProfile
{
    public function handle(User $user, Retailer $retailer, array $data): void
    {
        Gate::forUser($user)->authorize('update', $retailer);
        $values = Arr::only($data, array_keys(RetailerCompanyRequest::companyRules()));
        if (! empty($data['iban'])) {
            $values['iban'] = $data['iban'];
        }
        $retailer->update($values);
    }
}
