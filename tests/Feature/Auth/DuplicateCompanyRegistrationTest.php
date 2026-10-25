<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\RegisterUser;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Support\RegistrationMessages;
use Database\Seeders\PlanSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\Concerns\WithCompanyRegistrationContext;
use Tests\TestCase;

class DuplicateCompanyRegistrationTest extends TestCase
{
    use AdditiveRefreshDatabase;
    use WithCompanyRegistrationContext;

    private function registration(): array
    {
        $this->withCompanyRegistrationContext();

        return ['company_name' => 'Nuova azienda', 'vat_number' => '12345678901', 'city' => 'Roma', 'plan_code' => 'free', 'email' => 'new-account@example.test', 'password' => 'password', 'password_confirmation' => 'password'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Retailer::factory()->create(['vat_number' => '12345678901']);
    }

    public function test_duplicate_vat_has_structured_alert_and_account_recovery_links_without_creating_any_data(): void
    {
        $counts = [User::count(), Retailer::count(), Subscription::count()];
        $this->from('/register')->post('/register', $this->registration())->assertRedirect('/register/details')
            ->assertSessionHasErrors(['vat_number' => RegistrationMessages::DUPLICATE_VAT, 'company_exists' => RegistrationMessages::DUPLICATE_VAT]);
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get('/register/details')->assertOk()
            ->assertSee('Azienda già registrata')->assertSee(RegistrationMessages::DUPLICATE_VAT)
            ->assertSee('href="'.route('login').'"', false)->assertSee('href="'.route('password.request').'"', false)->assertSee('Recupera password');
        $this->assertSame($counts, [User::count(), Retailer::count(), Subscription::count()]);
        $this->assertGuest();
        $response->assertSee('aria-invalid="true"', false)->assertDontSee('value="password"', false);
    }

    public function test_malformed_vat_does_not_display_the_company_exists_alert(): void
    {
        $data = $this->registration();
        $data['vat_number'] = 'invalid';
        $this->from('/register')->post('/register', $data)->assertSessionHasErrors('vat_number')->assertSessionDoesntHaveErrors('company_exists');
        $this->withCookie(config('session.cookie'), session()->getId())->get('/register')->assertOk()->assertDontSee('Azienda già registrata');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('retailers', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertGuest();
    }

    public function test_db_unique_protection_rolls_back_a_user_created_after_a_stale_validation(): void
    {
        // Bypass request validation to reproduce a VAT inserted between validation and save.
        try {
            app(RegisterUser::class)->handle($this->registration());
            $this->fail('The database UNIQUE index must reject the duplicate VAT.');
        } catch (ValidationException $exception) {
            $this->assertSame([RegistrationMessages::DUPLICATE_VAT], $exception->errors()['vat_number']);
            $this->assertArrayHasKey('company_exists', $exception->errors());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('retailers', 1);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertGuest();
    }
}
