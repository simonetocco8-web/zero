<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\VendorOrder;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LifecycleUpgradeTest extends TestCase
{
    public function test_existing_multi_vendor_refunds_are_backfilled_without_inventing_stock_and_constraints_survive(): void
    {
        $original = config('database.default');
        config(['database.connections.lifecycle_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true], 'database.default' => 'lifecycle_upgrade']);
        try {
            $latest = '2026_10_16_000001_add_operational_lifecycle.php';
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) !== $latest));
            $this->artisan('migrate', ['--database' => 'lifecycle_upgrade', '--path' => $paths, '--realpath' => true, '--force' => true])->assertSuccessful();
            $sale = Sale::factory()->create(['status' => 'partially_refunded', 'paid_at' => now(), 'total_cents' => 5000]);
            $a = SaleItem::factory()->create(['sale_id' => $sale->id]);
            $b = SaleItem::factory()->create(['sale_id' => $sale->id]);
            WalletTransaction::factory()->create(['sale_item_id' => $a->id, 'retailer_id' => $a->retailer_id, 'type' => 'refund', 'amount_cents' => -2500]);
            $this->artisan('migrate', ['--database' => 'lifecycle_upgrade', '--force' => true])->assertSuccessful();
            $this->assertSame('refunded', VendorOrder::where('retailer_id', $a->retailer_id)->sole()->status);
            $this->assertSame('paid', VendorOrder::where('retailer_id', $b->retailer_id)->sole()->status);
            $this->assertSame(0, $a->fresh()->stock_deducted_milliunits);
            $this->assertSame('external', $a->fresh()->vendorOrder->stock_rule);
            try {
                DB::table('sale_items')->where('id', $a->id)->update(['net_cents' => 1]);
                $this->fail('Financial constraint was lost during migration');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
            try {
                DB::table('sale_items')->where('id', $a->id)->update(['vendor_order_id' => $b->fresh()->vendor_order_id]);
                $this->fail('Cross-retailer vendor assignment accepted');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        } finally {
            DB::purge('lifecycle_upgrade');
            config(['database.default' => $original]);
        }
    }
}
