<?php

namespace Tests\Feature\Auth;

use App\Support\PasswordStatusMessage;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryMessagesTest extends TestCase
{
    public static function statuses(): array
    {
        return [
            [Password::RESET_LINK_SENT, 'Ti abbiamo inviato via email il link per reimpostare la password.'],
            [Password::INVALID_USER, 'Non troviamo un account con questo indirizzo email.'],
            [Password::RESET_THROTTLED, 'Troppi tentativi. Attendi prima di richiedere un nuovo link.'],
            [Password::INVALID_TOKEN, 'Il link di recupero non è valido o è scaduto.'],
            [Password::PASSWORD_RESET, 'La password è stata aggiornata.'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_missing_translations_always_have_italian_fallbacks(string $status, string $message): void
    {
        Lang::shouldReceive('get')->with($status, [], 'it', false)->andReturn($status);
        $this->assertSame($message, PasswordStatusMessage::for($status));
    }

    public function test_resolved_italian_translation_is_preferred_to_the_fallback(): void
    {
        Lang::shouldReceive('get')->with(Password::RESET_LINK_SENT, [], 'it', false)->andReturn('Link di recupero inviato al tuo indirizzo email.');
        $this->assertSame('Link di recupero inviato al tuo indirizzo email.', PasswordStatusMessage::for(Password::RESET_LINK_SENT));
    }

    public function test_successful_link_request_uses_fallback_when_translation_is_missing(): void
    {
        config(['mail.default' => 'array']);
        Lang::partialMock()->shouldReceive('get')->with(Password::RESET_LINK_SENT, [], 'it', false)->andReturn(Password::RESET_LINK_SENT);
        Password::shouldReceive('sendResetLink')->once()->with(['email' => 'account@example.test'])->andReturn(Password::RESET_LINK_SENT);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'account@example.test'])
            ->assertRedirect('/forgot-password')->assertSessionHas('status', 'Ti abbiamo inviato via email il link per reimpostare la password.');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/forgot-password')->assertOk()
            ->assertSee('Ti abbiamo inviato via email il link per reimpostare la password.')->assertDontSee('passwords.sent');
    }

    public function test_non_delivering_mailer_does_not_invoke_the_broker_or_claim_delivery(): void
    {
        config(['mail.default' => 'log']);
        Password::shouldReceive('sendResetLink')->never();
        Log::spy();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'account@example.test'])
            ->assertSessionHasErrors(['email' => 'Il servizio email non è momentaneamente disponibile. Riprova più tardi.'])->assertSessionMissing('status');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/forgot-password')->assertOk()->assertSee('role="alert"', false)->assertDontSee('passwords.sent');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('error');
    }

    public function test_transport_failure_has_no_smtp_details_in_errors_or_logs(): void
    {
        config(['mail.default' => 'smtp']);
        Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('private SMTP hostname username and token'));
        Log::spy();
        $this->post('/forgot-password', ['email' => 'account@example.test'])
            ->assertSessionHasErrors(['email' => 'Il servizio email non è momentaneamente disponibile. Riprova più tardi.'])->assertSessionMissing('status');
        $this->assertStringNotContainsString('private SMTP', json_encode(session()->all()));
        Log::shouldNotHaveReceived('error');
    }

    public function test_invalid_reset_token_translation_falls_back_in_the_reset_endpoint(): void
    {
        Lang::partialMock()->shouldReceive('get')->with(Password::INVALID_TOKEN, [], 'it', false)->andReturn(Password::INVALID_TOKEN);
        Password::shouldReceive('reset')->once()->andReturn(Password::INVALID_TOKEN);
        $this->post('/reset-password', ['email' => 'account@example.test', 'token' => 'invalid', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])
            ->assertSessionHasErrors(['email' => 'Il link di recupero non è valido o è scaduto.']);
    }
}
