<?php

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RegisterCustomer
{
    public function handle(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = new User(Arr::only($data, ['first_name', 'last_name', 'email', 'password', 'phone']));
            $user->name = $data['first_name'].' '.$data['last_name'];
            $user->role = UserRole::Customer;
            $user->save();

            return $user;
        });
        event(new Registered($user));

        return $user;
    }
}
