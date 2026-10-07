<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_and_login_are_public(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('Zero')->assertSee('Accedi');
        $this->get(route('login'))->assertOk()->assertSee('Bentornato')->assertSee('name="_token"', false);
    }

    public function test_all_private_pages_redirect_guests_to_login(): void
    {
        $routes = ['dashboard'];
        foreach (['retailer', 'admin'] as $area) {
            $routes = array_merge($routes, array_column(config('navigation.'.$area), 'route'));
        }
        foreach ($routes as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_retailer_can_render_all_retailer_placeholders(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertRedirect(route('retailer.dashboard'));
        foreach (config('navigation.retailer') as $link) {
            $this->get(route($link['route']))->assertOk()->assertSee($link['label'])->assertSee('In preparazione');
        }
    }

    public function test_admin_can_render_all_admin_placeholders(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
        foreach (config('navigation.admin') as $link) {
            $this->get(route($link['route']))->assertOk()->assertSee($link['label'])->assertSee('In preparazione');
        }
    }

    public function test_retailer_cannot_access_any_admin_page(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (config('navigation.admin') as $link) {
            $this->get(route($link['route']))->assertForbidden();
        }
    }

    public function test_admin_cannot_access_retailer_pages(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        foreach (config('navigation.retailer') as $link) {
            $this->get(route($link['route']))->assertForbidden();
        }
    }

    public function test_registration_cannot_assign_admin_role(): void
    {
        $this->post(route('register'), [
            'name' => 'Retailer',
            'email' => 'retailer@example.test',
            'password' => 'a-test-password',
            'password_confirmation' => 'a-test-password',
            'role' => 'admin',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame(UserRole::Retailer, User::sole()->role);
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_csrf_rejects_a_post_without_token(): void
    {
        // Laravel normally bypasses CSRF in tests. Restore its real environment check.
        $this->app['env'] = 'local';
        $this->post(route('login'), [])->assertStatus(419);
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $this->freezeTime();
        $credentials = ['email' => 'unknown@example.test', 'password' => 'wrong'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login'), $credentials)->assertSessionHasErrors('email');
        }
        $this->post(route('login'), $credentials)->assertSessionHasErrors([
            'email' => __('auth.throttle', ['seconds' => 60, 'minutes' => 1]),
        ]);
    }
}
