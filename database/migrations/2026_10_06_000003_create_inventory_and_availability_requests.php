<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category', 120);
            $this->identifier($table, 'sku', true);
            $this->identifier($table, 'ean', true);
            $table->bigInteger('quantity_milliunits');
            $table->string('unit', 32)->default('piece');
            $this->currency($table);
            $table->bigInteger('list_price_cents')->nullable();
            $table->bigInteger('zero_price_cents');
            $table->enum('condition', ['new', 'end_of_line', 'old_stock', 'damaged_packaging'])->default('new');
            $table->string('province', 80);
            $table->boolean('pickup_available')->default(false);
            $table->boolean('shipping_available')->default(false);
            $table->boolean('exchange_available')->default(false);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'pending', 'published', 'change_pending', 'rejected', 'archived'])->default('draft');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('published_at')->nullable();
            $this->identifier($table, 'external_provider', true);
            $this->identifier($table, 'external_product_id', true);
            $table->timestamps();
            $table->unique(['retailer_id', 'sku']);
            $table->unique(['external_provider', 'external_product_id']);
            $table->unique(['id', 'retailer_id']);
            $table->index(['retailer_id', 'status']);
            $table->index(['status', 'province', 'category']);
        });
        Schema::create('inventory_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path', 512);
            $table->string('alt_text')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->unique(['inventory_item_id', 'position']);
        });
        Schema::create('availability_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_item_id');
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 40)->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['new', 'contacted', 'closed'])->default('new');
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->foreign(['inventory_item_id', 'retailer_id'], 'availability_inventory_owner_fk')
                ->references(['id', 'retailer_id'])->on('inventory_items')->restrictOnDelete();
            $table->index(['retailer_id', 'status', 'created_at']);
        });

    }

    public function down(): void
    {

        Schema::dropIfExists('availability_requests');
        Schema::dropIfExists('inventory_images');
        Schema::dropIfExists('inventory_items');

    }

    private function currency(Blueprint $table): void
    {
        $column = $table->char('currency', 3)->default('EUR');
        if (DB::getDriverName() === 'mysql') {
            $column->collation('utf8mb4_bin');
        }
    }

    private function identifier(Blueprint $table, string $name, bool $nullable = false): void
    {
        $column = $table->string($name, 191)->nullable($nullable);
        if (DB::getDriverName() === 'mysql') {
            $column->collation('utf8mb4_bin');
        }
    }
};
