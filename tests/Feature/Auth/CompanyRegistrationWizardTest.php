<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\RegisterUser;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Company\CompanyRegistrationLookup;
use App\Services\Company\CompanyStartMapper;
use App\Support\RegistrationMessages;
use Database\Seeders\PlanSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class CompanyRegistrationWizardTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private const VAT = '12485671007';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['services.openapi_company.token' => 'fake-company-token', 'services.openapi_company.base_url' => 'https://test.company.openapi.com']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function company(array $overrides = []): array
    {
        return ['success' => true, 'error' => null, 'data' => [array_replace_recursive(['companyName' => 'OPENAPI S.P.A.', 'vatCode' => self::VAT, 'id' => 'company-fixture', 'activityStatus' => 'ATTIVA', 'address' => ['registeredOffice' => ['streetName' => 'VIALE ESEMPIO 221', 'toponym' => 'VIALE', 'street' => 'ESEMPIO', 'streetNumber' => '221', 'town' => 'ROMA', 'province' => 'RM', 'region' => ['description' => 'LAZIO']]]], $overrides)]];
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['vat_number' => self::VAT, 'company_name' => 'Nome confermato', 'city' => 'Milano', 'address' => 'Indirizzo corretto 1', 'province' => 'MI', 'region' => 'Lombardia', 'email' => 'new@example.test', 'password' => 'password123', 'password_confirmation' => 'password123', 'plan_code' => 'free'], $overrides);
    }

    private function lookup(): void
    {
        $this->post(route('register.company.lookup'), ['vat_number' => self::VAT])->assertRedirect(route('register.details'))->assertSessionHasNoErrors();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    public function test_step_one_only_collects_vat_and_preserves_account_links(): void
    {
        $response = $this->get(route('register'))->assertOk()->assertSee('Registra la tua azienda')->assertSee(route('login'))->assertSee(route('customer.register'));
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//input[@name="vat_number"]')->length);
        foreach (['company_name', 'city', 'email', 'password', 'plan_code'] as $name) {
            $this->assertSame(0, $xpath->query('//*[@name="'.$name.'"]')->length);
        }
        $this->assertSame(1, $xpath->query('//*[@aria-current="step"]')->length);
        Http::assertNothingSent();
    }

    public function test_invalid_and_duplicate_vat_stop_before_paid_lookup(): void
    {
        $this->post(route('register.company.lookup'), ['vat_number' => '123x45678901'])->assertSessionHasErrors('vat_number');
        Retailer::factory()->create(['vat_number' => self::VAT]);
        $this->post(route('register.company.lookup'), ['vat_number' => ' IT 12485671007 '])->assertSessionHasErrors(['vat_number' => RegistrationMessages::DUPLICATE_VAT, 'company_exists']);
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('register'))->assertOk()->assertSee('Azienda già registrata')->assertSee('Recupera password');
        Http::assertNothingSent();
    }

    public function test_found_company_maps_prefill_without_creating_records_and_final_values_are_editable(): void
    {
        Http::fake(['*' => Http::response($this->company())]);
        $this->post(route('register.company.lookup'), ['vat_number' => ' it 12485671007 '])->assertRedirect(route('register.details'));
        $context = session(CompanyRegistrationLookup::SESSION_KEY);
        $this->assertSame(['company_name' => 'OPENAPI S.P.A.', 'vat_number' => self::VAT, 'address' => 'VIALE ESEMPIO 221', 'city' => 'ROMA', 'province' => 'RM', 'region' => 'LAZIO'], $context['prefill']);
        foreach (['contact_name', 'contact_email', 'phone', 'website'] as $field) {
            $this->assertArrayNotHasKey($field, $context['prefill']);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('retailers', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $response = $this->get(route('register.details'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Azienda trovata')->assertSee('value="OPENAPI S.P.A."', false)->assertSee('OpenAPI Company Start');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//input[@name="vat_number" and @readonly]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="company_name" and @readonly]')->length);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://test.company.openapi.com/IT-start/'.self::VAT && $request->hasHeader('Authorization', 'Bearer fake-company-token') && $request->hasHeader('Accept', 'application/json'));
        Event::fake([Registered::class]);
        $this->post(route('register.store'), $this->data())->assertRedirect(route('dashboard', absolute: false))->assertSessionHasNoErrors()->assertSessionMissing(CompanyRegistrationLookup::SESSION_KEY);
        $retailer = Retailer::sole();
        $this->assertSame('Nome confermato', $retailer->company_name);
        $this->assertSame('Indirizzo corretto 1', $retailer->address);
        $this->assertSame('Milano', $retailer->city);
        $this->assertSame('pending', $retailer->status->value);
        $this->assertSame('active', Subscription::sole()->status->value);
        Event::assertDispatched(Registered::class);
        $this->assertAuthenticated();
    }

    public function test_address_composition_omits_nulls_and_redundant_whitespace(): void
    {
        $result = app(CompanyStartMapper::class)->map($this->company(['address' => ['registeredOffice' => ['streetName' => null, 'toponym' => ' VIA ', 'street' => ' DEL  CORSO ', 'streetNumber' => null]]])['data'][0], self::VAT);
        $this->assertSame('VIA DEL CORSO', $result->prefill['address']);
    }

    public static function notFound(): array
    {
        return [[204, null], [404, ['success' => false, 'error' => 302, 'data' => null]]];
    }

    #[DataProvider('notFound')]
    public function test_canonical_not_found_allows_manual_registration(int $status, ?array $body): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->lookup();
        $this->assertSame('not_found', session(CompanyRegistrationLookup::SESSION_KEY)['status']);
        $this->get(route('register.details'))->assertOk()->assertSee('Azienda non trovata automaticamente')->assertSee('value="'.self::VAT.'"', false);
        $this->post(route('register.store'), $this->data())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(self::VAT, Retailer::sole()->vat_number);
    }

    public static function errors(): array
    {
        return [[401], [403], [402], [429], [500], [503], [404], [406], [302]];
    }

    #[DataProvider('errors')]
    public function test_technical_errors_offer_manual_choice_instead_of_not_found(int $status): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'fake-company-token private payload'], $status)]);
        $this->lookup();
        $this->assertSame('unavailable', session(CompanyRegistrationLookup::SESSION_KEY)['status']);
        $this->get(route('register.details'))->assertOk()->assertSee('Riprova')->assertSee('Continua manualmente')->assertDontSee('Azienda non trovata')->assertDontSee('private payload')->assertDontSee('fake-company-token')->assertDontSee('name="password"', false);
        $this->post(route('register.company.manual'))->assertRedirect(route('register.details'));
        $this->get(route('register.details'))->assertOk()->assertSee('name="password"', false);
        Http::assertSentCount(1);
    }

    public function test_timeout_then_explicit_manual_choice_can_complete_registration(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->lookup();
        $this->assertFalse(session(CompanyRegistrationLookup::SESSION_KEY)['ready']);
        $this->post(route('register.store'), $this->data())->assertSessionHasErrors('registration_step');
        $this->post(route('register.company.manual'))->assertRedirect(route('register.details'));
        $this->post(route('register.store'), $this->data())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('retailers', 1);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_missing_token_allows_manual_registration_without_fatal_error(): void
    {
        config(['services.openapi_company.token' => null]);
        $this->lookup();
        $this->get(route('register.details'))->assertOk()->assertSee('non è configurato');
        $this->post(route('register.company.manual'))->assertRedirect();
        $this->post(route('register.store'), $this->data())->assertSessionHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_cache_prevents_repeated_calls_and_step_two_refresh_never_calls_api(): void
    {
        Http::fake(['*' => Http::response($this->company())]);
        $this->lookup();
        $this->get(route('register.details'))->assertOk();
        $this->get(route('register.details'))->assertOk();
        $this->get(route('register'))->assertOk()->assertSessionMissing(CompanyRegistrationLookup::SESSION_KEY);
        $this->lookup();
        Http::assertSentCount(1);
        $this->travel(7)->hours();
        $this->lookup();
        Http::assertSentCount(2);
    }

    public function test_technical_error_cache_expires_quickly(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 500)->push($this->company())]);
        $this->lookup();
        $this->lookup();
        Http::assertSentCount(1);
        $this->travel(21)->seconds();
        $this->lookup();
        Http::assertSentCount(2);
        $this->assertSame('found', session(CompanyRegistrationLookup::SESSION_KEY)['status']);
    }

    public function test_missing_expired_or_browser_forged_context_cannot_skip_first_step(): void
    {
        $this->get(route('register.details'))->assertRedirect(route('register'));
        $this->post(route('register.store'), $this->data() + ['company_lookup' => ['ready' => true]])->assertSessionHasErrors('registration_step');
        Http::fake(['*' => Http::response($this->company())]);
        $this->lookup();
        $this->travel(31)->minutes();
        $this->get(route('register.details'))->assertRedirect(route('register'))->assertSessionMissing(CompanyRegistrationLookup::SESSION_KEY);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_vat_cannot_change_at_final_submit_and_readonly_field_keeps_original_after_error(): void
    {
        Http::fake(['*' => Http::response($this->company())]);
        $this->lookup();
        $this->post(route('register.store'), $this->data(['vat_number' => '12345678901']))->assertSessionHasErrors('vat_number');
        $this->get(route('register.details'))->assertOk()->assertSee('value="'.self::VAT.'"', false)->assertDontSee('value="12345678901"', false);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_mismatched_response_preserves_entered_vat_and_falls_back_safely(): void
    {
        Http::fake(['*' => Http::response($this->company(['vatCode' => '12345678901']))]);
        $this->lookup();
        $context = session(CompanyRegistrationLookup::SESSION_KEY);
        $this->assertSame('unavailable', $context['status']);
        $this->assertSame('vat_mismatch', $context['reason']);
        $this->assertSame(self::VAT, $context['vat_number']);
        $this->assertSame([], $context['prefill']);
    }

    public function test_duplicate_at_final_submit_does_not_create_an_orphan(): void
    {
        Http::fake(['*' => Http::response($this->company())]);
        $this->lookup();
        Retailer::factory()->create(['vat_number' => self::VAT]);
        $this->post(route('register.store'), $this->data())->assertSessionHasErrors(['vat_number', 'company_exists']);
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('register.details'))->assertOk()->assertSee('Azienda già registrata');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_final_validation_still_checks_email_plan_password_and_required_company_data(): void
    {
        Http::fake(['*' => Http::response($this->company())]);
        $this->lookup();
        User::factory()->create(['email' => 'new@example.test']);
        Plan::where('code', 'free')->update(['is_active' => false]);
        $this->post(route('register.store'), $this->data(['password_confirmation' => 'different', 'company_name' => '', 'city' => '']))->assertSessionHasErrors(['email', 'plan_code', 'password', 'company_name', 'city']);
        $this->post(route('register.store'), $this->data(['plan_code' => 'missing']))->assertSessionHasErrors('plan_code');
        $this->assertDatabaseCount('retailers', 0);
    }

    public function test_inactive_company_is_informative_and_pro_subscription_stays_pending(): void
    {
        Http::fake(['*' => Http::response($this->company(['activityStatus' => 'CESSATA']))]);
        $this->lookup();
        $this->get(route('register.details'))->assertOk()->assertSee('CESSATA')->assertSee('stato aziendale non attivo');
        $this->post(route('register.store'), $this->data(['plan_code' => 'pro']))->assertSessionHasNoErrors();
        $this->assertSame('pending', Retailer::sole()->status->value);
        $this->assertSame('pending', Subscription::sole()->status->value);
    }

    public function test_existing_atomic_transaction_rolls_back_all_three_models_on_failure(): void
    {
        $listener = fn () => throw new RuntimeException('test subscription failure');
        Event::listen('eloquent.creating: '.Subscription::class, $listener);
        try {
            app(RegisterUser::class)->handle($this->data());
            $this->fail('Expected rollback');
        } catch (RuntimeException $exception) {
            $this->assertSame('test subscription failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('retailers', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_lookup_csrf_guest_restriction_and_rate_limit(): void
    {
        Http::fake(['*' => Http::response($this->company())]);
        for ($i = 0; $i < 10; $i++) {
            $this->lookup();
        }
        $this->post(route('register.company.lookup'), ['vat_number' => self::VAT])->assertStatus(429);
        Http::assertSentCount(1);
        $this->actingAs(User::factory()->create())->get(route('register.details'))->assertRedirect();
    }

    public function test_lookup_and_manual_post_require_csrf_and_customer_flow_is_unchanged(): void
    {
        $this->get(route('customer.register'))->assertOk()->assertSee('Crea account cliente');
        $this->app->instance('env', 'production');
        $this->post(route('register.company.lookup'), ['vat_number' => self::VAT])->assertStatus(419);
        $this->post(route('register.company.manual'))->assertStatus(419);
        Http::assertNothingSent();
    }
}
