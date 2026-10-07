<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Gate;

class WalletTransactionPolicy
{
    public function view(User $user, WalletTransaction $walletTransaction): bool
    {
        return Gate::forUser($user)->allows('view', $walletTransaction->retailer);
    }
}
