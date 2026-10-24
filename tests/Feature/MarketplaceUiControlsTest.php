<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Retailer;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class MarketplaceUiControlsTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public function test_header_omits_category_dropdown_while_category_pages_remain_accessible(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $active = Category::create(['name' => 'Nuova categoria', 'slug' => 'nuova-categoria', 'is_active' => true, 'sort_order' => 99]);
        Category::create(['name' => 'Categoria nascosta', 'slug' => 'categoria-nascosta', 'is_active' => false, 'sort_order' => 100]);

        foreach ([route('home'), route('marketplace.index'), route('marketplace.category', $active->slug)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $xpath = $this->document($html);
            $this->assertCount(0, $xpath->query('//header//nav[@aria-label="Categorie marketplace"]'));
            $this->assertCount(1, $xpath->query('//header//a[text()="Vai a Marketplace"]'));
        }
    }

    public function test_home_has_separate_single_row_review_slider_and_circular_categories(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $html = $this->get(route('home'))->assertOk()->assertSee('20 recensioni demo')->assertSee('4,9/5')->getContent();
        $xpath = $this->document($html);
        $this->assertCount(20, $xpath->query('//*[@id="reviews-track"]/article'));
        $track = $xpath->query('//*[@id="reviews-track"]')->item(0);
        $this->assertStringContainsString('reviews-slider-track', $track->getAttribute('class'));
        $this->assertStringNotContainsString('product-rail', $track->getAttribute('class'));
        $this->assertSame('0', $track->getAttribute('tabindex'));
        $this->assertCount(2, $xpath->query('//button[@aria-controls="reviews-track"]'));
        $this->assertCount(Category::where('is_active', true)->count(), $xpath->query('//*[contains(@class,"category-circles")]/a'));
    }

    public function test_each_layout_has_exactly_one_icon_only_toggle_outside_headers(): void
    {
        foreach ([route('home'), route('login'), route('register'), route('customer.register')] as $url) {
            $this->assertFloatingToggle($this->get($url)->assertOk()->getContent());
        }
        foreach (['customer', 'admin', 'retailer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            if ($role === 'retailer') {
                Retailer::factory()->approved()->create(['user_id' => $user->id]);
            }
            $this->actingAs($user);
            $this->assertFloatingToggle($this->get(route($role.'.dashboard'))->assertOk()->getContent());
        }
    }

    private function assertFloatingToggle(string $html): void
    {
        $xpath = $this->document($html);
        $toggles = $xpath->query('//button[@data-theme-toggle]');
        $this->assertCount(1, $toggles);
        $this->assertCount(0, $xpath->query('//header//button[@data-theme-toggle] | //nav//button[@data-theme-toggle]'));
        $toggle = $toggles->item(0);
        $this->assertSame('', trim($toggle->textContent));
        $this->assertStringContainsString('theme-floating', $toggle->getAttribute('class'));
        $this->assertSame('Attiva tema scuro', $toggle->getAttribute('aria-label'));
        $this->assertCount(2, $xpath->query('//button[@data-theme-toggle]//svg'));
    }

    public function test_category_icons_use_slug_name_and_unknown_category_fallback(): void
    {
        foreach (['edilizia' => 'building', 'ferramenta' => 'wrench', 'idraulica' => 'droplet', 'colore' => 'paint', 'edilizia-2' => 'construction', 'edilizia-pesante' => 'construction', 'elettroutensili' => 'drill', 'categoria-futura' => 'box'] as $slug => $icon) {
            $html = Blade::render('<x-category-icon :category="$category" />', ['category' => (object) ['slug' => $slug, 'name' => 'Altro']]);
            $this->assertStringContainsString('data-category-icon="'.$icon.'"', $html);
        }
        $html = Blade::render('<x-category-icon :category="$category" />', ['category' => (object) ['slug' => 'slug-diverso', 'name' => 'Edilizia pesante']]);
        $this->assertStringContainsString('data-category-icon="construction"', $html);
    }
}
