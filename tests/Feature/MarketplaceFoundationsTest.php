<?php

namespace Tests\Feature;

use App\Actions\SaveCustomerAddress;
use App\Actions\SaveInventoryItem;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceFoundationsTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function retailer(): Retailer
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);

        return $r;
    }

    private function product(array $data = []): array
    {
        return array_replace(['name' => 'Cemento', 'category_id' => Category::where('slug', 'edilizia')->sole()->id, 'quantity' => '1.250', 'unit' => 'kg', 'zero_price' => '12.00', 'condition' => 'new', 'province' => 'Roma', 'description' => 'Sacco disponibile', 'shipping_available' => true, 'intent' => 'pending'], $data);
    }

    public function test_customer_registration_is_separate_hashed_and_cannot_assign_roles(): void
    {
        $this->get(route('customer.register'))->assertOk()->assertSee('Crea account cliente');
        $this->post(route('customer.register'), ['first_name' => 'Ada', 'last_name' => 'Rossi', 'email' => 'ada@example.com', 'password' => 'password123', 'password_confirmation' => 'password123', 'phone' => '12345', 'role' => 'admin', 'plan_code' => 'pro'])->assertRedirect(route('customer.dashboard'));
        $u = User::where('email', 'ada@example.com')->sole();
        $this->assertSame(UserRole::Customer, $u->role);
        $this->assertSame('Ada Rossi', $u->name);
        $this->assertTrue(Hash::check('password123', $u->password));
        $this->assertNull($u->retailer);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertAuthenticatedAs($u);
        $this->get(route('dashboard'))->assertRedirect(route('customer.dashboard'));
        $this->get(route('retailer.dashboard'))->assertForbidden();
        $this->get(route('admin.categories.index'))->assertForbidden();
    }

    public function test_customer_registration_requires_names_confirmation_and_unique_email(): void
    {
        User::factory()->create(['email' => 'used@example.com']);
        $this->post(route('customer.register'), ['email' => 'used@example.com', 'password' => 'password123'])->assertSessionHasErrors(['first_name', 'last_name', 'email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_purchase_capability_keeps_retailer_role_and_excludes_admin(): void
    {
        $r = $this->retailer();
        $customer = User::factory()->create(['role' => 'customer']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($r->user->can('purchase', User::class));
        $this->assertTrue($customer->can('purchase', User::class));
        $this->assertFalse($admin->can('purchase', User::class));
        $this->actingAs($r->user)->get(route('customer.dashboard'))->assertOk();
        $this->assertSame(UserRole::Retailer, $r->user->fresh()->role);
        $this->assertSame($r->id, $r->user->retailer->id);
        $this->assertFalse($r->user->can('proposeExchange', User::class));
        $r->activeSubscription->update(['plan_id' => Plan::where('code', 'pro')->sole()->id]);
        $r->user->unsetRelation('retailer');
        $this->assertTrue($r->user->can('proposeExchange', User::class));
        $r->update(['status' => 'suspended']);
        $r->user->unsetRelation('retailer');
        $this->assertFalse($r->user->can('proposeExchange', User::class));
        $this->actingAs($admin)->get(route('customer.dashboard'))->assertForbidden();
    }

    public function test_only_admin_can_manage_categories_and_unused_category_can_be_deleted(): void
    {
        $r = $this->retailer();
        $data = ['name' => 'Utensili', 'slug' => 'utensili', 'description' => 'Descrizione', 'is_active' => 1, 'sort_order' => 12];
        $this->actingAs($r->user)->post(route('admin.categories.store'), $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('admin.categories.store'), $data)->assertRedirect(route('admin.categories.index'));
        $c = Category::where('slug', 'utensili')->sole();
        $this->get(route('admin.categories.index'))->assertOk()->assertSee('Utensili');
        $this->get(route('admin.categories.edit', $c))->assertOk();
        $this->put(route('admin.categories.update', $c), array_replace($data, ['name' => 'Utensili nuovi', 'is_active' => 0]))->assertSessionHasNoErrors();
        $this->assertFalse($c->fresh()->is_active);
        $this->delete(route('admin.categories.destroy', $c))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('categories', ['id' => $c->id]);
    }

    public function test_referenced_categories_can_be_renamed_and_disabled_but_not_deleted(): void
    {
        $item = InventoryItem::factory()->create();
        $category = $item->categoryRecord;
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('admin.categories.update', $category), ['name' => 'Nome nuovo', 'slug' => 'nome-nuovo', 'is_active' => 0, 'sort_order' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Nome nuovo', $item->fresh()->categoryRecord->name);
        $this->assertSame('Edilizia', $item->fresh()->category);
        $this->delete(route('admin.categories.destroy', $category))->assertSessionHasErrors('category');
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'category_id' => $category->id]);
    }

    public function test_pending_proposal_also_protects_category_reference(): void
    {
        $category = Category::where('slug', 'colore')->sole();
        InventoryItem::factory()->published()->create(['proposed_data' => ['category_id' => $category->id, 'category' => 'Colore']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->delete(route('admin.categories.destroy', $category))->assertSessionHasErrors('category');
    }

    public function test_database_rejects_invalid_category_reference(): void
    {
        $item = InventoryItem::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('inventory_items')->where('id', $item->id)->update(['category_id' => 999999]);
    }

    public function test_inactive_categories_cannot_be_selected_for_new_products_or_reassigned(): void
    {
        $r = $this->retailer();
        $c = Category::where('slug', 'colore')->sole();
        $c->update(['is_active' => false]);
        $this->actingAs($r->user)->get(route('retailer.sell'))->assertOk()->assertDontSee('value="'.$c->id.'"', false);
        $this->post(route('retailer.stock.store'), $this->product(['category_id' => $c->id]))->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('inventory_items', 0);
        $item = InventoryItem::factory()->create(['retailer_id' => $r->id]);
        $this->put(route('retailer.stock.update', $item), $this->product(['category_id' => $c->id]))->assertSessionHasErrors('category_id');
        $item->categoryRecord->update(['is_active' => false]);
        $this->put(route('retailer.stock.update', $item), $this->product(['category_id' => $item->category_id]))->assertSessionHasNoErrors();
    }

    public function test_product_fields_and_shipping_cost_are_exact_and_iva_is_descriptive(): void
    {
        $r = $this->retailer();
        $this->actingAs($r->user)->post(route('retailer.stock.store'), $this->product(['shipping_cost' => '12,34', 'delivery_notes' => 'Solo lunedì', 'vat_treatment' => 'excluded']))->assertSessionHasNoErrors();
        $i = InventoryItem::sole();
        $this->assertSame(1234, $i->shipping_cost_cents);
        $this->assertSame(1250, $i->quantity_milliunits);
        $this->assertSame(1200, $i->zero_price_cents);
        $this->assertSame('kg', $i->unit);
        $this->assertSame('Solo lunedì', $i->delivery_notes);
        $this->assertSame('IVA esclusa', $i->vatLabel());
        $this->get(route('retailer.stock.show', $i))->assertOk()->assertSee('IVA esclusa')->assertSee('12,34');
        $this->put(route('retailer.stock.update', $i), $this->product(['shipping_cost' => '0', 'vat_treatment' => null]))->assertSessionHasNoErrors();
        $this->assertSame(0, $i->fresh()->shipping_cost_cents);
        $this->assertSame('Trattamento IVA da definire', $i->fresh()->vatLabel());
    }

    public function test_shipping_cost_rejects_negative_precision_and_float_inputs(): void
    {
        $r = $this->retailer();
        foreach (['-1.00', '1.234', '1e2'] as $cost) {
            $this->actingAs($r->user)->post(route('retailer.stock.store'), $this->product(['shipping_cost' => $cost]))->assertSessionHasErrors('shipping_cost');
        }
        $this->expectException(ValidationException::class);
        app(SaveInventoryItem::class)->handle($r->user, $this->product(['shipping_cost' => 1.5]));
    }

    public function test_shipping_cost_constraint_and_cast_cannot_be_bypassed(): void
    {
        $i = InventoryItem::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('inventory_items')->where('id', $i->id)->update(['shipping_cost_cents' => -1]);
    }

    public function test_product_proposals_preserve_approved_delivery_and_vat_until_review(): void
    {
        $r = $this->retailer();
        $i = InventoryItem::factory()->published()->create(['retailer_id' => $r->id, 'shipping_cost_cents' => 100, 'vat_treatment' => 'included']);
        $this->actingAs($r->user)->put(route('retailer.stock.update', $i), $this->product(['shipping_cost' => '2.50', 'vat_treatment' => 'excluded']))->assertSessionHasNoErrors();
        $this->assertSame(100, $i->fresh()->shipping_cost_cents);
        $this->assertSame(250, $i->fresh()->proposed_data['shipping_cost_cents']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('admin.stock.review', $i), ['decision' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame(250, $i->fresh()->shipping_cost_cents);
        $this->assertSame('excluded', $i->fresh()->vat_treatment);
    }

    public function test_vat_treatments_can_be_extended_without_a_tax_calculation(): void
    {
        config(['marketplace.vat_treatments.custom' => 'Regime da concordare']);
        $r = $this->retailer();
        $this->actingAs($r->user)->post(route('retailer.stock.store'), $this->product(['vat_treatment' => 'custom']))->assertSessionHasNoErrors();
        $this->assertSame('Regime da concordare', InventoryItem::sole()->vatLabel());
        $this->assertSame(1200, InventoryItem::sole()->zero_price_cents);
    }

    public function test_addresses_are_owned_and_default_flags_are_unique_per_user(): void
    {
        $u = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $data = ['label' => 'Casa', 'recipient_name' => 'Ada Rossi', 'address_line_1' => 'Via Roma 1', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country_code' => 'IT', 'is_default_shipping' => true, 'is_default_billing' => true];
        $first = app(SaveCustomerAddress::class)->handle($u, $data);
        $second = app(SaveCustomerAddress::class)->handle($u, array_replace($data, ['label' => 'Ufficio', 'user_id' => $other->id]));
        $this->assertSame($u->id, $second->user_id);
        $this->assertFalse($first->fresh()->is_default_shipping);
        $this->assertFalse($first->fresh()->is_default_billing);
        $this->assertTrue($second->is_default_shipping);
        $this->assertFalse($other->can('update', $second));
        $this->expectException(AuthorizationException::class);
        app(SaveCustomerAddress::class)->handle($other, $data, $second);
    }

    public function test_address_defaults_have_database_uniqueness(): void
    {
        $u = User::factory()->create(['role' => 'retailer']);
        $data = ['label' => 'Casa', 'recipient_name' => 'Mario', 'address_line_1' => 'Via Roma', 'postal_code' => '00100', 'city' => 'Roma', 'province' => 'RM', 'country_code' => 'IT', 'is_default_shipping' => true];
        app(SaveCustomerAddress::class)->handle($u, $data);
        $this->expectException(QueryException::class);
        $u->customerAddresses()->create($data);
    }
}
