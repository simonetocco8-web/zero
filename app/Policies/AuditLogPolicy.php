<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->role === UserRole::Admin;
    }
}
