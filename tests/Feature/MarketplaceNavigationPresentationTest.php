<?php

namespace Tests\Feature;

use App\Actions\CreateMarketplaceOrder;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MarketplaceCart;
use App\Services\MarketplaceQuote;
use Database\Seeders\PlanSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceNavigationPresentationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($doc);
    }

    private function product(array $data = []): InventoryItem
    {
        $this->seed(PlanSeeder::class);
        $seller = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $seller->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);

        return InventoryItem::factory()->published()->create(array_replace(['retailer_id' => $seller->id, 'quantity' => '20', 'pickup_available' => true, 'shipping_available' => true, 'shipping_cost_cents' => 1000, 'shipping_vat_treatment' => 'excluded', 'shipping_vat_rate_basis_points' => 2200, 'vat_treatment' => 'not_applicable'], $data));
    }

    private function order(User $buyer, array $products): Order
    {
        foreach ($products as $product) {
            app(MarketplaceCart::class)->save($buyer, $product, '1', 'pickup');
        }
        $quotes = app(MarketplaceQuote::class);

        return app(CreateMarketplaceOrder::class)->handle($buyer, (string) Str::uuid(), [], null, $quotes->hash($quotes->build($buyer, [])));
    }

    public function test_header_counts_own_distinct_products_and_parent_orders_only(): void
    {
        $buyer = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $a = $this->product();
        $b = $this->product();
        $this->order($buyer, [$a, $b]);
        $this->order($other, [$a]);
        app(MarketplaceCart::class)->save($buyer, $a, '3', 'pickup');
        app(MarketplaceCart::class)->save($buyer, $b, '2', 'pickup');
        app(MarketplaceCart::class)->save($other, $a, '1', 'pickup');
        $xpath = $this->xpath($this->actingAs($buyer)->get(route('marketplace.index'))->assertOk()->getContent());
        $this->assertSame('2', $xpath->query('//header//*[@data-cart-count]')->item(0)->textContent);
        $this->assertSame('1', $xpath->query('//header//*[@data-order-count]')->item(0)->textContent);
        foreach (['Carrello, 2 prodotti', 'Ordini, 1 ordine', 'La tua area'] as $label) {
            $this->assertSame(1, $xpath->query('//header//a[@aria-label="'.$label.'"]/svg')->length);
        }
        $this->assertSame(0, $xpath->query('//header//nav[@aria-label="Categorie marketplace"]')->length);
    }

    public function test_empty_header_has_no_zero_badges_or_cart_creation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $html = $this->get(route('marketplace.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(0, $xpath->query('//header//*[@data-cart-count or @data-order-count]')->length);
        foreach (['Carrello', 'Ordini', 'La tua area'] as $label) {
            $link = $xpath->query('//header//a[@aria-label="'.$label.'"]')->item(0);
            $this->assertNotNull($link);
            $this->assertSame('', trim($link->textContent));
        }
        $this->assertDatabaseCount('carts', 0);
    }

    public function test_delivery_option_shows_gross_price_free_shipping_and_undetermined_cost(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        foreach ([['shipping_cost_cents' => 1000], ['shipping_cost_cents' => 0], ['shipping_cost_cents' => null], ['shipping_cost_cents' => 1000, 'shipping_vat_rate_basis_points' => null]] as $i => $data) {
            $product = $this->product($data);
            $xpath = $this->xpath($this->get(route('marketplace.purchase', $product))->assertOk()->getContent());
            $option = $xpath->query('//select[@name="delivery_mode"]/option[@value="shipping"]')->item(0);
            $this->assertStringContainsString(['12,20', 'Gratuita', 'Costo da definire', 'IVA da definire'][$i], $option->textContent);
        }
    }

    public function test_orders_table_preserves_vendor_snapshots_ownership_and_local_date_format(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(10, 30));
        $buyer = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $a = $this->product();
        $b = $this->product();
        $order = $this->order($buyer, [$a, $b]);
        $hidden = $this->order($other, [$a]);
        $sellerName = $a->retailer->company_name;
        $a->retailer->update(['company_name' => 'Nome nuovo non storico']);
        $response = $this->actingAs($buyer)->get(route('orders.index'))->assertOk()->assertSee($sellerName)->assertDontSee('Nome nuovo non storico');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(6, $xpath->query('//main//table/thead/tr/th')->length);
        $this->assertSame(1, $xpath->query('//main//table/tbody/tr')->length);
        $this->assertSame(1, $xpath->query('//table//a[@href="'.route('orders.show', $order).'"]/svg')->length);
        $this->assertSame(0, $xpath->query('//table//a[@href="'.route('orders.show', $hidden).'"]')->length);
        $this->assertSame('24/10/2026', $xpath->query('//table//time')->item(0)->textContent);
        $this->get(route('orders.show', $order))->assertOk()->assertSee('Prenotazione fino al 24/10/2026.')->assertDontSee('2026-10-24 11:00:00');
    }

    public function test_stock_actions_and_status_use_icons_with_accessible_labels(): void
    {
        $product = $this->product();
        $xpath = $this->xpath($this->actingAs($product->retailer->user)->get(route('retailer.stock'))->assertOk()->getContent());
        foreach (['Visualizza', 'Modifica', 'Gestisci foto', 'Elimina articolo'] as $label) {
            $controls = $xpath->query('//main//a[@aria-label="'.$label.'"] | //main//button[@aria-label="'.$label.'"]');
            $this->assertSame(2, $controls->length);
            foreach ($controls as $control) {
                $this->assertSame('', trim($control->textContent));
                $this->assertSame(1, $xpath->query('.//svg', $control)->length);
            }
        }
        $this->assertSame(2, $xpath->query('//main//span[@role="img" and @aria-label="Pubblicato"]')->length);
        $this->assertSame(2, $xpath->query('//main//dialog//input[@name="_method" and @value="DELETE"]')->length);
        $sales = $this->xpath($this->get(route('retailer.sales.overview'))->assertOk()->getContent());
        $this->assertSame(7, $sales->query('//main//h2/preceding-sibling::div/svg')->length);
    }
}
