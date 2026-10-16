<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private array $categoryIds = [];

    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 191)->unique();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        foreach (['Edilizia', 'Ferramenta', 'Idraulica', 'Colore'] as $i => $name) {
            $this->category($name, $i);
        }
        // SQLite supports an additive nullable REFERENCES column directly; avoid a
        // table rebuild which would remove the existing validation triggers.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE inventory_items ADD COLUMN category_id INTEGER REFERENCES categories(id) ON DELETE RESTRICT');
        }
        Schema::table('inventory_items', function (Blueprint $t) {
            if (DB::getDriverName() !== 'sqlite') {
                $t->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            }
            $t->bigInteger('shipping_cost_cents')->nullable();
            $t->text('delivery_notes')->nullable();
            $t->string('vat_treatment', 64)->nullable();
        });
        DB::table('inventory_items')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $update = ['category_id' => $this->category($row->category)];
                if ($row->proposed_data !== null) {
                    $proposal = json_decode($row->proposed_data, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($proposal['category'])) {
                        $proposal['category_id'] = $this->category($proposal['category']);
                        $update['proposed_data'] = json_encode($proposal, JSON_THROW_ON_ERROR);
                    }
                }
                DB::table('inventory_items')->where('id', $row->id)->update($update);
            }
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('phone', 40)->nullable();
        });
        Schema::create('customer_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('label');
            $t->string('recipient_name');
            $t->string('company')->nullable();
            $t->string('address_line_1');
            $t->string('address_line_2')->nullable();
            $t->string('postal_code', 32);
            $t->string('city');
            $t->string('province', 80);
            $t->char('country_code', 2);
            $t->string('phone', 40)->nullable();
            $t->boolean('is_default_shipping')->default(false);
            $t->boolean('is_default_billing')->default(false);
            $t->unsignedBigInteger('default_shipping_user_id')->nullable()->storedAs('CASE WHEN is_default_shipping = 1 THEN user_id ELSE NULL END')->unique();
            $t->unsignedBigInteger('default_billing_user_id')->nullable()->storedAs('CASE WHEN is_default_billing = 1 THEN user_id ELSE NULL END')->unique();
            $t->timestamps();
            $t->index('user_id');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE inventory_items ADD CONSTRAINT inventory_shipping_cost_nonnegative CHECK (shipping_cost_cents IS NULL OR shipping_cost_cents >= 0)');
        } else {
            foreach (['INSERT', 'UPDATE'] as $op) {
                DB::unprepared("CREATE TRIGGER inventory_shipping_cost_$op BEFORE $op ON inventory_items WHEN NEW.shipping_cost_cents IS NOT NULL AND (NEW.shipping_cost_cents < 0 OR typeof(NEW.shipping_cost_cents) <> 'integer') BEGIN SELECT RAISE(ABORT, 'Invalid shipping cost'); END");
            }
        }
    }

    private function category(string $name, int $sort = 0): int
    {
        if (array_key_exists($name, $this->categoryIds)) {
            return $this->categoryIds[$name];
        }
        // Match exact original strings, not a MySQL case-insensitive name comparison.
        foreach (DB::table('categories')->get(['id', 'name']) as $category) {
            if ($category->name === $name) {
                return $this->categoryIds[$name] = $category->id;
            }
        }
        $base = Str::slug($name) ?: 'categoria';
        $base = substr($base, 0, 160);
        $slug = $base;
        $suffix = 1;
        while (DB::table('categories')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$suffix;
        }

        return $this->categoryIds[$name] = DB::table('categories')->insertGetId(['name' => $name, 'slug' => $slug, 'sort_order' => $sort, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        throw new RuntimeException('Non-destructive migration: retain marketplace data; roll back application code, not this schema.');
    }
};
