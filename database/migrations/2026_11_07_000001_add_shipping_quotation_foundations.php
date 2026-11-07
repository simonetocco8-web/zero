<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            foreach (['length_mm', 'width_mm', 'height_mm', 'weight_g', 'units_per_parcel_milliunits'] as $field) {
                $table->unsignedInteger($field)->nullable();
            }
            $table->string('packaging_type', 40)->nullable();
            $table->string('packing_rule', 32)->nullable();
        });
        Schema::table('retailers', function (Blueprint $table) {
            $table->string('postal_code', 32)->nullable();
            $table->string('country_code', 2)->nullable();
        });
        Schema::create('shipping_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('context', 64);
            $table->char('fingerprint', 64)->index();
            $table->text('request_snapshot');
            $table->json('rate');
            $table->string('status', 16)->default('valid');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::table('cart_items', function (Blueprint $table) {
            $table->uuid('shipping_quote_id')->nullable()->index();
            $table->string('shipping_pricing_mode', 16)->default('legacy');
        });
    }

    public function down(): void
    {
        // Additive release: rollback is performed by disabling the feature, preserving history.
    }
};
