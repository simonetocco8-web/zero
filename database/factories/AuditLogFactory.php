<?php

namespace Database\Factories;

use App\Enums\AdministrativeAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory()->state(['role' => UserRole::Admin]),
            'subject_type' => Retailer::class,
            'subject_id' => Retailer::factory(),
            'action' => AdministrativeAction::RetailerApproved,
            'before_state' => ['status' => 'pending'],
            'after_state' => ['status' => 'approved'],
        ];
    }
}
