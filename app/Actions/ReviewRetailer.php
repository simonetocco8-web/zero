<?php

namespace App\Actions;

use App\Enums\AdministrativeAction;
use App\Enums\RetailerStatus;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewRetailer
{
    public function handle(User $actor, Retailer $retailer, string $decision, ?string $reason): void
    {
        Gate::forUser($actor)->authorize('review', $retailer);
        DB::transaction(function () use ($actor, $retailer, $decision, $reason) {
            $retailer = Retailer::lockForUpdate()->findOrFail($retailer->id);
            if ($decision === 'suspended') {
                if ($retailer->status !== RetailerStatus::Approved) {
                    throw ValidationException::withMessages(['decision' => 'Puoi sospendere solo profili approvati.']);
                }
                $retailer->update(['status' => RetailerStatus::Suspended, 'reviewed_by' => $actor->id, 'rejection_reason' => $reason]);
                app(RecordAdministrativeAction::class)->handle($actor, AdministrativeAction::RetailerSuspended, $retailer, ['status' => 'approved'], ['status' => 'suspended']);

                return;
            }
            if ($retailer->status !== RetailerStatus::Pending) {
                throw ValidationException::withMessages(['decision' => 'Questo profilo è già stato verificato.']);
            }
            $before = ['status' => $retailer->status->value];
            $approved = $decision === 'approved';
            $retailer->update(['status' => $approved ? RetailerStatus::Approved : RetailerStatus::Rejected, 'reviewed_by' => $actor->id, 'approved_at' => $approved ? now() : null, 'rejected_at' => $approved ? null : now(), 'rejection_reason' => $approved ? null : $reason]);
            app(RecordAdministrativeAction::class)->handle($actor, $approved ? AdministrativeAction::RetailerApproved : AdministrativeAction::RetailerRejected, $retailer, $before, ['status' => $retailer->status->value]);
        });
    }
}
