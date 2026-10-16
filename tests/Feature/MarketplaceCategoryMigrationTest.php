<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceCategoryMigrationTest extends TestCase
{
    public function test_additive_upgrade_preserves_original_categories_proposals_money_and_triggers(): void
    {
        $driver = DB::getDriverName();
        $config = config('database.connections.'.$driver);
        if ($driver === 'mysql') {
            $db = 'zero_upgrade_'.bin2hex(random_bytes(8));
            $pdo = new \PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']);
            $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $config['database'] = $db;
        } else {
            $config['database'] = ':memory:';
        }
        config(['database.connections.upgrade' => $config]);
        DB::setDefaultConnection('upgrade');
        // Reconstruct the old schema on a NEW isolated connection, never down/reset it.
        $paths = glob(database_path('migrations/*.php'));
        sort($paths);
        foreach ($paths as $path) {
            if (basename($path) < '2026_10_13_000001_add_marketplace_foundations.php') {
                (require $path)->up();
            }
        }
        $u = DB::table('users')->insertGetId(['name' => 'Storico', 'email' => 'legacy@example.com', 'password' => 'hash', 'role' => 'retailer']);
        $r = DB::table('retailers')->insertGetId(['user_id' => $u, 'company_name' => 'Azienda', 'vat_number' => '12345678901', 'city' => 'Roma', 'status' => 'approved', 'approved_at' => now()]);
        $ids = [];
        foreach (['Edilizia', 'Électricité', 'Electricite', ' Edilizia ', ''] as $name) {
            $ids[] = DB::table('inventory_items')->insertGetId(['retailer_id' => $r, 'name' => 'Storico', 'category' => $name, 'quantity_milliunits' => 1250, 'zero_price_cents' => 12345, 'province' => 'Roma', 'proposed_data' => json_encode(['category' => 'Categoria proposta', 'name' => 'Nuovo nome', 'quantity_milliunits' => 2000, 'zero_price_cents' => 9876, 'description' => 'Da preservare'])]);
        }
        $sale = DB::table('sales')->insertGetId(['provider' => 'fake', 'external_order_id' => 'legacy', 'status' => 'paid', 'total_cents' => 100, 'currency' => 'EUR']);
        $line = DB::table('sale_items')->insertGetId(['sale_id' => $sale, 'inventory_item_id' => $ids[0], 'retailer_id' => $r, 'external_line_id' => 'line', 'name' => 'Storico', 'quantity_milliunits' => 1000, 'unit_price_cents' => 100, 'line_total_cents' => 100, 'commission_basis_points' => 200, 'commission_cents' => 2, 'net_cents' => 98]);
        DB::table('wallet_transactions')->insert(['retailer_id' => $r, 'sale_item_id' => $line, 'idempotency_key' => 'legacy-credit', 'type' => 'sale_credit', 'amount_cents' => 100, 'available_at' => now()]);
        $before = DB::table('inventory_items')->orderBy('id')->get();
        $wallet = DB::table('wallet_transactions')->get()->toJson();
        $migration = require database_path('migrations/2026_10_13_000001_add_marketplace_foundations.php');
        $migration->up();
        foreach ($before as $old) {
            $new = DB::table('inventory_items')->find($old->id);
            $this->assertSame($old->category, $new->category);
            $this->assertSame($old->category, DB::table('categories')->find($new->category_id)->name);
            $this->assertSame($old->quantity_milliunits, $new->quantity_milliunits);
            $this->assertSame($old->zero_price_cents, $new->zero_price_cents);
            $proposal = json_decode($new->proposed_data, true);
            $this->assertSame('Categoria proposta', DB::table('categories')->find($proposal['category_id'])->name);
            unset($proposal['category_id']);
            $this->assertSame(json_decode($old->proposed_data, true), $proposal);
            $this->assertNull($new->shipping_cost_cents);
            $this->assertNull($new->vat_treatment);
        }
        $this->assertSame($wallet, DB::table('wallet_transactions')->get()->toJson());
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame('retailer', DB::table('users')->find($u)->role);
        $this->assertSame(1, DB::table('categories')->where('slug', 'electricite')->count());
        $this->assertSame(1, DB::table('categories')->where('slug', 'electricite-2')->count());
        foreach (['Edilizia', 'Ferramenta', 'Idraulica', 'Colore'] as $name) {
            $this->assertTrue(DB::table('categories')->where('name', $name)->exists());
        }
        $this->expectException(QueryException::class);
        DB::table('inventory_items')->where('id', $ids[0])->update(['quantity_milliunits' => -1]);
    }
}
