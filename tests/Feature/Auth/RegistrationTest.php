<?php

namespace Tests\Feature\Auth;

use Database\Seeders\PlanSeeder;
use Tests\Concerns\AdditiveRefreshDatabase as RefreshDatabase;
use Tests\Concerns\WithCompanyRegistrationContext;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;
    use WithCompanyRegistrationContext;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $this->seed(PlanSeeder::class);
        $response = $this->withCompanyRegistrationContext()->post('/register', [
            'company_name' => 'Test Company', 'vat_number' => '12345678901', 'city' => 'Roma', 'plan_code' => 'free',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
