<?php

namespace Tests\Feature;

use App\Enums\RetailerStatus;
use App\Enums\UserRole;
use App\Models\AvailabilityRequest;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetailerFlowTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $extra = []): array
    {
        return array_replace(['company_name' => 'Edilizia Roma', 'vat_number' => '12345678901', 'city' => 'Roma', 'email' => 'retailer@example.test', 'password' => 'test-password', 'password_confirmation' => 'test-password', 'plan_code' => 'free'], $extra);
    }

    public function test_registration_creates_company_and_free_subscription_but_not_approval(): void
    {
        $this->seed(PlanSeeder::class);
        $this->post('/register', $this->registration(['status' => 'approved', 'role' => 'admin', 'price_cents' => 1]))->assertSessionHasNoErrors();
        $retailer = Retailer::sole();
        $this->assertSame(RetailerStatus::Pending, $retailer->status);
        $this->assertSame(UserRole::Retailer, $retailer->user->role);
        $this->assertSame('free', $retailer->activeSubscription->plan->code);
        $this->assertSame(0, $retailer->activeSubscription->price_cents);
        $this->get(route('retailer.dashboard'))->assertOk()->assertSee('Profilo in verifica');
        $this->get(route('retailer.sell'))->assertForbidden();
    }

    public function test_pro_request_is_pending_and_does_not_approve_the_company(): void
    {
        $this->seed(PlanSeeder::class);
        $this->post('/register', $this->registration(['plan_code' => 'pro']))->assertSessionHasNoErrors();
        $retailer = Retailer::sole();
        $this->assertNull($retailer->activeSubscription);
        $this->assertSame(6900, $retailer->subscriptions()->sole()->price_cents);
        $this->get(route('retailer.dashboard'))->assertOk()->assertSee('Piano richiesto: PRO')->assertSee('Profilo in verifica');
    }

    public function test_registration_validation_does_not_create_partial_accounts(): void
    {
        $this->seed(PlanSeeder::class);
        $this->post('/register', $this->registration(['vat_number' => 'bad', 'plan_code' => 'missing']))->assertSessionHasErrors(['vat_number', 'plan_code']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('retailers', 0);
        Plan::where('code', 'free')->update(['is_active' => false]);
        $this->post('/register', $this->registration())->assertSessionHasErrors('plan_code');
    }

    public function test_duplicate_vat_cannot_register_another_account(): void
    {
        $this->seed(PlanSeeder::class);
        Retailer::factory()->create(['vat_number' => '12345678901']);
        $this->post('/register', $this->registration())->assertSessionHasErrors('vat_number');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_profile_changes_only_owned_fields_and_masks_iban(): void
    {
        $retailer = Retailer::factory()->create();
        $other = Retailer::factory()->create();
        $this->actingAs($retailer->user)->put(route('retailer.profile.update'), ['company_name' => 'Nuova azienda', 'vat_number' => $retailer->vat_number, 'city' => 'Milano', 'iban' => 'IT60 X054 2811 1010 0000 0123 456', 'user_id' => $other->user_id, 'status' => 'approved', 'id' => $other->id])->assertSessionHasNoErrors()->assertRedirect(route('retailer.profile'));
        $this->assertSame('Nuova azienda', $retailer->fresh()->company_name);
        $this->assertSame(RetailerStatus::Pending, $retailer->fresh()->status);
        $this->assertNotSame('Nuova azienda', $other->fresh()->company_name);
        $this->get(route('retailer.profile'))->assertOk()->assertSee('3456')->assertDontSee('IT60X0542811101000000123456');
        $this->assertFalse($retailer->user->can('update', $other));
        $this->assertFalse($retailer->user->can('operate', $other));
    }

    public function test_invalid_iban_is_not_saved_or_flashed(): void
    {
        $retailer = Retailer::factory()->create();
        $this->actingAs($retailer->user)->from(route('retailer.profile'))->put(route('retailer.profile.update'), ['company_name' => $retailer->company_name, 'vat_number' => $retailer->vat_number, 'city' => $retailer->city, 'iban' => 'IT00X0542811101000000123456'])->assertSessionHasErrors('iban')->assertSessionMissing('_old_input.iban');
    }

    public function test_account_states_control_all_operational_routes(): void
    {
        foreach (['pending', 'approved', 'rejected', 'suspended'] as $state) {
            $retailer = Retailer::factory()->create(['status' => $state, 'approved_at' => $state === 'approved' ? now() : null, 'rejected_at' => $state === 'rejected' ? now() : null]);
            $this->actingAs($retailer->user)->get(route('retailer.dashboard'))->assertOk()->assertSee(match ($state) {
                'pending' => 'Profilo in verifica','approved' => 'Profilo approvato','rejected' => 'Iscrizione non approvata',default => 'Profilo sospeso'
            });
            foreach (['stock', 'sell', 'requests', 'credit'] as $route) {
                $response = $this->get(route('retailer.'.$route));
                $state === 'approved' ? $response->assertOk() : $response->assertForbidden();
            }
            $this->get(route('retailer.profile'))->assertOk();
        }
    }

    public function test_admin_approval_is_audited_and_does_not_activate_pro(): void
    {
        $retailer = Retailer::factory()->create();
        $subscription = Subscription::factory()->create(['retailer_id' => $retailer->id, 'status' => 'pending']);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patch(route('admin.retailers.review', $retailer), ['decision' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame(RetailerStatus::Approved, $retailer->fresh()->status);
        $this->assertNotNull($retailer->fresh()->approved_at);
        $this->assertSame('pending', $subscription->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $admin->id, 'action' => 'retailer_approved', 'subject_id' => $retailer->id]);
        $this->patch(route('admin.retailers.review', $retailer), ['decision' => 'rejected', 'reason' => 'No'])->assertSessionHasErrors('decision');
    }

    public function test_rejection_requires_reason_and_retailer_cannot_review(): void
    {
        $retailer = Retailer::factory()->create();
        $this->actingAs($retailer->user)->patch(route('admin.retailers.review', $retailer), ['decision' => 'approved'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->patch(route('admin.retailers.review', $retailer), ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->patch(route('admin.retailers.review', $retailer), ['decision' => 'rejected', 'reason' => 'Dati da verificare'])->assertSessionHasNoErrors();
        $this->actingAs($retailer->user)->get(route('retailer.dashboard'))->assertSee('Iscrizione non approvata')->assertSee('Dati da verificare');
    }

    public function test_dashboard_totals_and_requests_are_owner_scoped(): void
    {
        $retailer = Retailer::factory()->approved()->create();
        $other = Retailer::factory()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id]);
        $item = InventoryItem::factory()->create(['retailer_id' => $retailer->id, 'quantity' => '2.000', 'zero_price_cents' => 1250]);
        $otherItem = InventoryItem::factory()->create(['retailer_id' => $other->id, 'name' => 'SECRET OTHER STOCK']);
        AvailabilityRequest::factory()->create(['retailer_id' => $retailer->id, 'inventory_item_id' => $item->id, 'customer_name' => 'Cliente visibile']);
        AvailabilityRequest::factory()->create(['retailer_id' => $other->id, 'inventory_item_id' => $otherItem->id, 'customer_name' => 'Cliente segreto']);
        $this->actingAs($retailer->user)->get(route('retailer.dashboard'))->assertOk()->assertSee('25,00 €')->assertSee('Cliente visibile')->assertDontSee('Cliente segreto')->assertDontSee('SECRET OTHER STOCK');
    }
}
