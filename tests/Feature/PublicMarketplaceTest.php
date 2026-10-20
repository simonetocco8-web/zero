<?php

namespace Tests\Feature;

use App\Contracts\StoreGatewayInterface;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class PublicMarketplaceTest extends TestCase
{
    use AdditiveRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'customer']));
    }

    private function product(array $data = []): InventoryItem
    {
        $r = Retailer::factory()->approved()->create();

        return InventoryItem::factory()->published()->create(array_replace(['retailer_id' => $r->id, 'name' => 'Piastrella approved', 'published_at' => now()->subDay(), 'description' => 'Descrizione approvata', 'brand' => 'Marca pubblica', 'sku' => 'SKU-PUBBLICO', 'ean' => '8021234567890'], $data));
    }

    private function pro(): Retailer
    {
        $this->seed(PlanSeeder::class);
        $r = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $r->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id, 'starts_at' => now()->subDay()]);

        return $r;
    }

    public function test_home_refresh_uses_visible_deals_exchange_and_all_categories(): void
    {
        $deal = $this->product(['list_price_cents' => 2000, 'zero_price_cents' => 1000, 'exchange_available' => true]);
        $ordinary = $this->product(['list_price_cents' => 1000, 'zero_price_cents' => 1000, 'exchange_available' => false]);
        $hidden = $this->product(['status' => 'draft', 'brand' => 'Hidden brand', 'list_price_cents' => 3000, 'zero_price_cents' => 1000, 'exchange_available' => true]);
        Category::create(['name' => 'Categoria extra', 'slug' => 'categoria-extra', 'is_active' => true, 'sort_order' => 99]);
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('Il magazzino')->assertSee('Occasioni da non perdere')
            ->assertSee('Categoria extra')->assertSee('20 recensioni demo')->assertSee('Demo')
            ->assertSee('data-theme="light"', false)->assertSee('data-theme-toggle', false)
            ->assertSee('/favicon-32x32.png', false)->assertSee('/apple-touch-icon.png', false)
            ->assertDontSee('Hidden brand');
        $response->assertViewHas('deals', fn ($items) => $items->modelKeys() === [$deal->id]);
        $response->assertViewHas('exchangeItems', fn ($items) => $items->modelKeys() === [$deal->id]);
        $response->assertViewHas('categories', fn ($items) => $items->count() === Category::where('is_active', true)->count());
        $this->assertCount(20, config('marketplace_reviews'));
        $this->assertSame(4.9, collect(config('marketplace_reviews'))->avg('rating'));
        $this->get(route('marketplace.show', $hidden))->assertNotFound();
    }

    public function test_home_catalog_and_category_use_only_local_approved_versions(): void
    {
        $i = $this->product(['status' => 'change_pending', 'proposed_data' => ['name' => 'Nome segreto proposta', 'brand' => 'Brand segreto', 'zero_price_cents' => 1, 'description' => 'Segreto proposta']]);
        config(['store.driver' => 'unknown', 'marketplace.featured_item_ids' => [$i->id]]);
        $this->app->bind(StoreGatewayInterface::class, fn () => throw new \RuntimeException('Gateway must not be resolved'));
        foreach ([route('home'), route('marketplace.index'), route('marketplace.category', $i->categoryRecord->slug), route('marketplace.show', $i)] as $url) {
            $this->get($url)->assertOk()->assertSee('Piastrella approved')->assertDontSee('Nome segreto proposta')->assertDontSee('Brand segreto')->assertDontSee('Segreto proposta');
        }
        $this->get(route('home'))->assertSee('In evidenza')->assertSee('Diventa rivenditore')->assertSee('La tua area');
        $this->get(route('marketplace.index', ['q' => 'Nome segreto proposta']))->assertDontSee('Piastrella approved');
    }

    public function test_all_hidden_states_and_unapproved_retailers_are_excluded_everywhere(): void
    {
        $visible = $this->product();
        foreach (['draft', 'pending', 'rejected', 'archived'] as $status) {
            $i = $this->product(['name' => 'Secret-'.$status, 'status' => $status]);
            $this->get(route('marketplace.show', $i))->assertNotFound();
        }
        foreach (['pending', 'rejected', 'suspended'] as $status) {
            $r = Retailer::factory()->create(['status' => $status, 'rejected_at' => $status === 'rejected' ? now() : null]);
            $i = $this->product(['name' => 'Secret-retailer-'.$status, 'retailer_id' => $r->id]);
            $this->get(route('marketplace.show', $i))->assertNotFound();
        }
        foreach ([route('home'), route('marketplace.index'), route('marketplace.category', $visible->categoryRecord->slug)] as $url) {
            $this->get($url)->assertOk()->assertSee($visible->name)->assertDontSee('Secret-');
        }
    }

    public function test_search_matches_name_brand_sku_and_ean_and_escapes_wildcards(): void
    {
        $i = $this->product();
        foreach (['Piastrella', 'Marca pubblica', 'SKU-PUB', '802123'] as $term) {
            $this->get(route('marketplace.index', ['q' => $term]))->assertOk()->assertSee($i->name);
        }
        $this->get(route('marketplace.index', ['q' => '%']))->assertOk()->assertDontSee($i->name);
        $this->get(route('marketplace.index', ['q' => '_']))->assertOk()->assertDontSee($i->name);
        $this->get(route('marketplace.index', ['q' => '0']))->assertOk()->assertSee($i->name);
    }

    public function test_price_category_brand_condition_province_and_delivery_filters(): void
    {
        $i = $this->product(['zero_price_cents' => 1234, 'condition' => 'old_stock', 'province' => 'Roma', 'shipping_available' => true, 'pickup_available' => true, 'exchange_available' => true]);
        $excluded = $this->product(['name' => 'Altro prodotto', 'category_id' => Category::where('slug', 'colore')->sole()->id, 'brand' => 'Altra marca', 'condition' => 'new', 'province' => 'Milano', 'zero_price_cents' => 5000, 'shipping_available' => false, 'pickup_available' => false, 'exchange_available' => false]);
        $filters = ['min_price' => '12,34', 'max_price' => '12.34', 'category_id' => $i->category_id, 'brand' => $i->brand, 'condition' => 'old_stock', 'province' => 'Roma', 'shipping_available' => 1, 'pickup_available' => 1, 'exchange_available' => 1];
        foreach ($filters as $key => $value) {
            $response = $this->get(route('marketplace.index', [$key => $value]))->assertOk()->assertSee($i->name);
            if ($key !== 'min_price') {
                $response->assertDontSee($excluded->name);
            }
        }
        $this->get(route('marketplace.index', $filters))->assertOk()->assertSee($i->name)->assertDontSee($excluded->name);
        $this->get(route('marketplace.index', ['min_price' => '12.35']))->assertDontSee($i->name);
        $this->get(route('marketplace.category', $i->categoryRecord->slug))->assertSee($i->name)->assertDontSee($excluded->name);
    }

    public function test_ordering_and_pagination_are_server_side_and_stable(): void
    {
        $this->product(['name' => 'Vecchio economico', 'zero_price_cents' => 100, 'published_at' => now()->subDays(4)]);
        $this->product(['name' => 'Nuovo costoso', 'zero_price_cents' => 500, 'published_at' => now()->subDay()]);
        foreach (['price_asc' => ['Vecchio economico', 'Nuovo costoso'], 'price_desc' => ['Nuovo costoso', 'Vecchio economico'], 'newest' => ['Nuovo costoso', 'Vecchio economico'], 'oldest' => ['Vecchio economico', 'Nuovo costoso']] as $sort => $names) {
            $this->get(route('marketplace.index', ['sort' => $sort]))->assertSeeInOrder($names);
        }
        for ($n = 0; $n < 25; $n++) {
            $this->product(['name' => 'Paginated-'.$n]);
        }
        $response = $this->get(route('marketplace.index', ['sort' => 'price_asc', 'q' => 'Paginated-']));
        $response->assertOk();
        $this->assertSame(24, $response->viewData('items')->count());
        $this->assertSame(25, $response->viewData('items')->total());
        $this->assertStringContainsString('q=Paginated-', $response->viewData('items')->nextPageUrl());
        $this->assertSame(1, $this->get(route('marketplace.index', ['q' => 'Paginated-', 'page' => 2]))->viewData('items')->count());
    }

    public function test_invalid_filters_are_rejected_without_sql_injection_or_rounding(): void
    {
        $this->get(route('marketplace.index', ['min_price' => '12.345', 'sort' => 'name desc;drop table users', 'q' => ['bad']]))->assertSessionHasErrors(['min_price', 'sort', 'q']);
        $this->get(route('marketplace.index', ['min_price' => '20', 'max_price' => '10']))->assertSessionHasErrors('max_price');
        $this->withHeader('Referer', route('marketplace.index'))->get(route('marketplace.index', ['q' => ['malformed']]))->assertSessionHasErrors('q');
        $this->get(route('marketplace.index'))->assertOk();
        $this->get(route('marketplace.index', ['category_id' => 999999, 'page' => 0]))->assertSessionHasErrors(['category_id', 'page']);
    }

    public function test_product_details_seo_and_information_flow_do_not_expose_private_fields(): void
    {
        $i = $this->product(['shipping_available' => true, 'shipping_cost_cents' => 1234, 'delivery_notes' => 'Consegna dal lunedì', 'vat_treatment' => 'excluded', 'unit' => 'm2', 'quantity' => '1.250']);
        $i->retailer->update(['iban' => 'private-iban', 'contact_email' => 'private-retailer@example.com']);
        $this->get(route('marketplace.show', $i))->assertOk()->assertSee($i->name)->assertSee('Descrizione approvata')->assertSee('IVA esclusa')->assertSee('12,34')->assertSee('1,25 m2')->assertSee('Consegna dal lunedì')->assertSee('SKU-PUBBLICO')->assertSee('8021234567890')->assertSee('Acquista')->assertSee('Richiedi informazioni')->assertDontSee('private-iban')->assertDontSee('private-retailer@example.com')->assertSee('rel="canonical"', false)->assertSee('property="og:title"', false)->assertSee('name="description"', false);
        $url = route('marketplace.show', $i);
        $i->update(['name' => 'Nome modificato']);
        $this->assertSame($url, route('marketplace.show', $i));
        $this->get(route('availability.create', $i))->assertOk()->assertSee('Torna alla scheda prodotto')->assertSee('name="_token"', false);
    }

    public function test_public_images_only_serve_approved_references_and_suspend_immediately(): void
    {
        Storage::fake('local');
        $i = $this->product(['status' => 'change_pending']);
        $approved = 'inventory/'.Str::uuid().'.jpg';
        $proposal = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($approved, 'approved-image');
        Storage::disk('local')->put($proposal, 'secret-image');
        $i->images()->create(['disk' => 'local', 'path' => $approved, 'position' => 0]);
        $i->update(['proposed_images' => [['disk' => 'local', 'path' => $proposal]], 'proposed_data' => ['name' => 'proposed']]);
        $url = route('marketplace.image', [$i, 0]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent('approved-image');
        $this->get($url.'?proposed=1')->assertNotFound();
        $this->get(route('marketplace.image', [$i, 1]))->assertNotFound();
        $i->retailer->update(['status' => 'suspended']);
        $this->get($url)->assertNotFound();
        $this->assertFalse(file_exists(public_path('storage/inventory')));
    }

    public function test_draft_images_and_unsafe_paths_or_missing_files_are_never_public(): void
    {
        Storage::fake('local');
        $i = $this->product(['status' => 'pending']);
        $i->images()->create(['disk' => 'local', 'path' => 'inventory/'.Str::uuid().'.jpg', 'position' => 0]);
        $this->get(route('marketplace.image', [$i, 0]))->assertNotFound();
        $i->update(['status' => 'published']);
        $this->get(route('marketplace.image', [$i, 0]))->assertNotFound();
        $i->images()->first()->update(['path' => '../private/secret']);
        $this->get(route('marketplace.image', [$i, 0]))->assertNotFound();
        auth()->logout();
        $this->get(route('inventory.image', [$i, 0]))->assertRedirect(route('login'));
    }

    public function test_exchange_action_requires_both_eligible_retailers_and_never_self(): void
    {
        $seller = $this->pro();
        $buyer = $this->pro();
        $i = $this->product(['retailer_id' => $seller->id, 'exchange_available' => true]);
        $this->get(route('marketplace.show', $i))->assertDontSee('Proponi uno scambio');
        $this->actingAs($buyer->user->fresh())->get(route('marketplace.show', $i))->assertSee('Proponi uno scambio');
        $this->get(route('marketplace.exchange', $i))->assertOk()->assertSee('Proponi uno scambio');
        $this->actingAs($seller->user)->get(route('marketplace.show', $i))->assertDontSee('Proponi uno scambio');
        $this->get(route('marketplace.exchange', $i))->assertForbidden();
        foreach (['customer', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('marketplace.show', $i))->assertDontSee('Proponi uno scambio');
            $this->get(route('marketplace.exchange', $i))->assertForbidden();
        }
        $buyer->activeSubscription->update(['plan_id' => Plan::where('code', 'free')->sole()->id]);
        $this->actingAs($buyer->user->fresh())->get(route('marketplace.exchange', $i))->assertForbidden();
        $buyer->activeSubscription->update(['plan_id' => Plan::where('code', 'pro')->sole()->id]);
        $seller->activeSubscription->update(['ends_at' => now()->subHour()]);
        $this->actingAs($buyer->user->fresh())->get(route('marketplace.show', $i))->assertDontSee('Proponi uno scambio');
        $this->get(route('marketplace.exchange', $i))->assertForbidden();
    }

    public function test_purchase_entry_has_no_financial_effects_and_admin_is_excluded(): void
    {
        $i = $this->product();
        auth()->logout();
        $this->get(route('marketplace.purchase', $i))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get(route('marketplace.purchase', $i))->assertOk()->assertSee('Aggiungi al carrello');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('billing_checkouts', 0);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('marketplace.purchase', $i))->assertForbidden();
    }

    public function test_catalog_query_count_does_not_grow_with_more_products_and_facets_hide_private_brands(): void
    {
        $this->product();
        $this->product(['status' => 'pending', 'brand' => 'Brand privata', 'province' => 'Località privata']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('marketplace.index'))->assertOk()->assertDontSee('Brand privata')->assertDontSee('Località privata');
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($n = 0; $n < 20; $n++) {
            $this->product(['sku' => 'SKU-'.$n]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get(route('marketplace.index'))->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(9, $large);
        $this->assertSame($small, $large);
    }

    public function test_featured_hidden_products_and_pending_values_cannot_bypass_visibility(): void
    {
        $i = $this->product(['status' => 'pending', 'name' => 'Hidden featured']);
        config(['marketplace.featured_item_ids' => [$i->id]]);
        $this->get(route('home'))->assertOk()->assertDontSee('Hidden featured')->assertDontSee('In evidenza');
    }
}
