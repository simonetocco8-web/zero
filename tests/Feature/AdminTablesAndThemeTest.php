<?php

namespace Tests\Feature;

use App\Actions\RecordAdministrativeAction;
use App\Enums\AdministrativeAction;
use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class AdminTablesAndThemeTest extends TestCase
{
    use AdditiveRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_recent_audit_is_a_semantic_table_with_translated_actions_and_ten_records(): void
    {
        $retailer = Retailer::factory()->create();
        foreach (range(1, 12) as $index) {
            app(RecordAdministrativeAction::class)->handle(auth()->user(), AdministrativeAction::RetailerApproved, $retailer);
        }
        $response = $this->get(route('admin.dashboard'))->assertOk();
        $response->assertViewHas('logs', fn ($logs) => $logs->count() === 10);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(10, $xpath->query('//table/tbody/tr')->length);
        foreach (['Azione', 'Operatore', 'Risorsa', 'ID', 'Data / Ora'] as $label) {
            $this->assertSame(1, $xpath->query('//table/thead/tr/th[@scope="col" and text()="'.$label.'"]')->length);
        }
        $response->assertSee('Rivenditore approvato')->assertSee('overflow-x-auto');
    }

    public function test_stock_rows_exclude_details_and_photos_and_modal_uses_proposed_values(): void
    {
        $item = InventoryItem::factory()->create(['status' => 'change_pending', 'published_at' => now(), 'description' => 'Descrizione pubblicata']);
        $data = $item->getAttributes();
        $data['name'] = 'Nome proposto';
        $data['description'] = str_repeat('Descrizione proposta completa. ', 30);
        $data['delivery_notes'] = 'Consegna proposta';
        $data['sku'] = 'SKU-PROPOSTO';
        $item->forceFill(['proposed_data' => $data, 'proposed_images' => [['disk' => 'private', 'path' => 'private/never-expose.jpg']]])->save();
        $response = $this->get(route('admin.stock'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(10, $xpath->query('//table/thead/tr/th[@scope="col"]')->length);
        $this->assertSame(0, $xpath->query('//table//img')->length);
        $row = $xpath->query('//table/tbody/tr')->item(0)->textContent;
        $this->assertStringContainsString('Nome proposto', $row);
        $this->assertStringNotContainsString('Descrizione', $row);
        $this->assertStringNotContainsString('Gestisci foto', $row);
        $this->assertStringNotContainsString('Elimina articolo', $row);
        foreach (['APPROVA E PUBBLICA', 'RIFIUTA'] as $label) {
            $button = $xpath->query('//table//button[@aria-label="'.$label.'"]')->item(0);
            $this->assertNotNull($button);
            $this->assertSame('', trim($button->textContent));
            $this->assertSame(1, $xpath->query('.//svg', $button)->length);
        }
        $this->assertSame(1, $xpath->query('//table//button[@aria-label="Dettaglio giacenza #'.$item->id.'"]')->length);
        $modal = $xpath->query('//dialog[@aria-labelledby="stock-detail-'.$item->id.'-title"]')->item(0)->textContent;
        foreach ([$data['description'], 'Consegna proposta', 'SKU-PROPOSTO', 'Foto presenti: 1', 'Gestisci foto', 'Elimina articolo'] as $value) {
            $this->assertStringContainsString($value, $modal);
        }
        $response->assertDontSee('private/never-expose.jpg')->assertDontSee('Descrizione pubblicata');
        $this->assertSame(1, $xpath->query('//dialog//form/input[@name="_method" and @value="DELETE"]')->length);
    }

    public function test_stock_photo_and_delete_actions_respect_each_policy(): void
    {
        InventoryItem::factory()->create();
        $permissions = ['managePhotos' => false, 'delete' => false];
        Gate::before(function ($user, $ability) use (&$permissions) {
            return $permissions[$ability] ?? null;
        });
        $this->get(route('admin.stock'))->assertOk()->assertDontSee('Gestisci foto')->assertDontSee('Elimina articolo');
        $permissions['managePhotos'] = true;
        $this->get(route('admin.stock'))->assertOk()->assertSee('Gestisci foto')->assertDontSee('Elimina articolo');
        $permissions['managePhotos'] = false;
        $permissions['delete'] = true;
        $this->get(route('admin.stock'))->assertOk()->assertDontSee('Gestisci foto')->assertSee('Elimina articolo');
    }

    public function test_brand_renders_both_variants_with_original_preserved(): void
    {
        $response = $this->get(route('admin.dashboard'))->assertOk();
        $response->assertSee('brand-logo-light')->assertSee('brand-logo-dark')->assertSee('zero-magazzino-logo-light.png')->assertSee('zero-magazzino-logo-dark.png')->assertSee('alt="ZeroMagazzino.it"', false);
        foreach (['zero-magazzino-logo.png', 'zero-magazzino-logo-light.png', 'zero-magazzino-logo-dark.png'] as $file) {
            $this->assertFileExists(public_path('brand/'.$file));
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        return new \DOMXPath($dom);
    }
}
