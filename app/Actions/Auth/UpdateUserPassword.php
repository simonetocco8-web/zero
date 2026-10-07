<?php

namespace App\Actions\Auth;

use App\Models\User;

class UpdateUserPassword
{
    public function handle(User $user, string $password): void
    {
        $user->update(['password' => $password]);
    }
}
