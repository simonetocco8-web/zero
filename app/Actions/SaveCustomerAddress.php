<?php

namespace App\Actions;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class SaveCustomerAddress
{
    public function handle(User $user, array $data, ?CustomerAddress $address = null): CustomerAddress
    {
        Gate::forUser($user)->authorize($address ? 'update' : 'create', $address ?? CustomerAddress::class);

        $data = Validator::make($data, [
            'label' => ['required', 'string', 'max:255'], 'recipient_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'], 'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'], 'postal_code' => ['required', 'string', 'max:32'],
            'city' => ['required', 'string', 'max:255'], 'province' => ['required', 'string', 'max:80'],
            'country_code' => ['required', 'regex:/^[A-Z]{2}$/D'], 'phone' => ['nullable', 'string', 'max:40'],
            'is_default_shipping' => ['sometimes', 'boolean'], 'is_default_billing' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($user, $data, $address) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            $address = $address ? $user->customerAddresses()->lockForUpdate()->findOrFail($address->id) : new CustomerAddress;
            foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
                if ($data[$flag] ?? false) {
                    $user->customerAddresses()->where($flag, true)->update([$flag => false]);
                }
            }
            $address->fill($data);
            $address->user_id = $user->id;
            $address->save();

            return $address;
        }, 5);
    }
}
