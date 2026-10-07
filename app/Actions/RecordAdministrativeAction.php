<?php

namespace App\Actions;

use App\Enums\AdministrativeAction;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\PayoutRequest;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class RecordAdministrativeAction
{
    public function handle(User $actor, AdministrativeAction $action, Model $subject, array $before = [], array $after = []): AuditLog
    {
        Gate::forUser($actor)->authorize('accessAdministration', User::class);
        $expected = match ($action) {
            AdministrativeAction::RetailerApproved, AdministrativeAction::RetailerRejected, AdministrativeAction::RetailerSuspended => Retailer::class,
            AdministrativeAction::InventoryApproved, AdministrativeAction::InventoryRejected, AdministrativeAction::InventoryPublished => InventoryItem::class,
            AdministrativeAction::PayoutPaid, AdministrativeAction::PayoutRejected => PayoutRequest::class,
        };
        if (! $subject instanceof $expected || ! $subject->exists) {
            throw new InvalidArgumentException('Administrative action requires a persisted subject of the matching type.');
        }
        $allowed = ['status', 'approved_at', 'rejected_at', 'published_at', 'paid_at', 'amount_cents', 'currency'];

        return AuditLog::create([
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before_state' => array_filter(Arr::only($before, $allowed), $this->isSafeValue(...)),
            'after_state' => array_filter(Arr::only($after, $allowed), $this->isSafeValue(...)),
        ]);
    }

    private function isSafeValue(mixed $value): bool
    {
        // Never persist nested payloads or floating-point amounts in audit snapshots.
        return is_string($value) || is_int($value) || is_bool($value) || $value === null;
    }
}
