<?php

namespace App\Actions\Auth;

use App\Http\Requests\RetailerCompanyRequest;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterUser
{
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $plan = Plan::where('code', $data['plan_code'])->where('is_active', true)->lockForUpdate()->first();
            if (! $plan) {
                throw ValidationException::withMessages(['plan_code' => 'Il piano selezionato non è disponibile.']);
            }
            $user = User::create(['name' => $data['contact_name'] ?? $data['company_name'], 'email' => $data['email'], 'password' => $data['password']]);
            $retailer = $user->retailer()->create(Arr::only($data, array_keys(RetailerCompanyRequest::companyRules())) + ['status' => 'pending']);
            $retailer->subscriptions()->create(['plan_id' => $plan->id, 'status' => $plan->code === 'free' ? 'active' : 'pending', 'billing_interval' => 'monthly', 'currency' => $plan->currency, 'price_cents' => $plan->monthly_price_cents, 'starts_at' => $plan->code === 'free' ? now() : null]);

            return $user;
        });
        event(new Registered($user));

        return $user;
    }
}
