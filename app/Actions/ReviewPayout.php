<?php

namespace App\Actions;

use App\Enums\AdministrativeAction;
use App\Models\PayoutRequest;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReviewPayout
{
    public function handle(User $actor, PayoutRequest $payout, string $decision, ?string $reason, ?string $reference): void
    {
        Gate::forUser($actor)->authorize('review', $payout);
        DB::transaction(function () use ($actor, $payout, $decision, $reason, $reference) {
            $retailer = Retailer::lockForUpdate()->findOrFail($payout->retailer_id);
            $payout = PayoutRequest::lockForUpdate()->findOrFail($payout->id);
            if ($payout->status->value !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'Il bonifico è già stato verificato.']);
            }
            if ($payout->walletTransactions()->whereIn('type', ['payout_release', 'payout'])->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['decision' => 'La riserva è già stata utilizzata.']);
            }
            $reservation = $payout->walletTransactions()->where('type', 'payout_reservation')->lockForUpdate()->first();
            if ($reservation && ($reservation->amount_cents !== -$payout->amount_cents || ! $reservation->available_at || $reservation->available_at > now())) {
                throw ValidationException::withMessages(['decision' => 'La riserva del bonifico non è coerente.']);
            }
            if ($decision === 'paid' && (! $reservation || ! $payout->iban)) {
                throw ValidationException::withMessages(['decision' => 'Servono IBAN e riserva valida prima di registrare il pagamento.']);
            }
            $before = ['status' => 'pending', 'amount_cents' => $payout->amount_cents, 'currency' => $payout->currency];
            if ($reservation) {
                $payout->walletTransactions()->create(['retailer_id' => $retailer->id, 'currency' => $payout->currency, 'type' => 'payout_release', 'amount_cents' => $payout->amount_cents, 'available_at' => now(), 'idempotency_key' => 'payout:'.$payout->id.':release']);
            }
            if ($decision === 'paid') {
                $payout->walletTransactions()->create(['retailer_id' => $retailer->id, 'currency' => $payout->currency, 'type' => 'payout', 'amount_cents' => -$payout->amount_cents, 'available_at' => now(), 'idempotency_key' => 'payout:'.$payout->id.':paid']);
            }
            $payout->update(['status' => $decision, 'paid_at' => $decision === 'paid' ? now() : null, 'rejected_at' => $decision === 'rejected' ? now() : null, 'reviewed_by' => $actor->id, 'rejection_reason' => $decision === 'rejected' ? $reason : null, 'payment_reference' => $decision === 'paid' ? $reference : null]);
            app(RecordAdministrativeAction::class)->handle($actor, $decision === 'paid' ? AdministrativeAction::PayoutPaid : AdministrativeAction::PayoutRejected, $payout, $before, ['status' => $decision, 'amount_cents' => $payout->amount_cents, 'currency' => $payout->currency]);
        });
    }
}
