<?php

namespace Tests\Feature;

use App\Models\AvailabilityRequest;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityFlowTest extends TestCase
{
    use RefreshDatabase;

    private Retailer $retailer;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->retailer = Retailer::factory()->approved()->create();
        $this->item = InventoryItem::factory()->published()->create(['retailer_id' => $this->retailer->id]);
    }

    private function data(array $extra = []): array
    {
        return array_replace(['customer_name' => 'Mario Rossi', 'customer_company' => 'Impresa Rossi', 'customer_email' => 'mario@example.test', 'customer_phone' => '123456', 'quantity' => '2,500', 'message' => 'Sono interessato', 'privacy_consent' => '1'], $extra);
    }

    public function test_public_form_and_request_preserve_consent_and_owner(): void
    {
        $this->get(route('availability.create', $this->item))->assertOk()->assertSee('Richiedi giacenza')->assertSee('name="_token"', false);
        $this->post(route('availability.store', $this->item), $this->data(['retailer_id' => 999, 'status' => 'closed', 'privacy_accepted_at' => '2000-01-01']))->assertSessionHasNoErrors()->assertRedirect(route('availability.create', $this->item));
        $record = AvailabilityRequest::sole();
        $this->assertSame($this->retailer->id, $record->retailer_id);
        $this->assertSame('new', $record->status->value);
        $this->assertSame('2.500', $record->quantity);
        $this->assertNotNull($record->privacy_accepted_at);
        $this->assertSame('availability-v1', $record->privacy_policy_version);
        $this->assertArrayNotHasKey('customer_email', $record->toArray());
    }

    public function test_privacy_and_required_fields_are_mandatory(): void
    {
        $this->post(route('availability.store', $this->item), $this->data(['customer_name' => '', 'customer_email' => 'bad', 'quantity' => '0', 'privacy_consent' => '0']))->assertSessionHasErrors(['customer_name', 'customer_email', 'quantity', 'privacy_consent']);
        $this->assertDatabaseCount('availability_requests', 0);
        $this->post(route('availability.store', $this->item), $this->data(['quantity' => '1.2345']))->assertSessionHasErrors('quantity');
    }

    public function test_honeypot_rejects_spam_and_rate_limit_is_global_per_ip(): void
    {
        $this->post(route('availability.store', $this->item), $this->data(['contact_website' => 'spam']))->assertSessionHasErrors('contact_website');
        $this->assertDatabaseCount('availability_requests', 0);
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('availability.store', $this->item), $this->data())->assertRedirect();
        }$other = InventoryItem::factory()->published()->create(['retailer_id' => $this->retailer->id]);
        $this->post(route('availability.store', $other), $this->data())->assertStatus(429);
        $this->assertDatabaseCount('availability_requests', 4);
    }

    public function test_public_requests_require_real_csrf_token(): void
    {
        $this->app['env'] = 'local';
        $this->post(route('availability.store', $this->item), $this->data())->assertStatus(419);
        $this->assertDatabaseCount('availability_requests', 0);
    }

    public function test_unpublished_and_suspended_products_are_not_public(): void
    {
        foreach (['draft', 'pending', 'rejected', 'archived'] as $state) {
            $this->item->update(['status' => $state]);
            $this->get(route('availability.create', $this->item))->assertNotFound();
            $this->post(route('availability.store', $this->item), $this->data())->assertNotFound();
        }$this->item->update(['status' => 'published']);
        $this->retailer->update(['status' => 'suspended']);
        $this->get(route('availability.create', $this->item))->assertNotFound();
    }

    public function test_pending_changes_use_approved_product_name(): void
    {
        $this->item->proposed_data = ['name' => 'SECRET PROPOSAL'];
        $this->item->status = 'change_pending';
        $this->item->save();
        $this->get(route('availability.create', $this->item))->assertOk()->assertSee($this->item->name)->assertDontSee('SECRET PROPOSAL');
        $this->post(route('availability.store', $this->item), $this->data())->assertSessionHasNoErrors();
    }

    public function test_input_is_plain_text_and_script_markup_is_not_rendered(): void
    {
        $this->post(route('availability.store', $this->item), $this->data(['customer_name' => '<b>Mario</b>', 'message' => '<script>alert(1)</script><b>Testo</b>', 'customer_email' => 'MARIO@example.test']))->assertSessionHasNoErrors();
        $record = AvailabilityRequest::sole();
        $this->assertSame('Mario', $record->customer_name);
        $this->assertSame('mario@example.test', $record->customer_email);
        $this->assertStringNotContainsString('<', $record->message);
        $this->actingAs($this->retailer->user)->get(route('retailer.requests'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_ownership_scopes_list_status_and_dashboard(): void
    {
        $own = AvailabilityRequest::factory()->create(['retailer_id' => $this->retailer->id, 'inventory_item_id' => $this->item->id, 'customer_name' => 'Cliente proprio']);
        $other = AvailabilityRequest::factory()->create(['customer_name' => 'Cliente segreto']);
        $this->actingAs($this->retailer->user)->get(route('retailer.requests'))->assertOk()->assertSee('Cliente proprio')->assertDontSee('Cliente segreto');
        $this->patch(route('retailer.requests.update', $other), ['status' => 'closed'])->assertForbidden();
        $this->get(route('retailer.dashboard'))->assertOk()->assertViewHas('newRequestCount', 1)->assertSee('Richieste nuove')->assertSee('Cliente proprio')->assertDontSee('Cliente segreto');
        $this->assertFalse($this->retailer->user->can('update', $other));
    }

    public function test_retailer_can_change_only_status_with_timestamps(): void
    {
        $record = AvailabilityRequest::factory()->create(['retailer_id' => $this->retailer->id, 'inventory_item_id' => $this->item->id]);
        $name = $record->customer_name;
        $this->actingAs($this->retailer->user)->patch(route('retailer.requests.update', $record), ['status' => 'contacted', 'customer_name' => 'Changed', 'retailer_id' => 999, 'quantity' => '999'])->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertSame($name, $record->customer_name);
        $this->assertSame('1.000', $record->quantity);
        $this->assertNotNull($record->contacted_at);
        $this->patch(route('retailer.requests.update', $record), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertNotNull($record->fresh()->closed_at);
        $this->patch(route('retailer.requests.update', $record), ['status' => 'new'])->assertSessionHasNoErrors();
        $this->assertNull($record->fresh()->closed_at);
        $this->patch(route('retailer.requests.update', $record), ['status' => 'approved'])->assertSessionHasErrors('status');
    }

    public function test_guests_pending_retailers_and_admin_cannot_change_status(): void
    {
        $record = AvailabilityRequest::factory()->create(['retailer_id' => $this->retailer->id, 'inventory_item_id' => $this->item->id]);
        $this->patch(route('retailer.requests.update', $record), ['status' => 'closed'])->assertRedirect(route('login'));
        $this->retailer->update(['status' => 'pending']);
        $this->actingAs($this->retailer->user)->patch(route('retailer.requests.update', $record), ['status' => 'closed'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('retailer.requests.update', $record), ['status' => 'closed'])->assertForbidden();
    }

    public function test_received_requests_are_paginated_and_contact_data_stay_private(): void
    {
        AvailabilityRequest::factory()->count(16)->create(['retailer_id' => $this->retailer->id, 'inventory_item_id' => $this->item->id]);
        $this->actingAs($this->retailer->user)->get(route('retailer.requests'))->assertOk()->assertViewHas('requests', fn ($rows) => $rows->total() === 16 && $rows->count() === 15);
        $this->get(route('availability.create', $this->item))->assertDontSee(AvailabilityRequest::first()->customer_email);
    }
}
