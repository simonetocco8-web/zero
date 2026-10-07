<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryFlowTest extends TestCase
{
    use RefreshDatabase;

    private Retailer $retailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->retailer = Retailer::factory()->approved()->create();
        Subscription::factory()->active()->create(['retailer_id' => $this->retailer->id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        $this->actingAs($this->retailer->user);
        Storage::fake('local');
    }

    private function data(array $extra = []): array
    {
        return array_replace(['name' => 'Piastrella', 'category' => 'Pavimenti', 'quantity' => '1', 'zero_price' => '100.00', 'list_price' => null, 'condition' => 'new', 'province' => 'Roma', 'description' => 'Piastrelle disponibili', 'pickup_available' => '1', 'intent' => 'pending'], $extra);
    }

    private function item(array $extra = []): InventoryItem
    {
        return InventoryItem::factory()->create(array_replace(['retailer_id' => $this->retailer->id, 'quantity' => '1', 'zero_price_cents' => 10000, 'currency' => 'EUR'], $extra));
    }

    public function test_create_and_draft_submission(): void
    {
        $this->post(route('retailer.stock.store'), $this->data(['intent' => 'draft']))->assertSessionHasNoErrors();
        $item = InventoryItem::sole();
        $this->assertSame('draft', $item->status->value);
        $this->put(route('retailer.stock.update', $item), $this->data())->assertSessionHasNoErrors();
        $this->assertSame('pending', $item->fresh()->status->value);
    }

    public function test_free_five_product_limit_and_archive_releases_slot(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->item();
        } $this->post(route('retailer.stock.store'), $this->data())->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('inventory_items', 5);
        $this->patch(route('retailer.stock.archive', InventoryItem::first()))->assertRedirect();
        $this->post(route('retailer.stock.store'), $this->data())->assertSessionHasNoErrors();
    }

    public function test_free_inventory_value_limit_including_fractional_quantities(): void
    {
        $this->item(['quantity' => '1', 'zero_price_cents' => 999999]);
        $this->post(route('retailer.stock.store'), $this->data(['quantity' => '0.001', 'zero_price' => '10.00']))->assertSessionHasNoErrors();
        $this->post(route('retailer.stock.store'), $this->data(['quantity' => '0.001', 'zero_price' => '0.01']))->assertSessionHasErrors('quantity');
    }

    public function test_edit_cannot_exceed_limit_and_does_not_change_item(): void
    {
        $item = $this->item();
        $this->put(route('retailer.stock.update', $item), $this->data(['quantity' => '101']))->assertSessionHasErrors('quantity');
        $this->assertSame('1.000', $item->fresh()->quantity);
    }

    public function test_prices_and_free_exchange_are_enforced_server_side(): void
    {
        $this->post(route('retailer.stock.store'), $this->data(['list_price' => '99.99']))->assertSessionHasErrors('zero_price');
        $this->post(route('retailer.stock.store'), $this->data(['exchange_available' => '1']))->assertSessionHasErrors('exchange_available');
        $this->post(route('retailer.stock.store'), $this->data(['zero_price' => '1e3']))->assertSessionHasErrors('zero_price');
    }

    public function test_pro_has_no_limits_and_allows_exchange(): void
    {
        $this->retailer->activeSubscription->update(['plan_id' => Plan::where('code', 'pro')->sole()->id]);
        for ($i = 0; $i < 5; $i++) {
            $this->item();
        } $this->post(route('retailer.stock.store'), $this->data(['quantity' => '1000', 'exchange_available' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue(InventoryItem::latest('id')->first()->exchange_available);
    }

    public function test_owner_only_and_pending_cannot_write(): void
    {
        $other = InventoryItem::factory()->create();
        $this->get(route('retailer.stock.show', $other))->assertForbidden();
        $this->get(route('retailer.stock.edit', $other))->assertForbidden();
        $this->put(route('retailer.stock.update', $other), $this->data())->assertForbidden();
        $this->patch(route('retailer.stock.archive', $other))->assertForbidden();
        $this->retailer->update(['status' => 'pending']);
        $this->post(route('retailer.stock.store'), $this->data())->assertForbidden();
    }

    public function test_invalid_and_oversized_uploads_are_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'invalid-photo');
        file_put_contents($path, '<script>alert(1)</script>');
        $this->post(route('retailer.stock.store'), $this->data(['photos' => [new UploadedFile($path, 'fake.jpg', 'image/jpeg', null, true)]]))->assertSessionHasErrors('photos.0');
        $this->post(route('retailer.stock.store'), $this->data(['photos' => [UploadedFile::fake()->create('too-big.jpg', 15361, 'image/jpeg')]]))->assertSessionHasErrors('photos.0');
    }

    private function photo(string $format = 'png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'test-image');
        (new Process(['/usr/bin/convert', '-size', '2200x1100', 'xc:red', $format.':'.$path]))->mustRun();

        return new UploadedFile($path, 'untrusted.'.$format, 'image/'.$format, null, true);
    }

    public function test_photo_is_resized_jpeg_and_private_with_ownership(): void
    {
        $this->post(route('retailer.stock.store'), $this->data(['photos' => [$this->photo()]]))->assertSessionHasNoErrors();
        $item = InventoryItem::sole();
        $photo = $item->images()->sole();
        $dimensions = getimagesize(Storage::disk('local')->path($photo->path));
        $this->assertSame(1800, $dimensions[0]);
        $this->assertSame(IMAGETYPE_JPEG, $dimensions[2]);
        $this->assertStringEndsWith('.jpg', $photo->path);
        $this->get(route('inventory.image', [$item, 0]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs(User::factory()->create())->get(route('inventory.image', [$item, 0]))->assertForbidden();
    }

    public function test_jpeg_orientation_is_corrected(): void
    {
        $photo = $this->photo('jpeg');
        $bytes = file_get_contents($photo->getRealPath());
        $exif = "Exif\0\0".'II'.pack('v', 42).pack('V', 8).pack('v', 1).pack('vvVv', 274, 3, 1, 6)."\0\0".pack('V', 0);
        file_put_contents($photo->getRealPath(), substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($bytes, 2));
        $this->post(route('retailer.stock.store'), $this->data(['photos' => [$photo]]))->assertSessionHasNoErrors();
        $image = InventoryItem::sole()->images()->sole();
        $size = getimagesize(Storage::disk('local')->path($image->path));
        $this->assertSame(900, $size[0]);
        $this->assertSame(1800, $size[1]);
    }

    public function test_webp_is_converted_and_failed_quota_cleans_new_files(): void
    {
        $this->post(route('retailer.stock.store'), $this->data(['photos' => [$this->photo('webp')]]))->assertSessionHasNoErrors();
        $this->assertSame(IMAGETYPE_JPEG, getimagesize(Storage::disk('local')->path(InventoryItem::sole()->images()->sole()->path))[2]);
        $before = Storage::disk('local')->allFiles();
        $this->post(route('retailer.stock.store'), $this->data(['quantity' => '1000', 'photos' => [$this->photo()]]))->assertSessionHasErrors('quantity');
        $this->assertSame($before, Storage::disk('local')->allFiles());
    }

    public function test_published_changes_preserve_approved_data_and_photos_until_approval(): void
    {
        $item = $this->item(['status' => 'published', 'published_at' => now(), 'name' => 'Original']);
        $item->images()->create(['disk' => 'local', 'path' => 'inventory/original.jpg', 'position' => 0]);
        $this->put(route('retailer.stock.update', $item), $this->data(['name' => 'Proposto', 'zero_price' => '120.00', 'photos' => [$this->photo()]]))->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('Original', $item->name);
        $this->assertSame(10000, $item->zero_price_cents);
        $this->assertSame('change_pending', $item->status->value);
        $this->assertSame('Proposto', $item->proposed_data['name']);
        $this->assertSame('inventory/original.jpg', $item->images()->sole()->path);
        $this->patch(route('retailer.stock.archive', $item))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('Proposto', $item->name);
        $this->assertSame(12000, $item->zero_price_cents);
        $this->assertNull($item->proposed_data);
        $this->assertNotSame('inventory/original.jpg', $item->images()->sole()->path);
        $this->assertDatabaseHas('audit_logs', ['subject_id' => $item->id, 'action' => 'inventory_approved']);
    }

    public function test_rejected_change_preserves_published_version(): void
    {
        $item = $this->item(['status' => 'published', 'published_at' => now()]);
        $original = $item->name;
        $this->put(route('retailer.stock.update', $item), $this->data())->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->patch(route('admin.stock.review', $item), ['decision' => 'rejected', 'reason' => 'Dati insufficienti'])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame('published', $item->status->value);
        $this->assertSame($original, $item->name);
        $this->assertNull($item->proposed_data);
    }

    public function test_new_rejection_and_duplicate_review(): void
    {
        $item = $this->item(['status' => 'pending']);
        $this->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->patch(route('admin.stock.review', $item), ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->patch(route('admin.stock.review', $item), ['decision' => 'rejected', 'reason' => 'Foto insufficienti'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $item->fresh()->status->value);
        $this->patch(route('admin.stock.review', $item), ['decision' => 'published'])->assertSessionHasErrors('decision');
    }

    public function test_published_value_remains_reserved_while_lower_proposal_waits(): void
    {
        $item = $this->item(['status' => 'published', 'published_at' => now(), 'zero_price_cents' => 1000000]);
        $this->put(route('retailer.stock.update', $item), $this->data(['zero_price' => '1']))->assertSessionHasNoErrors();
        $this->post(route('retailer.stock.store'), $this->data())->assertSessionHasErrors('quantity');
    }

    public function test_duplicate_sku_and_archived_edits_are_rejected(): void
    {
        $item = $this->item(['sku' => 'ABC']);
        $this->post(route('retailer.stock.store'), $this->data(['sku' => 'ABC']))->assertSessionHasErrors('sku');
        $this->patch(route('retailer.stock.archive', $item))->assertRedirect();
        $this->put(route('retailer.stock.update', $item), $this->data())->assertForbidden();
    }
}
