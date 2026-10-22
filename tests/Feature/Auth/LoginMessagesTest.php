<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class LoginMessagesTest extends TestCase
{
    use AdditiveRefreshDatabase;

    public function test_failed_login_has_clear_italian_alert_even_with_missing_translation(): void
    {
        Lang::shouldReceive('get')->with('auth.failed', [], 'it')->andReturn('auth.failed');
        $request = LoginRequest::create('/login', 'POST', ['email' => 'missing@example.test', 'password' => 'incorrect']);
        Auth::shouldReceive('attempt')->once()->andReturn(false);
        try {
            $request->authenticate();
            $this->fail('Invalid credentials must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Le credenziali inserite non sono corrette.'], $exception->errors()['email']);
        }
    }

    public function test_login_alert_displays_the_credential_error(): void
    {
        $user = User::factory()->create();
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'invalid'])
            ->assertRedirect('/login')->assertSessionHasErrors(['email' => 'Le credenziali inserite non sono corrette.']);
        $r = $this->withCookie(config('session.cookie'), session()->getId())->get('/login');
        $r->assertOk()->assertSee('role="alert"', false)->assertSee('Le credenziali inserite non sono corrette.')->assertDontSee('auth.failed');
    }

    public function test_throttle_still_blocks_after_five_attempts_with_missing_translation(): void
    {
        $request = LoginRequest::create('/login', 'POST', ['email' => 'throttled@example.test']);
        RateLimiter::clear($request->throttleKey());
        foreach (range(1, 5) as $attempt) {
            RateLimiter::hit($request->throttleKey(), 60);
        }
        Lang::shouldReceive('get')->with('auth.throttle', \Mockery::type('array'), 'it')->andReturn('auth.throttle');
        try {
            $request->ensureIsNotRateLimited();
            $this->fail('The sixth login must be blocked.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['email'][0];
            $this->assertStringContainsString('Troppi tentativi di accesso. Riprova tra ', $message);
            $this->assertStringNotContainsString('auth.throttle', $message);
            $this->assertStringNotContainsString(':seconds', $message);
        }
        RateLimiter::clear($request->throttleKey());
    }
}
