<?php

namespace Tests\Feature;

use App\Actions\RecordAdministrativeAction;
use App\Actions\ResetRetailerPassword;
use App\Enums\AdministrativeAction;
use App\Enums\UserRole;
use App\Mail\RetailerTemporaryPasswordMail;
use App\Models\AuditLog;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class AdminRetailerPasswordResetTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private User $admin;

    private Retailer $retailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->retailer = Retailer::factory()->create();
        config(['mail.default' => 'array']);
    }

    private function url(): string
    {
        return route('admin.retailers.reset-password', $this->retailer);
    }

    public function test_only_admin_can_reset_password_including_direct_action_calls(): void
    {
        Mail::fake();
        $this->post($this->url())->assertRedirect(route('login'));
        foreach ([$this->retailer->user, User::factory()->create(['role' => UserRole::Customer])] as $user) {
            $this->actingAs($user)->post($this->url())->assertForbidden();
            try {
                app(ResetRetailerPassword::class)->handle($user, $this->retailer);
                $this->fail('The action must also authorize the actor.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
        Mail::assertNothingSent();
    }

    public function test_reset_hashes_server_generated_password_sends_specific_mail_and_redacts_output(): void
    {
        Mail::fake();
        Log::spy();
        $user = $this->retailer->user;
        $oldHash = $user->password;
        $response = $this->actingAs($this->admin)->post($this->url(), ['password' => 'browser-must-not-choose-this']);
        $response->assertRedirect(route('admin.retailers'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Nuova password temporanea inviata a '.$user->email.'.');
        $newHash = $user->fresh()->password;
        $this->assertNotSame($oldHash, $newHash);
        $this->assertNull($user->fresh()->remember_token);
        $this->assertFalse(Hash::check('password', $newHash));
        $this->assertFalse(Hash::check('browser-must-not-choose-this', $newHash));
        $temporary = null;
        Mail::assertSent(RetailerTemporaryPasswordMail::class, function ($mail) use ($user, $newHash, &$temporary) {
            $mail->build();
            $temporary = $mail->viewData['temporaryPassword'];
            $this->assertSame(24, strlen($temporary));
            foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/'] as $pattern) {
                $this->assertMatchesRegularExpression($pattern, $temporary);
            }
            $this->assertTrue(Hash::check($temporary, $newHash));
            $this->assertNotSame($temporary, $newHash);
            $this->assertSame('ZeroMagazzino — nuova password temporanea', $mail->subject);
            $html = $mail->render();
            $this->assertStringContainsString(e($temporary), $html);
            $this->assertStringContainsString(e($user->email), $html);
            $this->assertStringContainsString(route('login'), $html);

            return $mail->hasTo($user->email);
        });
        Mail::assertSentCount(1);
        $audit = AuditLog::sole();
        $this->assertSame(AdministrativeAction::RetailerPasswordReset, $audit->action);
        $this->assertTrue($audit->subject->is($this->retailer));
        $this->assertSame(['password_reset' => false], $audit->before_state);
        $this->assertSame(['password_reset' => true], $audit->after_state);
        foreach ([$temporary, $oldHash, $newHash] as $secret) {
            $this->assertStringNotContainsString($secret, $audit->toJson());
            $this->assertStringNotContainsString($secret, json_encode(session()->all()));
            $response->assertDontSee($secret);
        }
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Password rivenditore ripristinata');
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => $user->email, 'password' => $temporary])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_generation_retries_each_missing_character_category_deterministically(): void
    {
        Mail::fake();
        $candidates = [
            str_repeat('A', 21).'B1!', // No lowercase.
            str_repeat('a', 21).'b1!', // No uppercase.
            str_repeat('a', 21).'Ab!', // No number.
            str_repeat('a', 21).'Ab1', // No symbol.
            str_repeat('a', 21).'A1!', // All four categories.
        ];
        foreach ($candidates as $candidate) {
            $this->assertSame(24, strlen($candidate));
        }
        $action = Mockery::mock(ResetRetailerPassword::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $action->shouldReceive('passwordCandidate')->times(count($candidates))->andReturn(...$candidates);
        $this->app->instance(ResetRetailerPassword::class, $action);

        $this->actingAs($this->admin)->post($this->url())->assertSessionHasNoErrors()->assertRedirect(route('admin.retailers'));

        Mail::assertSent(RetailerTemporaryPasswordMail::class, function ($mail) use ($candidates) {
            $mail->build();
            $password = $mail->viewData['temporaryPassword'];
            $this->assertSame($candidates[4], $password);
            $this->assertTrue(Hash::check($password, $this->retailer->user->fresh()->password));
            foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^a-zA-Z0-9]/'] as $pattern) {
                $this->assertMatchesRegularExpression($pattern, $password);
            }

            return $mail->hasTo($this->retailer->user->email);
        });
        Mail::assertSentCount(1);
    }

    public function test_mail_failure_rolls_back_hash_remember_token_and_audit_without_logging(): void
    {
        $original = $this->retailer->user->only('password', 'remember_token');
        Log::spy();
        Mail::shouldReceive('to')->once()->with($this->retailer->user->email)->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP details must stay private'));
        $this->actingAs($this->admin)->from(route('admin.retailers'))->post($this->url())
            ->assertRedirect(route('admin.retailers'))
            ->assertSessionHasErrors(['password_reset' => 'Impossibile completare il ripristino della password. La password precedente è stata conservata.'])
            ->assertDontSee('SMTP details');
        $this->assertSame($original, $this->retailer->user->fresh()->only('password', 'remember_token'));
        $this->assertDatabaseCount('audit_logs', 0);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
    }

    public function test_audit_failure_prevents_delivery_and_rolls_back_password(): void
    {
        Mail::fake();
        $original = $this->retailer->user->password;
        $this->mock(RecordAdministrativeAction::class)->shouldReceive('handle')->once()->andThrow(new \RuntimeException);
        $this->actingAs($this->admin)->post($this->url())->assertSessionHasErrors('password_reset');
        $this->assertSame($original, $this->retailer->user->fresh()->password);
        Mail::assertNothingSent();
    }

    public function test_logging_mailers_and_failover_to_log_are_rejected_before_changing_password(): void
    {
        Mail::fake();
        $original = $this->retailer->user->password;
        foreach (['log', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->actingAs($this->admin)->post($this->url())->assertSessionHasErrors('password_reset');
            $this->assertSame($original, $this->retailer->user->fresh()->password);
        }
        $this->assertDatabaseCount('audit_logs', 0);
        Mail::assertNothingSent();
    }

    public function test_mail_url_cannot_override_smtp_to_a_logging_transport(): void
    {
        Mail::fake();
        $original = $this->retailer->user->password;
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'log://localhost']);
        $this->actingAs($this->admin)->post($this->url())->assertSessionHasErrors('password_reset');
        $this->assertSame($original, $this->retailer->user->fresh()->password);
        $this->assertDatabaseCount('audit_logs', 0);
        Mail::assertNothingSent();
    }

    public function test_endpoint_is_limited_to_five_attempts_per_minute(): void
    {
        Mail::fake();
        $this->actingAs($this->admin);
        foreach (range(1, 5) as $attempt) {
            $this->post($this->url())->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post($this->url())->assertStatus(429);
        $this->assertDatabaseCount('audit_logs', 5);
        Mail::assertSentCount(5);
    }

    public function test_post_method_and_csrf_are_required(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->get($this->url())->assertStatus(405);
        $this->patch($this->url())->assertStatus(405);
        // Laravel bypasses CSRF in tests; explicitly enable its production behaviour.
        $this->app->instance('env', 'production');
        config(['session.secure' => false]);
        $this->post($this->url())->assertStatus(419);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_enum_extension_preserves_history_and_append_only_triggers(): void
    {
        $audit = app(RecordAdministrativeAction::class)->handle($this->admin, AdministrativeAction::RetailerApproved, $this->retailer, ['status' => 'pending'], ['status' => 'approved']);
        $before = $audit->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_22_000001_allow_retailer_password_reset_audit.php');
        if (DB::getDriverName() === 'sqlite') {
            $migration->up();
        }
        $this->assertSame($before, $audit->fresh()->getAttributes());
        $migration->down();
        app(RecordAdministrativeAction::class)->handle($this->admin, AdministrativeAction::RetailerPasswordReset, $this->retailer, [], ['password_reset' => true]);
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('audit_logs')->where('id', $audit->id);
                $operation === 'update' ? $query->update(['subject_id' => 999]) : $query->delete();
                $this->fail('Audit history must remain immutable.');
            } catch (QueryException) {
                $this->assertSame($before, $audit->fresh()->getAttributes());
            }
        }
    }
}
