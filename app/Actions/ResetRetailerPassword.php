<?php

namespace App\Actions;

use App\Enums\AdministrativeAction;
use App\Enums\UserRole;
use App\Exceptions\EmailDeliveryNotConfigured;
use App\Mail\RetailerTemporaryPasswordMail;
use App\Models\Retailer;
use App\Models\User;
use App\Services\DeliveringMailerGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ResetRetailerPassword
{
    public function handle(User $actor, Retailer $retailer): string
    {
        Gate::forUser($actor)->authorize('resetPassword', $retailer);

        try {
            app(DeliveringMailerGuard::class)->ensure();

            // Do not retry a transaction containing an external delivery.
            return DB::transaction(function () use ($actor, $retailer) {
                $retailer = Retailer::lockForUpdate()->findOrFail($retailer->id);
                $user = User::lockForUpdate()->findOrFail($retailer->user_id);
                if ($user->role !== UserRole::Retailer) {
                    throw new RuntimeException('Invalid retailer account.');
                }
                do {
                    $password = $this->passwordCandidate();
                } while (! preg_match('/[a-z]/', $password)
                    || ! preg_match('/[A-Z]/', $password)
                    || ! preg_match('/[0-9]/', $password)
                    || ! preg_match('/[^a-zA-Z0-9]/', $password));

                $user->forceFill(['password' => $password, 'remember_token' => null])->save();
                app(RecordAdministrativeAction::class)->handle($actor, AdministrativeAction::RetailerPasswordReset, $retailer, ['password_reset' => false], ['password_reset' => true]);
                Mail::to($user->email)->send(new RetailerTemporaryPasswordMail($user->email, $password));

                return $user->email;
            });
        } catch (EmailDeliveryNotConfigured) {
            throw ValidationException::withMessages([
                'password_reset' => 'Invio email non configurato sul server. La password non è stata modificata.',
            ]);
        } catch (Throwable) {
            // Never report the transport exception: its payload can contain the password.
            throw ValidationException::withMessages([
                'password_reset' => 'Impossibile completare il ripristino della password. La password precedente è stata conservata.',
            ]);
        }
    }

    protected function passwordCandidate(): string
    {
        return Str::password(24);
    }
}
