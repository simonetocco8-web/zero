<?php

namespace Tests\Feature;

use App\Actions\ManageInventoryPhotos;
use App\Models\AvailabilityRequest;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Plan;
use App\Models\Retailer;
use App\Models\StockReservation;
use App\Models\Subscription;
use App\Models\User;
use App\Support\DisplayQuantity;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\AdditiveRefreshDatabase;
use Tests\TestCase;

class InventoryManagementAccessTest extends TestCase
{
    use AdditiveRefreshDatabase;

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'replacement-photo');
        (new Process(['/usr/bin/convert', '-size', '10x10', 'xc:red', 'png:'.$path]))->mustRun();

        return new UploadedFile($path, 'replacement.png', 'image/png', null, true);
    }

    private function item(string $status = 'published'): InventoryItem
    {
        return InventoryItem::factory()->published()->create(['retailer_id' => Retailer::factory()->approved(), 'status' => $status]);
    }

    public function test_guest_sees_only_entry_choices_and_cannot_open_catalog_or_photos_or_enquiries(): void
    {
        $i = $this->item();
        $this->get('/')->assertOk()->assertSee('Accedi')->assertSee('Registrati')->assertDontSee($i->name);
        foreach ([route('marketplace.index'), route('marketplace.show', $i), route('marketplace.image', [$i, 0]), route('availability.create', $i)] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_only_validated_retailers_customers_and_admin_can_read_marketplace(): void
    {
        foreach (['pending', 'rejected', 'suspended'] as $status) {
            $r = Retailer::factory()->create(['status' => $status, 'rejected_at' => $status === 'rejected' ? now() : null]);
            $this->actingAs($r->user)->get(route('marketplace.index'))->assertForbidden();
            $this->get(route('checkout.index'))->assertForbidden();
            $this->get(route('cart.index'))->assertForbidden();
        }
        foreach (['customer', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('marketplace.index'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->actingAs(Retailer::factory()->approved()->create()->user)->get(route('marketplace.index'))->assertOk();
    }

    public function test_owner_and_admin_can_delete_uploaded_items_without_deleting_history(): void
    {
        foreach (['draft', 'pending', 'published', 'change_pending', 'rejected', 'archived'] as $status) {
            $i = $this->item($status);
            $this->actingAs($i->retailer->user)->delete(route('inventory.destroy', $i))->assertRedirect(route('retailer.stock'));
            $this->assertSoftDeleted('inventory_items', ['id' => $i->id, 'deleted_by' => $i->retailer->user_id]);
            $this->assertNull(InventoryItem::find($i->id));
            $this->assertNotNull(InventoryItem::withTrashed()->find($i->id));
        }
        $i = $this->item();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->delete(route('inventory.destroy', $i))->assertRedirect(route('admin.stock'));
        $this->assertSoftDeleted('inventory_items', ['id' => $i->id, 'deleted_by' => $admin->id]);
    }

    public function test_other_retailer_cannot_delete_or_modify_photos_and_active_hold_blocks_deletion(): void
    {
        $i = $this->item();
        $this->actingAs(Retailer::factory()->approved()->create()->user)->delete(route('inventory.destroy', $i))->assertForbidden();
        $this->get(route('inventory.photos.edit', $i))->assertForbidden();
        StockReservation::create(['inventory_item_id' => $i->id, 'quantity_milliunits' => 1000, 'expires_at' => now()->addMinutes(10), 'idempotency_key' => 'delete-protection']);
        $this->actingAs($i->retailer->user)->delete(route('inventory.destroy', $i))->assertSessionHasErrors('item');
        $this->assertFalse($i->fresh()->trashed());
        $owner = $i->retailer->user;
        $owner->setRelation('retailer', $i->retailer);
        Retailer::whereKey($i->retailer_id)->update(['status' => 'suspended']);
        $this->actingAs($owner)->delete(route('inventory.destroy', $i))->assertForbidden();
        $this->put(route('inventory.photos.update', $i), ['version' => app(ManageInventoryPhotos::class)->version($i)])->assertForbidden();

    }

    public function test_remove_all_and_replace_specific_photo_preserves_approved_version_until_review(): void
    {
        Storage::fake('local');
        $i = $this->item();
        $path = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'original');
        $i->images()->create(['disk' => 'local', 'path' => $path, 'position' => 0]);
        $photos = app(ManageInventoryPhotos::class);
        $this->actingAs($i->retailer->user)->get(route('inventory.photos.edit', $i))->assertOk()->assertSee('Sostituisci foto 1');
        $version = $photos->version($i);
        $this->put(route('inventory.photos.update', $i), ['version' => $version, 'replacements' => [0 => $this->photo()]])->assertSessionHasNoErrors();
        $i->refresh();
        $this->assertSame($path, $i->images()->sole()->path);
        $this->assertNotSame($path, $i->proposed_images[0]['path']);
        $this->assertSame('change_pending', $i->status->value);
        $this->put(route('inventory.photos.update', $i), ['version' => $version, 'remove' => [0]])->assertSessionHasErrors('photos');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->put(route('inventory.photos.update', $i), ['version' => $photos->version($i), 'remove' => [0]])->assertSessionHasNoErrors();
        $this->assertSame([], $i->fresh()->proposed_images);
        Storage::disk('local')->assertExists($path);
    }

    public function test_draft_photos_can_be_removed_immediately_and_invalid_positions_are_rejected(): void
    {
        $i = $this->item('draft');
        $i->images()->create(['disk' => 'local', 'path' => 'inventory/'.Str::uuid().'.jpg', 'position' => 0]);
        $photos = app(ManageInventoryPhotos::class);
        $this->actingAs($i->retailer->user)->put(route('inventory.photos.update', $i), ['version' => $photos->version($i), 'remove' => [99]])->assertSessionHasErrors('photos');
        $this->put(route('inventory.photos.update', $i), ['version' => $photos->version($i), 'remove' => [0]])->assertSessionHasNoErrors();
        $this->assertSame(0, $i->images()->count());
    }

    public function test_enquiry_history_remains_readable_after_article_deletion(): void
    {
        $i = $this->item();
        AvailabilityRequest::factory()->create(['inventory_item_id' => $i->id, 'retailer_id' => $i->retailer_id, 'quantity' => '1']);
        $this->actingAs($i->retailer->user)->delete(route('inventory.destroy', $i))->assertRedirect();
        $this->get(route('retailer.requests'))->assertOk()->assertSee($i->name)->assertDontSee('1.000');
    }

    public function test_deleted_articles_disappear_from_operational_lists_and_marketplace(): void
    {
        $i = $this->item();
        $this->actingAs($i->retailer->user)->get(route('retailer.stock'))
            ->assertSee('Elimina articolo')->assertSee('Ordini, scambi e storico saranno conservati.');
        $this->delete(route('inventory.destroy', $i))->assertRedirect();
        $this->get(route('retailer.stock'))->assertOk()->assertDontSee($i->name);
        $this->get(route('marketplace.index'))->assertOk()->assertDontSee($i->name);
        $this->get(route('marketplace.show', $i))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.stock'))->assertOk()->assertDontSee($i->name);
    }

    public function test_other_retailer_cannot_submit_photo_removal_or_replacement(): void
    {
        Storage::fake('local');
        $i = $this->item('draft');
        $path = 'inventory/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'private photo');
        $i->images()->create(['disk' => 'local', 'path' => $path, 'position' => 0]);
        $version = app(ManageInventoryPhotos::class)->version($i);
        $this->actingAs(Retailer::factory()->approved()->create()->user);
        $this->put(route('inventory.photos.update', $i), ['version' => $version, 'remove' => [0]])->assertForbidden();
        $this->put(route('inventory.photos.update', $i), ['version' => $version, 'replacements' => [0 => $this->photo()]])->assertForbidden();
        $this->assertSame($path, $i->images()->sole()->path);
        Storage::disk('local')->assertExists($path);
        $this->assertCount(1, Storage::disk('local')->allFiles('inventory'));
    }

    public function test_admin_can_replace_a_draft_photo_and_owner_can_remove_cover_or_all_photos(): void
    {
        Storage::fake('local');
        $i = $this->item('draft');
        $refs = [];
        foreach ([0, 1] as $position) {
            $path = 'inventory/'.Str::uuid().'.jpg';
            Storage::disk('local')->put($path, 'private photo '.$position);
            $refs[] = $path;
            $i->images()->create(['disk' => 'local', 'path' => $path, 'position' => $position]);
        }
        $photos = app(ManageInventoryPhotos::class);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('inventory.photos.update', $i), ['version' => $photos->version($i), 'replacements' => [1 => $this->photo()]])
            ->assertSessionHasNoErrors();
        $newPath = $i->images()->where('position', 1)->sole()->path;
        $this->assertNotSame($refs[1], $newPath);
        Storage::disk('local')->assertExists($newPath);
        $this->actingAs($i->retailer->user)->get(route('inventory.photos.edit', $i))->assertOk()->assertSee('Copertina');
        $this->put(route('inventory.photos.update', $i), ['version' => $photos->version($i), 'remove' => [0]])->assertSessionHasNoErrors();
        $cover = $i->images()->sole();
        $this->assertSame(0, $cover->position);
        $this->assertSame($newPath, $cover->path);
        $this->put(route('inventory.photos.update', $i), ['version' => $photos->version($i), 'remove' => [0]])->assertSessionHasNoErrors();
        $this->assertSame(0, $i->images()->count());
        $this->get(route('inventory.photos.edit', $i))->assertOk()->assertSee('Nessuna foto caricata');
        // Private superseded files are deliberately retained by the existing history contract.
        foreach ([...$refs, $newPath] as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_removing_published_cover_keeps_marketplace_photo_until_admin_review(): void
    {
        Storage::fake('local');
        $this->seed(PlanSeeder::class);
        $i = $this->item();
        Subscription::factory()->active()->create(['retailer_id' => $i->retailer_id, 'plan_id' => Plan::where('code', 'free')->sole()->id]);
        $refs = [];
        foreach ([0, 1] as $position) {
            $path = 'inventory/'.Str::uuid().'.jpg';
            Storage::disk('local')->put($path, 'published photo '.$position);
            $refs[] = $path;
            $i->images()->create(['disk' => 'local', 'path' => $path, 'position' => $position]);
        }
        $this->actingAs($i->retailer->user)->put(route('inventory.photos.update', $i), [
            'version' => app(ManageInventoryPhotos::class)->version($i), 'remove' => [0],
        ])->assertSessionHasNoErrors();
        $this->get(route('marketplace.image', [$i, 0]))->assertOk()->assertStreamedContent('published photo 0');
        $this->assertSame($refs[0], $i->images()->where('position', 0)->sole()->path);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch(route('admin.stock.review', $i), ['decision' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame($refs[1], $i->images()->sole()->path);
        $this->assertSame(0, $i->images()->sole()->position);
        $this->get(route('marketplace.image', [$i, 0]))->assertOk()->assertStreamedContent('published photo 1');
        $this->get(route('marketplace.image', [$i, 1]))->assertNotFound();
        Storage::disk('local')->assertExists($refs[0]);
    }

    public function test_deleted_articles_keep_their_category_reference_protected(): void
    {
        $category = Category::create(['name' => 'Categoria storica', 'slug' => 'categoria-storica']);
        $i = $this->item();
        $i->update(['category_id' => $category->id]);
        $this->actingAs($i->retailer->user)->delete(route('inventory.destroy', $i))->assertRedirect();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->delete(route('admin.categories.destroy', $category))->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_quantities_use_exact_italian_decimals_without_trailing_zeroes(): void
    {
        $this->assertSame('1', DisplayQuantity::format(1000));
        $this->assertSame('1,25', DisplayQuantity::format(1250));
        $this->assertSame('0,001', DisplayQuantity::format(1));
        $this->assertSame('1000', DisplayQuantity::format(1000000));
        $i = $this->item();
        $i->update(['quantity' => '1']);
        $this->actingAs($i->retailer->user)->get(route('retailer.stock.show', $i))->assertSee('Quantità disponibile')->assertSee('1 '.$i->unit)->assertDontSee('1.000 '.$i->unit);
        $this->get(route('retailer.dashboard'))->assertSee('Vai al marketplace');
    }
}
