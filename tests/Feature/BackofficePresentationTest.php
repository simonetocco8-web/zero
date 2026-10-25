<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\AvailabilityRequest;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use App\Support\DisplayMoney;
use Database\Seeders\PlanSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class BackofficePresentationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }

    private function retailer(): Retailer
    {
        $this->seed(PlanSeeder::class);
        $retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $retailer->id, 'plan_id' => Plan::where('code', 'pro')->sole()->id]);

        return $retailer;
    }

    public function test_sales_table_contains_only_own_vendor_total_and_customer_snapshot(): void
    {
        $seller = $this->retailer();
        $other = $this->retailer();
        $buyer = User::factory()->create(['role' => 'customer', 'name' => 'Cliente della vendita']);
        foreach ([$seller, $other] as $retailer) {
            $item = InventoryItem::factory()->published()->create(['retailer_id' => $retailer->id, 'pickup_available' => true, 'vat_treatment' => 'not_applicable']);
            app(MarketplaceCart::class)->save($buyer, $item, '1', 'pickup');
        }
        $quote = app(MarketplaceQuote::class);
        $order = app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], null, $quote->hash($quote->build($buyer, [])));
        $ours = $order->vendorOrders()->where('retailer_id', $seller->id)->sole();
        $theirs = $order->vendorOrders()->where('retailer_id', $other->id)->sole();
        $response = $this->actingAs($seller->user)->get(route('retailer.sales.index'))->assertOk()->assertSee('Cliente della vendita')->assertSee(route('retailer.sales.show', $ours))->assertDontSee(route('retailer.sales.show', $theirs));
        $xpath = $this->xpath($response->getContent());
        $headings = $xpath->query('//main//thead//th');
        $this->assertSame(['ID', 'Status', 'Cliente', 'Totale Ordine'], array_map(fn ($node) => $node->textContent, iterator_to_array($headings)));
        $this->assertSame(1, $xpath->query('//main//tbody/tr')->length);
        $this->assertStringContainsString(DisplayMoney::euros($ours->total_cents), $xpath->query('//main//tbody')->item(0)->textContent);
        $this->assertStringNotContainsString(DisplayMoney::euros($order->total_cents), $xpath->query('//main//tbody')->item(0)->textContent);
    }

    public static function states(): array
    {
        return [['new', 'In lavorazione'], ['contacted', 'Risposta'], ['closed', 'Chiusa']];
    }

    #[DataProvider('states')]
    public function test_request_status_icon_buttons_keep_existing_values(string $status, string $label): void
    {
        $retailer = $this->retailer();
        $item = InventoryItem::factory()->create(['retailer_id' => $retailer->id]);
        $request = AvailabilityRequest::factory()->create(['inventory_item_id' => $item->id, 'retailer_id' => $retailer->id, 'status' => $status, 'contacted_at' => $status === 'contacted' ? now() : null, 'closed_at' => $status === 'closed' ? now() : null]);
        $response = $this->actingAs($retailer->user)->get(route('retailer.requests'))->assertOk()->assertSee($label)->assertDontSee('Salva stato');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//main//select[@name="status"]')->length);
        $this->assertSame(6, $xpath->query('//main//button[@name="status"]/*[local-name()="svg"]')->length);
        $this->assertSame(2, $xpath->query('//main//button[@value="'.$status.'" and @aria-pressed="true" and @aria-label="'.$label.'"]')->length);
        $this->patch(route('retailer.requests.update', $request), ['status' => $status])->assertSessionHasNoErrors();
        $this->assertSame($status, $request->fresh()->status->value);
    }

    public function test_purchase_and_exchange_actions_are_siblings_with_icons_for_eligible_buyer(): void
    {
        $seller = $this->retailer();
        $buyer = $this->retailer();
        $item = InventoryItem::factory()->published()->create(['retailer_id' => $seller->id, 'exchange_available' => true]);
        $response = $this->actingAs($buyer->user)->get(route('marketplace.show', $item))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(2, $xpath->query('//*[@data-product-actions]/a/*[local-name()="svg"]')->length);
        $response->assertSee('Acquista')->assertSee('Proponi uno scambio');
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get(route('marketplace.show', $item))->assertOk()->assertDontSee('Proponi uno scambio');
        $this->actingAs($seller->user)->get(route('marketplace.show', $item))->assertOk()->assertDontSee('> Acquista<', false)->assertDontSee('Proponi uno scambio');
    }

    public function test_retailer_submenus_expand_only_in_current_section_and_highlight_detail(): void
    {
        $retailer = $this->retailer();
        $this->actingAs($retailer->user)->get(route('retailer.dashboard'))->assertOk()->assertDontSee('Proposte ricevute')->assertDontSee('Richieste bonifico');
        $item = InventoryItem::factory()->create(['retailer_id' => $retailer->id]);
        $response = $this->get(route('retailer.stock.show', $item))->assertOk()->assertSee('Richieste bonifico')->assertDontSee('Proposte ricevute');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(2, $xpath->query('//a[@aria-current="page" and @href="'.route('retailer.stock').'"]')->length);
        $response = $this->get(route('retailer.exchanges.index', ['tab' => 'sent']))->assertOk()->assertSee('Proposte ricevute')->assertDontSee('Richieste bonifico');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(2, $xpath->query('//nav[@aria-label="Navigazione principale" or @aria-label="Sezioni riservate"]//a[@aria-current="page" and @href="'.route('retailer.exchanges.index', ['tab' => 'sent']).'"]')->length);
        $this->assertSame(0, $xpath->query('//nav[@aria-label="Navigazione principale" or @aria-label="Sezioni riservate"]//a[@aria-current="page" and @href="'.route('retailer.exchanges.index', ['tab' => 'received']).'"]')->length);
    }

    public function test_admin_submenus_follow_resource_and_nested_inventory_section(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('Stock reservations');
        $response = $this->get(route('admin.marketplace.index', ['resource' => 'orders']))->assertOk()->assertSee('Stock reservations');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(2, $xpath->query('//a[@aria-current="page" and @href="'.route('admin.marketplace.index', ['resource' => 'orders']).'"]')->length);
        $this->get(route('admin.stock'))->assertOk()->assertDontSee('Stock reservations')->assertSee('Giacenze');
    }
}
