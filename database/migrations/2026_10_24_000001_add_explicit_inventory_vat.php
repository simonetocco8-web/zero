<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // No backfill or default: legacy products remain fiscally undetermined.
            $table->unsignedSmallInteger('vat_rate_basis_points')->nullable();
            $table->string('shipping_vat_treatment', 32)->nullable();
            $table->unsignedSmallInteger('shipping_vat_rate_basis_points')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Fiscal configuration is retained; roll back code without removing data.');
    }
};
