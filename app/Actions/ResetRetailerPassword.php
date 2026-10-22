<?php

namespace App\Actions;

use App\Enums\AdministrativeAction;
use App\Enums\UserRole;
use App\Mail\RetailerTemporaryPasswordMail;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\ConfigurationUrlParser;
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
            $this->ensureDeliveringMailer(config('mail.default'));

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

    private function ensureDeliveringMailer(string $name, array $visited = []): void
    {
        if (config('mail.driver') || in_array($name, $visited, true)) {
            throw new RuntimeException('Invalid mailer configuration.');
        }
        $mailer = config('mail.mailers.'.$name, []);
        if (isset($mailer['url'])) {
            $mailer = array_merge($mailer, (new ConfigurationUrlParser)->parseConfiguration($mailer));
            $mailer['transport'] = $mailer['driver'] ?? null;
        }
        $transport = $mailer['transport'] ?? null;
        if (in_array($transport, ['failover', 'roundrobin'], true) && ! empty($mailer['mailers'])) {
            foreach ($mailer['mailers'] as $child) {
                $this->ensureDeliveringMailer($child, [...$visited, $name]);
            }

            return;
        }
        if (in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)
            || ($transport === 'array' && app()->environment('testing'))) {
            return;
        }

        throw new RuntimeException('Password delivery requires a non-logging mailer.');
    }
}
