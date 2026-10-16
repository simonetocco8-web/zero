<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Exchange;
use App\Models\User;

class ExchangePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->role === UserRole::Retailer && $u->retailer !== null;
    }

    public function create(User $u): bool
    {
        return $u->can('proposeExchange', User::class);
    }

    public function view(User $u, Exchange $e): bool
    {
        return $this->viewAny($u) && in_array($u->retailer->id, [$e->retailer_a_id, $e->retailer_b_id], true);
    }

    public function negotiate(User $u, Exchange $e): bool
    {
        return $this->view($u, $e) && $this->create($u);
    }

    public function cancel(User $u, Exchange $e): bool
    {
        return $this->view($u, $e);
    }

    public function simulatePayment(User $u, Exchange $e): bool
    {
        return $this->negotiate($u, $e) && $e->currentVersion?->payer_retailer_id === $u->retailer->id;
    }
}
