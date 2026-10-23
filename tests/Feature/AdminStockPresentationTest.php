<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class AdminStockPresentationTest extends TestCase
{
    use AdditiveRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public static function statuses(): array
    {
        return [['draft', 'Bozza'], ['pending', 'In verifica'], ['published', 'Pubblicato'], ['change_pending', 'Modifica in verifica'], ['rejected', 'Rifiutato'], ['archived', 'Archiviato']];
    }

    #[DataProvider('statuses')]
    public function test_table_status_is_an_icon_with_label_and_tooltip_and_modal_keeps_text(string $status, string $label): void
    {
        InventoryItem::factory()->create(['status' => $status, 'published_at' => in_array($status, ['published', 'change_pending']) ? now() : null]);
        $response = $this->get(route('admin.stock'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $icon = $xpath->query('//table//span[@role="img" and @aria-label="'.$label.'"]')->item(0);
        $this->assertNotNull($icon);
        $this->assertSame($label, $icon->getAttribute('title'));
        $this->assertSame('', trim($icon->textContent));
        $this->assertSame(1, $xpath->query('//table/tbody/tr')->length);
        $this->assertStringContainsString($label, $xpath->query('//dialog[starts-with(@aria-labelledby, "stock-detail-")]')->item(0)->textContent);
    }

    private function photo(InventoryItem $item, int $position, string $contents): string
    {
        $path = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $contents);
        $item->images()->create(['position' => $position, 'disk' => 'local', 'path' => $path]);

        return $path;
    }

    public function test_modal_uses_first_persisted_photo_by_actual_position_and_secure_route(): void
    {
        $item = InventoryItem::factory()->create();
        $other = $this->photo($item, 9, 'later-photo');
        $first = $this->photo($item, 4, 'first-photo');
        $response = $this->get(route('admin.stock'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $image = $xpath->query('//dialog//img')->item(0);
        $this->assertSame(route('inventory.image', [$item, 4, 'proposed' => 0]), $image->getAttribute('src'));
        $this->assertSame('lazy', $image->getAttribute('loading'));
        $this->assertStringContainsString($item->name, $image->getAttribute('alt'));
        $this->assertSame(0, $xpath->query('//table//img')->length);
        $response->assertDontSee($first)->assertDontSee($other)->assertDontSee('storage/');
        $this->get(route('inventory.image', [$item, 4]))->assertOk()->assertStreamedContent('first-photo');
        $this->actingAs(Retailer::factory()->create()->user)->get(route('inventory.image', [$item, 4]))->assertForbidden();
    }

    public function test_proposed_image_is_used_only_when_proposed_data_and_photos_exist(): void
    {
        $item = InventoryItem::factory()->create(['status' => 'change_pending', 'published_at' => now()]);
        $this->photo($item, 6, 'persisted-photo');
        $path = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'proposed-photo');
        $data = $item->getAttributes();
        $data['name'] = 'Nome proposto';
        $item->forceFill(['proposed_data' => $data, 'proposed_images' => [['disk' => 'local', 'path' => $path]]])->save();
        $response = $this->get(route('admin.stock'))->assertOk();
        $image = $this->xpath($response->getContent())->query('//dialog//img')->item(0);
        $this->assertSame(route('inventory.image', [$item, 0, 'proposed' => 1]), $image->getAttribute('src'));
        $this->assertStringContainsString('Nome proposto', $image->getAttribute('alt'));
        $response->assertDontSee($path);
        $this->get(route('inventory.image', [$item, 0, 'proposed' => 1]))->assertOk()->assertStreamedContent('proposed-photo');
        $item->forceFill(['proposed_images' => []])->save();
        $response = $this->get(route('admin.stock'))->assertOk();
        $this->assertSame(route('inventory.image', [$item, 6, 'proposed' => 0]), $this->xpath($response->getContent())->query('//dialog//img')->item(0)->getAttribute('src'));
    }

    public function test_modal_displays_placeholder_when_no_photo_exists(): void
    {
        InventoryItem::factory()->create();
        $response = $this->get(route('admin.stock'))->assertOk()->assertSee('Nessuna immagine disponibile');
        $this->assertSame(0, $this->xpath($response->getContent())->query('//dialog//img')->length);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));

        return new \DOMXPath($dom);
    }
}
