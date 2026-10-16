<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('cart_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->bigInteger('quantity_milliunits');
            $t->string('delivery_mode', 16);
            $t->timestamps();
            $t->unique(['cart_id', 'inventory_item_id']);
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('checkout_key')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $this->totals($t);
            $t->json('customer_snapshot');
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('vendor_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $this->totals($t);
            $t->string('delivery_mode', 16);
            $t->json('retailer_snapshot');
            $t->json('address_snapshot')->nullable();
            $t->json('tax_snapshot');
            $t->timestamps();
            $t->unique(['order_id', 'retailer_id']);
            $t->index(['retailer_id', 'status']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_item_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('sku', 191)->nullable();
            $t->string('unit', 32);
            $t->bigInteger('unit_price_cents');
            $t->bigInteger('quantity_milliunits');
            $t->bigInteger('subtotal_cents');
            $t->bigInteger('shipping_cost_cents');
            $t->bigInteger('tax_cents')->nullable();
            $t->bigInteger('taxable_cents')->nullable();
            $t->bigInteger('total_cents');
            $t->integer('commission_basis_points');
            $t->bigInteger('commission_cents')->nullable();
            $t->json('tax_snapshot');
            $t->json('product_snapshot');
            $t->timestamps();
            $t->unique(['vendor_order_id', 'inventory_item_id']);
        });
        Schema::create('marketplace_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $t->string('provider', 32);
            $t->string('reference', 191)->nullable();
            $t->string('status', 32)->default('payment_pending');
            $t->bigInteger('amount_cents');
            $t->char('currency', 3)->default('EUR');
            $t->boolean('simulated')->default(true);
            $t->timestamps();
            $t->unique(['provider', 'reference']);
        });
    }

    private function totals(Blueprint $t): void
    {
        $t->enum('status', ['payment_pending', 'paid', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled', 'refunded'])->default('payment_pending');
        $t->char('currency', 3)->default('EUR');
        $t->bigInteger('subtotal_cents');
        $t->bigInteger('shipping_cents');
        $t->bigInteger('tax_cents')->nullable();
        $t->bigInteger('taxable_cents')->nullable();
        $t->bigInteger('total_cents');
        $t->boolean('totals_provisional')->default(true);
    }

    public function down(): void
    {
        throw new RuntimeException('Marketplace orders are retained; roll back code without removing customer data.');
    }
};
