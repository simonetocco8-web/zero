<?php

namespace Tests\Feature;

use App\Jobs\ProcessRetailerBankVerification;
use App\Models\Retailer;
use App\Models\RetailerBankVerification;
use App\Models\RetailerBankVerificationEvent;
use App\Models\User;
use App\Services\BankVerification\OpenApiTrustClient;
use App\Services\BankVerification\RetailerBankVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class RetailerBankVerificationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private const IBAN = 'IT60X0542811101000000123456';

    private const OTHER = 'GB82WEST12345698765432';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openapi_trust.token' => 'fake-trust-token', 'services.openapi_trust.base_url' => 'https://test.trust.openapi.com', 'services.openapi_trust.poll_seconds' => 5, 'integrations.queue_connection' => 'database']);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function profile(Retailer $retailer, array $overrides = [])
    {
        return $this->actingAs($retailer->user)->put(route('retailer.profile.update'), array_merge(['company_name' => $retailer->company_name, 'vat_number' => $retailer->vat_number, 'city' => $retailer->city], $overrides));
    }

    private function start(Retailer $retailer, string $iban = self::IBAN): RetailerBankVerification
    {
        $this->profile($retailer, ['iban' => $iban])->assertSessionHasNoErrors();

        return RetailerBankVerification::where('retailer_id', $retailer->id)->latest('id')->firstOrFail();
    }

    private function process(RetailerBankVerification $record): RetailerBankVerification
    {
        (new ProcessRetailerBankVerification($record->id))->handle(app(RetailerBankVerificationService::class), app(OpenApiTrustClient::class));

        return $record->fresh();
    }

    private function advanced(string $iban = self::IBAN, string $state = 'DONE', bool $valid = true, string $id = 'advanced-1'): array
    {
        $flags = [];
        foreach (['chars', 'account', 'iban', 'structure', 'length', 'countrySupport'] as $key) {
            $flags[$key] = ['valid' => $valid];
        }

        return ['success' => true, 'data' => ['id' => $id, 'iban' => $iban, 'state' => $state, 'status' => 'IBAN is valid', 'validations' => $flags,
            'bankData' => ['bank' => 'Banca Test', 'branch' => 'Sede Roma', 'bic' => 'TESTITMMXXX', 'address' => 'Via Esempio 123', 'city' => 'Roma', 'zipCode' => '00186', 'country' => 'ITALY', 'countryCode' => 'IT', 'account' => 'DO_NOT_STORE'],
            'sepaData' => ['creditTransfer' => true, 'directDebit' => false, 'creditInstantTransferRT1' => true, 'creditInstantTransferTIPS' => false]]];
    }

    private function vop(string $result = 'MATCH', ?string $owner = 'AZIENDA VERIFICATA', string $state = 'DONE'): array
    {
        return ['success' => true, 'data' => ['id' => 'vop-1', 'iban' => self::IBAN, 'state' => $state, 'vopResult' => $result,
            'details' => ['ownerName' => $owner, 'matchType' => ['MATCH' => 'MTCH', 'CLOSE_MATCH' => 'CMTC', 'NO_MATCH' => 'NMTC', 'NOT_APPLICABLE' => 'NOAP'][$result] ?? null]]];
    }

    private function fake(array $advanced, ?array $vop = null): void
    {
        Http::fake(['*/iban-advanced/*' => Http::response($advanced), '*/iban-vop*' => Http::response($vop ?? $this->vop())]);
    }

    public function test_local_validation_precedes_api_and_never_flashes_iban(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $this->profile($retailer, ['iban' => 'IT00X0542811101000000123456'])->assertSessionHasErrors('iban')->assertSessionMissing('_old_input.iban');
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        Http::assertNothingSent();
        $this->assertDatabaseCount('retailer_bank_verifications', 0);
    }

    public function test_valid_candidate_is_encrypted_then_activated_only_after_advanced_validation_and_maps_documented_fields(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer, 'it60 x054 2811 1010 0000 0123 456');
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        $this->assertSame(self::IBAN, $record->iban);
        $this->assertNotSame(self::IBAN, $record->getRawOriginal('iban'));
        $this->assertArrayNotHasKey('iban', $record->toArray());
        $this->assertArrayNotHasKey('iban', $retailer->toArray());
        $this->fake($this->advanced());
        $record = $this->process($record);
        $this->assertSame('valid', $record->iban_validation_status);
        $this->assertSame(self::IBAN, $retailer->fresh()->iban);
        $this->assertNotSame(self::IBAN, $retailer->fresh()->getRawOriginal('iban'));
        $this->assertSame('Banca Test', $record->bank_data['bank']);
        $this->assertSame('Sede Roma', $record->bank_data['branch']);
        $this->assertSame('TESTITMMXXX', $record->bank_data['bic']);
        $this->assertSame('Via Esempio 123', $record->bank_data['address']);
        $this->assertSame('00186', $record->bank_data['zipCode']);
        $this->assertArrayNotHasKey('account', $record->bank_data);
        $this->assertSame(false, $record->sepa_data['directDebit']);
        Http::assertSentCount(1);
        Queue::assertPushed(ProcessRetailerBankVerification::class, fn ($job) => $job->queue === 'integrations' && $job->verificationId === $record->id);
        $record = $this->process($record);
        $this->assertSame('MATCH', $record->vop_result);
        $this->assertSame('completed', $record->status);
        $this->assertNull($record->vop_owner_name);
        $this->assertArrayNotHasKey('vop_owner_name', $record->toArray());
        $this->assertArrayNotHasKey('company_name_checked', $record->toArray());
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://test.trust.openapi.com/iban-vop' && $request['iban'] === self::IBAN && $request['name'] === $retailer->company_name && $request->hasHeader('Authorization', 'Bearer fake-trust-token'));
        $audit = RetailerBankVerificationEvent::all()->toJson();
        $this->assertStringNotContainsString(self::IBAN, $audit);
        $this->assertStringNotContainsString('AZIENDA VERIFICATA', $audit);
        $this->assertStringNotContainsString('fake-trust-token', $audit);
    }

    public static function validations(): array
    {
        return array_map(fn ($key) => [$key], ['chars', 'account', 'iban', 'structure', 'length', 'countrySupport']);
    }

    #[DataProvider('validations')]
    public function test_success_true_and_english_valid_status_do_not_override_structured_negative_checks(string $check): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        $response = $this->advanced();
        $response['data']['validations'][$check]['valid'] = false;
        $this->fake($response);
        $record = $this->process($record);
        $this->assertSame('invalid', $record->iban_validation_status);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        $this->process($record);
        Http::assertSentCount(1);
        $this->get(route('retailer.profile'))->assertOk()->assertSee('IBAN non valido')->assertDontSee(self::IBAN);
    }

    public static function waitingStates(): array
    {
        return [['NEW'], ['WAIT']];
    }

    #[DataProvider('waitingStates')]
    public function test_pending_advanced_and_vop_poll_existing_ids_without_duplicate_posts(string $state): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        Http::fake(['*/iban-advanced/'.self::IBAN => Http::response($this->advanced(state: $state)), '*/iban-advanced/advanced-1' => Http::response($this->advanced()), '*/iban-vop' => Http::response($this->vop(state: $state)), '*/iban-vop/vop-1' => Http::response($this->vop())]);
        $record = $this->process($record);
        $this->assertSame($state, $record->advanced_state);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        $this->profile($retailer->fresh(), ['iban' => self::IBAN])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        $this->travel(10)->seconds();
        $record = $this->process($record);
        $this->assertSame(self::IBAN, $retailer->fresh()->iban);
        $record = $this->process($record);
        $this->assertSame($state, $record->vop_state);
        $this->travel(10)->seconds();
        $record = $this->process($record);
        $this->assertSame('MATCH', $record->vop_result);
        Http::assertSentCount(4);
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST');
        $this->assertCount(2, $posts);
    }

    public static function results(): array
    {
        return [['MATCH', 'Corrispondenza confermata'], ['CLOSE_MATCH', 'Corrispondenza parziale'], ['NO_MATCH', 'Intestatario non corrispondente'], ['NOT_APPLICABLE', 'Verifica intestatario non disponibile'], ['ERROR', 'Verifica intestatario non completata'], ['UNKNOWN', 'Verifica intestatario non completata']];
    }

    #[DataProvider('results')]
    public function test_all_vop_outcomes_remain_distinct_from_iban_validity(string $result, string $label): void
    {
        $retailer = Retailer::factory()->create();
        $name = $retailer->company_name;
        $record = $this->start($retailer);
        $this->fake($this->advanced(), $this->vop($result));
        $record = $this->process($this->process($record));
        $this->assertSame('valid', $record->iban_validation_status);
        $this->assertSame(self::IBAN, $retailer->fresh()->iban);
        $this->assertSame($name, $retailer->fresh()->company_name);
        if ($result === 'CLOSE_MATCH') {
            $this->assertSame('AZIENDA VERIFICATA', $record->vop_owner_name);
        } else {
            $this->assertNull($record->vop_owner_name);
        }
        $response = $this->get(route('retailer.profile'))->assertOk()->assertSee('IBAN verificato')->assertSee($label)->assertDontSee(self::IBAN);
        if ($result === 'CLOSE_MATCH') {
            $response->assertSee('Usa questa Ragione Sociale')->assertSee('AZIENDA VERIFICATA')->assertSee('Intestazione suggerita dalla banca');
        } else {
            $response->assertDontSee('Usa questa Ragione Sociale')->assertDontSee('AZIENDA VERIFICATA')->assertDontSee('Intestazione suggerita dalla banca');
        }
        if ($result !== 'MATCH') {
            $response->assertDontSee('Corrispondenza confermata');
        }
    }

    public function test_no_match_never_stores_or_exposes_provider_owner_name(): void
    {
        $retailer = Retailer::factory()->create();
        $record = $this->start($retailer);
        $this->fake($this->advanced(), $this->vop('NO_MATCH', 'PRIVATE ACCOUNT HOLDER'));
        $record = $this->process($this->process($record));

        $this->assertSame('NO_MATCH', $record->vop_result);
        $this->assertNull($record->vop_owner_name);
        $this->get(route('retailer.profile'))->assertOk()
            ->assertSee('Intestatario non corrispondente')
            ->assertSee('non identifica né rivela il titolare del conto')
            ->assertDontSee('PRIVATE ACCOUNT HOLDER')
            ->assertDontSee('Usa questa Ragione Sociale');
    }

    public function test_unchanged_profile_reuses_verification_and_name_edit_only_starts_vop(): void
    {
        $retailer = Retailer::factory()->create();
        $record = $this->start($retailer);
        $this->fake($this->advanced());
        $this->process($this->process($record));
        $retailer->refresh();
        $this->profile($retailer, ['iban' => self::IBAN])->assertSessionHasNoErrors();
        $this->profile($retailer, ['iban' => ''])->assertSessionHasNoErrors();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        $this->profile($retailer, ['company_name' => 'Nuova Ragione Sociale'])->assertSessionHasNoErrors();
        $next = RetailerBankVerification::latest('id')->first();
        $this->assertSame('valid', $next->iban_validation_status);
        $this->assertSame('Banca Test', $next->bank_data['bank']);
        $this->process($next);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://test.trust.openapi.com/iban-vop' && $request['name'] === 'Nuova Ragione Sociale');
    }

    public static function httpErrors(): array
    {
        return [[302, 'http_error'], [400, 'invalid_request'], [401, 'authentication_failed'], [403, 'authentication_failed'], [404, 'request_not_found'], [406, 'functional_error'], [429, 'rate_limited'], [500, 'service_unavailable']];
    }

    #[DataProvider('httpErrors')]
    public function test_http_errors_are_sanitized_and_posts_are_not_retried(int $status, string $reason): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        Http::fake(['*' => Http::response(['message' => self::IBAN.' fake-trust-token private-owner'], $status)]);
        $record = $this->process($record);
        $this->process($record);
        $this->assertSame('failed', $record->status);
        $this->assertSame($reason, $record->error_code);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        Http::assertSentCount(1);
        $this->get(route('retailer.profile'))->assertOk()->assertDontSee(self::IBAN)->assertDontSee('fake-trust-token')->assertDontSee('private-owner');
    }

    public function test_post_timeout_does_not_activate_or_repeat_and_missing_config_is_safe(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        Http::fake(['*' => Http::failedConnection()]);
        $record = $this->process($record);
        $this->assertSame('connection_failed', $record->error_code);
        $this->process($record);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        config(['services.openapi_trust.token' => null]);
        $this->post(route('retailer.bank-verification.store'), ['retry' => true])->assertRedirect();
        $this->assertSame('not_configured', RetailerBankVerification::latest('id')->first()->error_code);
        $this->get(route('retailer.profile'))->assertOk()->assertSee('Servizio di verifica non configurato')->assertDontSee(self::OTHER);
    }

    public function test_poll_retries_are_bounded_and_manual_retry_resumes_get_not_post(): void
    {
        config(['services.openapi_trust.max_attempts' => 2]);
        $retailer = Retailer::factory()->create();
        $record = $this->start($retailer);
        Http::fake(['*/iban-advanced/'.self::IBAN => Http::response($this->advanced(state: 'WAIT')), '*/iban-advanced/advanced-1' => Http::response([], 429)]);
        $record = $this->process($record);
        $this->travel(10)->seconds();
        $record = $this->process($record);
        $this->assertSame('attempts_exhausted', $record->error_code);
        $this->post(route('retailer.bank-verification.store'), ['retry' => true])->assertRedirect();
        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        $this->process($record);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'GET'));
    }

    public function test_inflight_old_response_cannot_replace_more_recent_candidate(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::IBAN]);
        $first = $this->start($retailer, self::OTHER);
        $service = app(RetailerBankVerificationService::class);
        $newIban = 'DE89370400440532013000';
        Http::fake(function ($request) use ($retailer, $service, $newIban) {
            if (str_ends_with($request->url(), self::OTHER)) {
                $second = DB::transaction(fn () => $service->requestLocked(Retailer::lockForUpdate()->findOrFail($retailer->id), $newIban));
                $this->process($second);

                return Http::response($this->advanced(self::OTHER, id: 'old-request'));
            }

            return Http::response($this->advanced($newIban, id: 'new-request'));
        });
        $this->process($first);
        $this->assertSame($newIban, $retailer->fresh()->iban);
        $this->assertSame('superseded', $first->fresh()->status);
    }

    public function test_legacy_iban_is_not_verified_automatically_and_explicit_route_is_owned_csrf_and_throttled(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::IBAN]);
        $this->actingAs($retailer->user)->get(route('retailer.profile'))->assertOk()->assertSee('Non ancora verificato')->assertSee('Verifica IBAN salvato')->assertDontSee(self::IBAN);
        $this->profile($retailer, ['company_name' => 'Nome legacy aggiornato'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('retailer_bank_verifications', 0);
        $other = Retailer::factory()->create();
        $this->post(route('retailer.bank-verification.store'), ['retailer_id' => $other->id])->assertSessionHasErrors('retailer_id');
        $this->post(route('retailer.bank-verification.store'))->assertRedirect();
        $this->assertDatabaseHas('retailer_bank_verifications', ['retailer_id' => $retailer->id]);
        $this->actingAs($other->user)->get(route('retailer.profile'))->assertOk()->assertDontSee('3456');
        $this->actingAs(User::factory()->create(['role' => 'customer']))->post(route('retailer.bank-verification.store'))->assertForbidden();
        $this->actingAs($retailer->user);
        $this->app->instance('env', 'production');
        $this->post(route('retailer.bank-verification.store'))->assertStatus(419);
        Http::assertNothingSent();
    }

    public function test_verification_route_is_rate_limited(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::IBAN]);
        $this->actingAs($retailer->user);
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('retailer.bank-verification.store'))->assertRedirect();
        }
        $this->post(route('retailer.bank-verification.store'))->assertStatus(429);
        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        Http::assertNothingSent();
    }

    public function test_malformed_response_retains_id_and_manual_retry_uses_get(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        $response = $this->advanced();
        unset($response['data']['validations']);
        Http::fake(['*/iban-advanced/'.self::IBAN => Http::response($response), '*/iban-advanced/advanced-1' => Http::response($this->advanced())]);
        $record = $this->process($record);
        $this->assertSame('invalid_response', $record->error_code);
        $this->assertSame('advanced-1', $record->advanced_request_id);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        $this->post(route('retailer.bank-verification.store'), ['retry' => true])->assertRedirect();
        $this->process($record);
        $this->assertSame(self::IBAN, $retailer->fresh()->iban);
        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'GET'));
    }

    public function test_lost_post_worker_is_not_replayed_and_job_contains_only_identifier(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        $record->update(['advanced_state' => 'SUBMITTING', 'processing_at' => now()->subMinutes(2)]);
        $this->assertSame('submission_uncertain', $this->process($record)->error_code);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        $payload = serialize(new ProcessRetailerBankVerification($record->id));
        $this->assertStringNotContainsString(self::IBAN, $payload);
        $this->assertStringNotContainsString($record->company_name_checked, $payload);
        Http::assertNothingSent();
    }

    public function test_queue_recovery_dispatches_due_work_without_contacting_provider(): void
    {
        $record = $this->start(Retailer::factory()->create());
        Queue::assertPushed(ProcessRetailerBankVerification::class);
        Queue::fake();
        $this->artisan('bank-verifications:resume')->assertSuccessful();
        Queue::assertPushed(ProcessRetailerBankVerification::class, fn ($job) => $job->verificationId === $record->id && $job->connection === 'database' && $job->queue === 'integrations');
        Http::assertNothingSent();
    }

    public function test_queue_must_be_durable_and_missing_configuration_never_erases_active_account(): void
    {
        config(['integrations.queue_connection' => 'sync']);
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        $this->assertSame('queue_not_configured', $record->error_code);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        Http::assertNothingSent();
    }

    public function test_base_url_is_configurable_and_redirects_are_disabled(): void
    {
        config(['services.openapi_trust.base_url' => 'https://trust.openapi.com']);
        $record = $this->start(Retailer::factory()->create());
        $this->fake($this->advanced());
        $this->process($record);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://trust.openapi.com/iban-advanced/') && $request->hasHeader('Accept', 'application/json'));
    }

    public function test_new_company_name_during_pending_advanced_does_not_duplicate_paid_request(): void
    {
        $retailer = Retailer::factory()->create();
        $record = $this->start($retailer);
        Http::fake(['*/iban-advanced/'.self::IBAN => Http::response($this->advanced(state: 'NEW')), '*/iban-advanced/advanced-1' => Http::response($this->advanced()), '*/iban-vop' => Http::response($this->vop())]);
        $record = $this->process($record);
        $this->profile($retailer, ['company_name' => 'Nuova intestazione'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        $this->travel(10)->seconds();
        $this->process($this->process($record));
        Http::assertSent(fn ($request) => $request->url() === 'https://test.trust.openapi.com/iban-vop' && $request['name'] === 'Nuova intestazione');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'iban-advanced')));
    }

    public function test_explicit_same_candidate_with_name_change_reuses_pending_advanced_request(): void
    {
        $retailer = Retailer::factory()->create();
        $record = $this->start($retailer);
        Http::fake([
            '*/iban-advanced/'.self::IBAN => Http::response($this->advanced(state: 'WAIT')),
            '*/iban-advanced/advanced-1' => Http::response($this->advanced()),
            '*/iban-vop' => Http::response($this->vop()),
        ]);

        $record = $this->process($record);
        $this->profile($retailer, ['company_name' => 'Nuova intestazione', 'iban' => self::IBAN])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        $this->assertSame($record->id, RetailerBankVerification::latest('id')->value('id'));
        $this->assertSame('Nuova intestazione', $record->fresh()->company_name_checked);

        $this->travel(10)->seconds();
        $record = $this->process($record);
        $this->assertSame('valid', $record->iban_validation_status);
        $this->process($record);

        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'iban-advanced')));
        Http::assertSent(fn ($request) => $request->url() === 'https://test.trust.openapi.com/iban-vop' && $request['name'] === 'Nuova intestazione');
    }

    public function test_bank_verification_request_rejects_non_boolean_retry_and_protected_fields(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::IBAN]);
        $this->actingAs($retailer->user)
            ->post(route('retailer.bank-verification.store'), ['retry' => 'not-a-boolean', 'iban' => self::OTHER])
            ->assertSessionHasErrors(['retry', 'iban']);

        $this->assertDatabaseCount('retailer_bank_verifications', 0);
        Http::assertNothingSent();
    }

    public function test_admin_sees_configuration_readiness_without_credentials_or_accounts(): void
    {
        config(['services.openapi_trust.token' => null]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.settings'))->assertOk()->assertSee('Servizio di verifica non configurato')->assertDontSee(self::IBAN);
        config(['services.openapi_trust.token' => 'fake-trust-token']);
        $this->get(route('admin.settings'))->assertOk()->assertSee('Servizio configurato')->assertDontSee('fake-trust-token');
        $this->actingAs(Retailer::factory()->create()->user)->get(route('admin.settings'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_transient_get_failure_is_retried_with_backoff_then_completes_without_new_post(): void
    {
        $retailer = Retailer::factory()->create();
        $record = $this->start($retailer);
        Http::fake(['*/iban-advanced/'.self::IBAN => Http::response($this->advanced(state: 'WAIT')), '*/iban-advanced/advanced-1' => Http::sequence()->push([], 500)->push($this->advanced())]);
        $record = $this->process($record);
        $this->travel(10)->seconds();
        $record = $this->process($record);
        $this->assertSame('pending', $record->status);
        $this->assertSame('service_unavailable', $record->error_code);
        $this->assertNull($retailer->fresh()->iban);
        $this->travel(15)->seconds();
        $record = $this->process($record);
        $this->assertSame('valid', $record->iban_validation_status);
        $this->assertSame(self::IBAN, $retailer->fresh()->iban);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'GET'));
    }

    public function test_conflicting_vop_errors_cannot_confirm_account_holder(): void
    {
        $retailer = Retailer::factory()->create();
        $record = $this->start($retailer);
        $vop = $this->vop();
        $vop['data']['errors'] = [['message' => 'Private provider error']];
        $this->fake($this->advanced(), $vop);
        $record = $this->process($this->process($record));
        $this->assertSame('valid', $record->iban_validation_status);
        $this->assertSame('ERROR', $record->vop_result);
        $this->assertSame('vop-1', $record->vop_request_id);
        $this->get(route('retailer.profile'))->assertOk()->assertDontSee('Corrispondenza confermata')->assertDontSee('Private provider error');
    }

    public function test_name_only_edit_does_not_verify_legacy_account_after_another_candidate_failed(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::OTHER]);
        $record = $this->start($retailer);
        $this->fake($this->advanced(valid: false));
        $this->process($record);
        $this->profile($retailer, ['company_name' => 'Nuovo nome legacy'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('retailer_bank_verifications', 1);
        $this->assertSame(self::OTHER, $retailer->fresh()->iban);
        Http::assertSentCount(1);
    }

    public function test_explicit_legacy_iban_input_is_verified_even_when_name_also_changes(): void
    {
        $retailer = Retailer::factory()->create(['iban' => self::IBAN]);
        $this->profile($retailer, ['company_name' => 'Nuova azienda', 'iban' => self::IBAN])->assertSessionHasNoErrors();
        $record = RetailerBankVerification::where('retailer_id', $retailer->id)->sole();
        $this->assertSame('pending', $record->iban_validation_status);
        $this->assertSame('Nuova azienda', $record->company_name_checked);
        $this->fake($this->advanced());
        $record = $this->process($record);
        $this->assertSame('valid', $record->iban_validation_status);
        Http::assertSentCount(1);
    }
}
