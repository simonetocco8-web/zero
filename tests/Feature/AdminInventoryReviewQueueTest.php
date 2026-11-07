<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Actions\SaveInventoryItem;
use App\Enums\InventoryStatus;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\StorePublication;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Services\Shipping\ShippingParcelBuilder;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class AdminInventoryReviewQueueTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private User $admin;

    private Retailer $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->seller = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $this->seller->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);
    }

    private function product(array $data = []): InventoryItem
    {
        return InventoryItem::factory()->published()->create($data + ['retailer_id' => $this->seller->id, 'quantity' => '30', 'zero_price_cents' => 1000, 'unit' => 'piece', 'vat_treatment' => 'not_applicable', 'shipping_available' => true, 'exchange_available' => true, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150, 'weight_g' => 1000, 'packing_rule' => 'pieces_capacity', 'units_per_parcel_milliunits' => 2000, 'packaging_type' => 'box']);
    }

    private function proposal(InventoryItem $item, array $changes = []): InventoryItem
    {
        return app(SaveInventoryItem::class)->handle($this->seller->user, array_replace(['name' => $item->name, 'category_id' => $item->category_id, 'unit' => 'piece', 'quantity' => '30', 'zero_price' => '10', 'condition' => 'new', 'province' => 'RM', 'description' => 'Nuova descrizione proposta', 'pickup_available' => true, 'shipping_available' => true, 'exchange_available' => true, 'vat_treatment' => 'not_applicable', 'length' => '40', 'width' => '25', 'height' => '20', 'weight' => '2,5', 'dimension_unit' => 'cm', 'weight_unit' => 'kg', 'units_per_parcel' => '4', 'packing_rule' => 'whole_parcels', 'packaging_type' => 'crate', 'intent' => 'pending'], $changes), [], $item);
    }

    private function queueUrl(array $filters = []): string
    {
        return route('admin.stock', $filters + ['status' => InventoryStatus::REVIEW_FILTER]);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($dom);
    }

    public function test_dashboard_navigation_and_queue_share_both_review_states_and_exclude_deleted_items(): void
    {
        $this->product(['status' => 'pending', 'published_at' => null]);
        $this->proposal($this->product());
        $this->product();
        $deleted = $this->product(['status' => 'pending', 'published_at' => null]);
        $deleted->delete();
        $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertViewHas('counts', fn ($counts) => $counts['Giacenze in verifica'] === 2);
        $xpath = $this->xpath($dashboard->getContent());
        $this->assertSame(1, $xpath->query('//a[@aria-label="2 Giacenze in verifica" and @href="'.$this->queueUrl().'"]')->length);
        $dashboard->assertSee('In verifica (2)')->assertSee('Giacenze');
        $this->get($this->queueUrl())->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 2 && $items->pluck('status')->map->value->sort()->values()->all() === ['change_pending', 'pending']);
        $navigation = collect(config('navigation.admin'));
        $this->assertEmpty($navigation->firstWhere('route', 'admin.retailers')['children'] ?? []);
        $this->assertSame('Giacenze', $navigation->firstWhere('route', 'admin.stock')['label']);
        $this->get(route('admin.settings'))->assertOk()->assertSee('In verifica (2)');
    }

    public function test_review_priority_submitted_date_and_id_are_stable_with_filters_and_pagination(): void
    {
        $published = $this->product(['created_at' => now()->addDay(), 'submitted_at' => now()->addDay()]);
        $old = $this->product(['status' => 'pending', 'published_at' => null, 'submitted_at' => '2026-11-01 09:00:00']);
        $sameDateFirst = $this->product(['status' => 'change_pending', 'submitted_at' => '2026-11-02 09:00:00']);
        $sameDateLast = $this->product(['status' => 'pending', 'published_at' => null, 'submitted_at' => '2026-11-02 09:00:00']);
        $this->actingAs($this->admin)->get(route('admin.stock'))->assertOk()->assertViewHas('items', fn ($items) => $items->pluck('id')->all() === [$sameDateLast->id, $sameDateFirst->id, $old->id, $published->id]);
        $this->get($this->queueUrl(['from' => '2026-11-02', 'to' => '2026-11-02', 'company' => $this->seller->company_name]))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 2);
        $this->get(route('admin.stock', ['status' => 'pending']))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 2);
        $this->get(route('admin.stock', ['status' => 'change_pending']))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1);
        $this->get(route('admin.retailers', ['status' => InventoryStatus::REVIEW_FILTER]))->assertSessionHasErrors('status');
        InventoryItem::factory()->count(16)->create(['retailer_id' => $this->seller->id, 'status' => 'pending', 'submitted_at' => '2026-11-03 09:00:00']);
        $first = $this->get($this->queueUrl(['company' => $this->seller->company_name]))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 19 && $items->count() === 15);
        $this->assertStringContainsString('status=awaiting_review', $first->viewData('items')->nextPageUrl());
        $page2 = $this->get($this->queueUrl(['company' => $this->seller->company_name, 'page' => 2]))->assertOk()->viewData('items');
        $this->assertCount(4, $page2);
        $this->assertEmpty(array_intersect($first->viewData('items')->pluck('id')->all(), $page2->pluck('id')->all()));
    }

    public function test_comparison_shows_exact_before_after_packaging_prices_and_quantity_without_writing(): void
    {
        $item = $this->product(['description' => 'Descrizione pubblicata']);
        $this->proposal($item, ['quantity' => '25', 'zero_price' => '12', 'shipping_available' => false]);
        $before = $item->fresh()->getRawOriginal();
        $response = $this->actingAs($this->admin)->get($this->queueUrl())->assertOk();
        $xpath = $this->xpath($response->getContent());
        foreach (['length_mm' => ['30 cm', '40 cm'], 'width_mm' => ['20 cm', '25 cm'], 'height_mm' => ['15 cm', '20 cm'], 'weight_g' => ['1 kg', '2,5 kg'], 'packaging_type' => ['box', 'crate'], 'units_per_parcel_milliunits' => ['2 piece', '4 piece'], 'shipping_available' => ['Sì', 'No'], 'quantity_milliunits' => ['30 piece', '25 piece']] as $key => $values) {
            $row = $xpath->query('//*[@data-review-field="'.$key.'" and @data-changed="true"]')->item(0);
            $this->assertNotNull($row);
            $this->assertSame($values[0], $xpath->query('.//*[@data-before]', $row)->item(0)->textContent);
            $this->assertSame($values[1], $xpath->query('.//*[@data-after]', $row)->item(0)->textContent);
            $this->assertStringContainsString('Modificato', $row->textContent);
        }
        $response->assertSee('Descrizione pubblicata')->assertSee('Nuova descrizione proposta')->assertSee('Pezzi: capacità massima per collo')->assertSee('Solo multipli esatti di un collo');
        $this->assertSame($before, $item->fresh()->getRawOriginal());
        $this->assertSame(300, $item->fresh()->length_mm);
        Http::assertNothingSent();
    }

    public function test_approving_new_inventory_or_published_changes_applies_dimensions_and_reduces_counter(): void
    {
        $new = $this->product(['status' => 'pending', 'published_at' => null]);
        $changed = $this->proposal($this->product(), ['zero_price' => '12', 'quantity' => '25']);
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $new), ['decision' => 'published'])->assertSessionHasNoErrors()->assertRedirect($this->queueUrl());
        $this->assertSame('published', $new->fresh()->status->value);
        $this->assertSame(300, $new->fresh()->length_mm);
        $this->get(route('admin.dashboard'))->assertViewHas('counts', fn ($counts) => $counts['Giacenze in verifica'] === 1);
        $this->patch(route('admin.stock.review', $changed), ['decision' => 'published'])->assertSessionHasNoErrors();
        $changed->refresh();
        $this->assertSame([400, 250, 200, 2500, 4000], [$changed->length_mm, $changed->width_mm, $changed->height_mm, $changed->weight_g, $changed->units_per_parcel_milliunits]);
        $this->assertSame(1200, $changed->zero_price_cents);
        $this->assertSame(25000, $changed->quantity_milliunits);
        $this->assertNull($changed->proposed_data);
        $this->get(route('admin.dashboard'))->assertViewHas('counts', fn ($counts) => $counts['Giacenze in verifica'] === 0);
        $this->get($this->queueUrl())->assertViewHas('items', fn ($items) => $items->total() === 0);
    }

    public function test_rejection_requires_reason_and_keeps_published_values_and_photographs(): void
    {
        $item = $this->product();
        $path = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'approved');
        $item->images()->create(['disk' => 'local', 'path' => $path, 'position' => 3]);
        $this->proposal($item);
        $proposed = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($proposed, 'proposed');
        $item->fresh()->forceFill(['proposed_images' => [['disk' => 'local', 'path' => $proposed]]])->save();
        $response = $this->actingAs($this->admin)->get($this->queueUrl())->assertOk()->assertSee('Fotografie modificate')->assertSee('Foto pubblicate')->assertSee('Foto proposte')->assertDontSee($path)->assertDontSee($proposed);
        $response->assertSee(route('inventory.image', [$item, 3, 'proposed' => 0]), false)->assertSee(route('inventory.image', [$item, 0, 'proposed' => 1]), false);
        $this->patch(route('admin.stock.review', $item), ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->assertSame('change_pending', $item->fresh()->status->value);
        $this->patch(route('admin.stock.review', $item), ['decision' => 'rejected', 'reason' => 'Correggere imballaggio'])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('published', $item->status->value);
        $this->assertSame(300, $item->length_mm);
        $this->assertSame(1000, $item->weight_g);
        $this->assertSame($path, $item->images()->sole()->path);
        $this->assertSame('Correggere imballaggio', $item->rejection_reason);
        $this->assertNull($item->proposed_data);
        $this->get(route('admin.dashboard'))->assertViewHas('counts', fn ($counts) => $counts['Giacenze in verifica'] === 0);
        $this->assertDatabaseCount('store_publications', 0);
    }

    public function test_second_administrator_cannot_repeat_a_decision_on_an_already_reviewed_request(): void
    {
        $item = $this->product(['status' => 'pending', 'published_at' => null]);
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasNoErrors();
        $publicationIds = StorePublication::pluck('id')->all();
        $reviewedBy = $item->fresh()->reviewed_by;
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch(route('admin.stock.review', $item), ['decision' => 'rejected', 'reason' => 'Vista non aggiornata'])->assertSessionHasErrors('decision');
        $this->assertSame('published', $item->fresh()->status->value);
        $this->assertSame($reviewedBy, $item->fresh()->reviewed_by);
        $this->assertSame($publicationIds, StorePublication::pluck('id')->all());
    }

    public function test_only_admins_can_see_queue_compare_photos_and_review(): void
    {
        $item = $this->proposal($this->product());
        $path = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'test');
        $item->forceFill(['proposed_images' => [['disk' => 'local', 'path' => $path]]])->save();
        foreach ([$this->seller->user, User::factory()->create(['role' => 'customer'])] as $user) {
            $this->actingAs($user)->get($this->queueUrl())->assertForbidden();
            $this->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertForbidden();
        }
        $this->get(route('inventory.image', [$item, 0, 'proposed' => 1]))->assertForbidden();
        $this->assertSame('change_pending', $item->fresh()->status->value);
    }

    public function test_review_does_not_rewrite_orders_or_reservations_and_calculator_uses_approved_packaging(): void
    {
        $item = $this->product();
        $buyer = User::factory()->create(['role' => 'customer']);
        app(MarketplaceCart::class)->save($buyer, $item, '1', 'pickup');
        $quotes = app(MarketplaceQuote::class);
        $order = app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($buyer, [])));
        $reservation = $item->stockReservations()->sole();
        $oldReservation = $reservation->getRawOriginal();
        $oldOrder = $order->getRawOriginal();
        $this->proposal($item, ['packing_rule' => 'pieces_capacity', 'units_per_parcel' => '2']);
        $this->actingAs($this->admin)->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame($oldReservation, $reservation->fresh()->getRawOriginal());
        $this->assertSame($oldOrder, $order->fresh()->getRawOriginal());
        $this->assertSame(30000, $item->fresh()->quantity_milliunits);
        config(['shipping.enabled' => true]);
        $response = $this->actingAs($buyer)->get(route('marketplace.show', $item))->assertOk()->assertSee('400 × 250 × 200 mm')->assertSee('2500 g');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//*[@data-product-actions]//button[@data-shipping-trigger]')->length);
        $this->assertSame(1, $xpath->query('//dialog[@aria-label="Calcola Spedizione"]')->length);
        $this->assertSame(400, app(ShippingParcelBuilder::class)->build([['product' => $item->fresh(), 'quantity_milliunits' => 1000]])[0]['length_mm']);
        Http::assertNothingSent();
        $this->actingAs($this->seller->user)->get(route('marketplace.show', $item))->assertDontSee('data-shipping-trigger', false);
        $this->actingAs($this->admin)->get(route('marketplace.show', $item))->assertDontSee('data-shipping-trigger', false);
        config(['shipping.enabled' => false]);
        $this->actingAs($buyer)->get(route('marketplace.show', $item))->assertDontSee('data-shipping-trigger', false);
    }
}
