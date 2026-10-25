<?php

namespace App\Services\BankVerification;

use App\Jobs\ProcessRetailerBankVerification;
use App\Models\Retailer;
use App\Models\RetailerBankVerification as Verification;
use App\Models\RetailerBankVerificationEvent;
use App\Rules\Iban;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RetailerBankVerificationService
{
    public function fingerprint(string $iban): string
    {
        return hash_hmac('sha256', $iban, (string) config('app.key'));
    }

    public function latest(Retailer $retailer): ?Verification
    {
        return Verification::where('retailer_id', $retailer->id)->latest('id')->first();
    }

    public function active(Retailer $retailer): ?Verification
    {
        return $retailer->iban ? Verification::where('retailer_id', $retailer->id)->where('iban_fingerprint', $this->fingerprint($retailer->iban))
            ->where('iban_validation_status', 'valid')->latest('id')->first() : null;
    }

    // Caller holds the retailer row lock. No network operations occur inside this transaction.
    public function requestLocked(Retailer $retailer, ?string $candidate, bool $nameChanged = false, bool $explicit = false, bool $retry = false): ?Verification
    {
        $hasCandidate = $candidate !== null;
        $latest = $this->latest($retailer);
        if ($candidate === null && ! $explicit && ! $nameChanged) {
            return null;
        }
        if ($candidate === null && $latest?->iban_validation_status === 'pending' && $latest->status === 'pending') {
            $latest->update(['company_name_checked' => $retailer->company_name]);

            return $latest;
        }
        $candidate ??= $retry && $latest ? $latest->iban : $retailer->iban;
        if (! $candidate) {
            return null;
        }
        $candidate = strtoupper(preg_replace('/\s+/', '', $candidate));
        Validator::make(['iban' => $candidate], ['iban' => ['required', 'string', 'max:34', new Iban]])->validate();
        $fingerprint = $this->fingerprint($candidate);
        if ($latest && hash_equals($latest->iban_fingerprint, $fingerprint) && $latest->company_name_checked === $retailer->company_name
            && (! $retry || in_array($latest->status, ['pending', 'completed'], true)) && in_array($latest->status, ['pending', 'completed', 'failed'], true)) {
            return $latest;
        }
        if ($retry && $latest && hash_equals($latest->iban_fingerprint, $fingerprint) && $latest->company_name_checked === $retailer->company_name) {
            $phase = $latest->iban_validation_status === 'valid' ? 'vop' : 'advanced';
            if ($latest->{$phase.'_request_id'} && (in_array($latest->{$phase.'_state'}, ['NEW', 'WAIT'], true) || in_array($latest->error_code, ['invalid_response', 'account_mismatch', 'payee_mismatch'], true))) {
                $latest->update(['status' => 'pending', 'iban_validation_status' => $phase === 'advanced' ? 'pending' : 'valid', $phase.'_attempts' => 0, 'processing_at' => null, 'next_attempt_at' => now(), 'error_code' => null, 'failed_at' => null, 'expires_at' => now()->addMinutes(max(1, min(120, (int) config('services.openapi_trust.max_minutes'))))]);
                $this->enqueue($latest->id);

                return $latest;
            }
        }
        $validated = $this->active($retailer);
        $reusable = $validated && hash_equals($validated->iban_fingerprint, $fingerprint);
        // A name-only edit must not start paid checks of a legacy, never-verified account.
        if (! $hasCandidate && ! $explicit && ! $reusable && $nameChanged) {
            return null;
        }
        $values = ['retailer_id' => $retailer->id, 'iban' => $candidate, 'iban_fingerprint' => $fingerprint, 'iban_last4' => substr($candidate, -4),
            'company_name_checked' => $retailer->company_name, 'status' => 'pending', 'iban_validation_status' => 'pending', 'started_at' => now(),
            'expires_at' => now()->addMinutes(max(1, min(120, (int) config('services.openapi_trust.max_minutes')))), 'next_attempt_at' => now()];
        if ($reusable) {
            $values += ['bank_data' => $validated->bank_data, 'sepa_data' => $validated->sepa_data, 'iban_verified_at' => $validated->iban_verified_at, 'advanced_request_id' => $validated->advanced_request_id, 'advanced_state' => 'DONE'];
            $values['iban_validation_status'] = 'valid';
        }
        $verification = Verification::create($values);
        $this->event($verification, $reusable ? 'vop_requested' : 'iban_verification_started');
        if (! app(OpenApiTrustClient::class)->configured()) {
            $this->fail($verification, $reusable ? 'vop' : 'advanced', 'not_configured');
        } elseif (! in_array(config('queue.connections.'.config('integrations.queue_connection').'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            $this->fail($verification, $reusable ? 'vop' : 'advanced', 'queue_not_configured');
        } else {
            $this->enqueue($verification->id);
        }

        return $verification;
    }

    public function enqueue(int $id, int $delay = 0): void
    {
        DB::afterCommit(function () use ($id, $delay) {
            try {
                ProcessRetailerBankVerification::dispatch($id)->onConnection(config('integrations.queue_connection'))
                    ->onQueue(config('integrations.queue'))->delay($delay);
            } catch (Throwable) {
                // Durable pending record is recovered by bank-verifications:resume.
                Log::warning('Bank verification queue unavailable.', ['verification_id' => $id]);
            }
        });
    }

    public function process(int $id, OpenApiTrustClient $client): void
    {
        $claim = $this->locked($id, function (Verification $v, Retailer $retailer) {
            if ($v->status !== 'pending' || ($v->next_attempt_at && $v->next_attempt_at->isFuture())) {
                return null;
            }
            if ($v->processing_at && $v->processing_at->gt(now()->subSeconds(90))) {
                return null;
            }
            $phase = $v->iban_validation_status === 'valid' ? 'vop' : 'advanced';
            if ($v->expires_at->isPast() || $v->{$phase.'_attempts'} >= max(1, min(30, (int) config('services.openapi_trust.max_attempts')))) {
                $this->fail($v, $phase, 'attempts_exhausted');

                return null;
            }
            if ($v->{$phase.'_state'} === 'SUBMITTING' && ! $v->{$phase.'_request_id'}) {
                $this->fail($v, $phase, 'submission_uncertain');

                return null;
            }
            if ($phase === 'vop' && ($v->company_name_checked !== $retailer->company_name || $retailer->iban !== $v->iban)) {
                $v->update(['status' => 'superseded']);

                return null;
            }
            $v->update(['processing_at' => now(), $phase.'_attempts' => $v->{$phase.'_attempts'} + 1,
                $phase.'_state' => $v->{$phase.'_request_id'} ? $v->{$phase.'_state'} : 'SUBMITTING']);
            if (! $v->{$phase.'_request_id'}) {
                $this->event($v, $phase === 'vop' ? 'vop_started' : 'iban_request_submitted');
            }

            return [$v, $phase];
        });
        if (! $claim) {
            return;
        }
        [$record, $phase] = $claim;
        $requestId = $record->{$phase.'_request_id'};
        try {
            $result = $phase === 'advanced'
                ? ($requestId ? $client->getIbanAdvanced($requestId, $record->iban) : $client->startIbanAdvanced($record->iban))
                : ($requestId ? $client->getVop($requestId, $record->iban, $record->company_name_checked) : $client->startVop($record->iban, $record->company_name_checked));
        } catch (TrustFailure $failure) {
            $this->locked($id, function (Verification $v) use ($phase, $requestId, $failure) {
                if (! $requestId && $failure->requestId) {
                    $v->update([$phase.'_request_id' => $failure->requestId, $phase.'_state' => $failure->state]);
                }
                if ($requestId && $failure->retryable) {
                    $this->later($v, $phase, $failure->reason);
                } else {
                    $this->fail($v, $phase, $failure->reason);
                }
            });

            return;
        } catch (Throwable) {
            // Never send provider exceptions/payloads to failed_jobs or application logs.
            $this->locked($id, fn (Verification $v) => $this->fail($v, $phase, 'processing_failed'));

            return;
        }
        $this->locked($id, function (Verification $v, Retailer $retailer) use ($phase, $result) {
            $v->update([$phase.'_request_id' => $result['id'], $phase.'_state' => $result['state'], 'processing_at' => null, 'error_code' => null]);
            if ($result['state'] !== 'DONE') {
                $this->later($v, $phase);

                return;
            }
            if ($phase === 'advanced') {
                if (! $result['valid']) {
                    $v->update(['iban_validation_status' => 'invalid']);
                    $this->fail($v, $phase, 'iban_invalid');

                    return;
                }
                $v->update(['iban_validation_status' => 'valid', 'iban_verified_at' => now(), 'bank_data' => $result['bank_data'], 'sepa_data' => $result['sepa_data']]);
                // Only the latest intended verification may replace the active encrypted IBAN.
                $retailer->update(['iban' => $v->iban]);
                $this->event($v, 'iban_verified');
                $v->update(['next_attempt_at' => now()]);
                $this->enqueue($v->id);
            } else {
                $v->update(['vop_result' => $result['vop_result'], 'vop_match_type' => $result['match_type'], 'vop_owner_name' => $result['owner_name'], 'vop_verified_at' => now()]);
                if (in_array($result['vop_result'], ['ERROR', 'UNKNOWN'], true)) {
                    $this->fail($v, 'vop', $result['vop_result'] === 'UNKNOWN' ? 'vop_unknown' : 'vop_error');
                } else {
                    $v->update(['status' => 'completed', 'next_attempt_at' => null]);
                    $this->event($v, 'vop_'.strtolower($result['vop_result']));
                }
            }
        });
    }

    private function locked(int $id, callable $callback): mixed
    {
        $retailerId = Verification::whereKey($id)->value('retailer_id');
        if (! $retailerId) {
            return null;
        }

        return DB::transaction(function () use ($id, $retailerId, $callback) {
            $retailer = Retailer::lockForUpdate()->findOrFail($retailerId);
            $v = Verification::lockForUpdate()->findOrFail($id);
            if ($this->latest($retailer)?->id !== $v->id) {
                if ($v->status === 'pending') {
                    $v->update(['status' => 'superseded', 'processing_at' => null, 'next_attempt_at' => null]);
                }

                return null;
            }

            return $v->status === 'pending' ? $callback($v, $retailer) : null;
        });
    }

    private function later(Verification $v, string $phase, ?string $error = null): void
    {
        if ($v->{$phase.'_attempts'} >= max(1, min(30, (int) config('services.openapi_trust.max_attempts'))) || $v->expires_at->isPast()) {
            $this->fail($v, $phase, 'attempts_exhausted');

            return;
        }
        $delay = min(300, max(5, (int) config('services.openapi_trust.poll_seconds')) * max(1, $v->{$phase.'_attempts'}));
        $v->update(['processing_at' => null, 'error_code' => $error, 'next_attempt_at' => now()->addSeconds($delay)]);
        $this->enqueue($v->id, $delay);
    }

    private function fail(Verification $v, string $phase, string $reason): void
    {
        $values = ['status' => 'failed', 'error_code' => $reason, 'failed_at' => now(), 'processing_at' => null, 'next_attempt_at' => null];
        if ($phase === 'vop') {
            $values['vop_result'] = 'ERROR';
        } elseif ($v->iban_validation_status !== 'invalid') {
            $values['iban_validation_status'] = 'error';
        }
        $v->update($values);
        $this->event($v, $phase === 'vop' ? 'vop_error' : 'iban_verification_failed');
    }

    private function event(Verification $v, string $event): void
    {
        RetailerBankVerificationEvent::create(['retailer_bank_verification_id' => $v->id, 'event' => $event,
            'context' => ['retailer_id' => $v->retailer_id, 'iban_last4' => $v->iban_last4, 'advanced_request_id' => $v->advanced_request_id, 'vop_request_id' => $v->vop_request_id, 'status' => $v->status, 'error_code' => $v->error_code], 'created_at' => now()]);
    }
}
