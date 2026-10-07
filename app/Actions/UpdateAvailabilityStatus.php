<?php

namespace App\Actions;

use App\Models\AvailabilityRequest;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateAvailabilityStatus
{
    public function handle(User $user, AvailabilityRequest $request, string $status): void
    {
        DB::transaction(function () use ($user, $request, $status) {
            Retailer::lockForUpdate()->findOrFail($request->retailer_id);
            $request = AvailabilityRequest::lockForUpdate()->findOrFail($request->id);
            Gate::forUser($user)->authorize('update', $request);
            $request->update(['status' => $status, 'contacted_at' => $status === 'contacted' ? ($request->contacted_at ?? now()) : $request->contacted_at, 'closed_at' => $status === 'closed' ? ($request->closed_at ?? now()) : null]);
        });
    }
}
