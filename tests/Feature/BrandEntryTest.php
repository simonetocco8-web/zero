<?php

namespace Tests\Feature;

use App\Services\MarketplaceCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BrandEntryTest extends TestCase
{
    public function test_entry_page_has_one_login_and_one_registration_action_without_duplicates(): void
    {
        $response = $this->get('/')->assertOk()
            ->assertSee('Vendi l’invenduto')
            ->assertSee('Trasforma le giacenze in liquidità')
            ->assertSee('Scambia prodotti')
            ->assertSee('Marketplace riservato agli utenti autenticati');

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        foreach (['login', 'register'] as $route) {
            $href = route($route);
            $this->assertSame(1, $xpath->query('//a[@href="'.$href.'"]')->length);
            $this->assertSame(1, $xpath->query('//main//a[@href="'.$href.'"]')->length);
            $this->assertSame(0, $xpath->query('//header//a[@href="'.$href.'"] | //footer//a[@href="'.$href.'"]')->length);
        }
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(1, $xpath->query('//footer//a[@href="'.route('privacy').'"]')->length);
        $this->assertSame(0, $xpath->query('//a[@href="'.route('marketplace.index').'"]')->length);
    }

    public function test_other_guest_pages_keep_access_and_customer_registration_links(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('href="'.route('register').'"', false);
        $this->mock(MarketplaceCatalog::class, fn ($mock) => $mock->shouldNotReceive('categories'));
        $html = Blade::render('<x-marketplace-layout title="Informazioni">Contenuto</x-marketplace-layout>');
        $this->assertStringContainsString('href="'.route('login').'"', $html);
        $this->assertStringContainsString('href="'.route('customer.register').'"', $html);
    }
}
