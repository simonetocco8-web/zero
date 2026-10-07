<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\IntegrationEvent;
use App\Models\User;

class IntegrationEventPolicy
{
    public function view(User $user, IntegrationEvent $integrationEvent): bool
    {
        return $user->role === UserRole::Admin;
    }
}
