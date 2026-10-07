<?php

namespace App\Actions;

use App\Models\PayoutRequest;
use App\Models\Retailer;
use App\Models\User;
use App\Rules\Iban;
use App\Services\WalletBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RequestPayout
{
    public function handle(User $user): PayoutRequest
    {
        Gate::forUser($user)->authorize('create', PayoutRequest::class);

        return DB::transaction(function () use ($user) {
            $retailer = Retailer::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('operate', $retailer);
            Validator::make(['iban' => $retailer->iban], ['iban' => ['required', new Iban]])->validate();
            if ($retailer->payoutRequests()->where('status', 'pending')->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['payout' => 'Hai già un bonifico in attesa.']);
            }
            $amount = app(WalletBalance::class)->availableCentsLocked($retailer);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['payout' => 'Credito disponibile insufficiente.']);
            }
            $payout = $retailer->payoutRequests()->create(['currency' => 'EUR', 'amount_cents' => $amount, 'iban' => $retailer->iban, 'status' => 'pending']);
            $payout->walletTransactions()->create(['retailer_id' => $retailer->id, 'currency' => 'EUR', 'type' => 'payout_reservation', 'amount_cents' => -$amount, 'idempotency_key' => 'payout:'.$payout->id.':reservation', 'available_at' => now(), 'description' => 'Credito riservato per bonifico']);

            return $payout;
        }, 5);
    }
}
